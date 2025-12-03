<?php
namespace SweetDelights\Mayie\Controllers\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ReportsAdminController extends BaseAdminController
{
    // --- All data helpers and __construct are now in BaseAdminController ---

    /**
     * This is the helper function that does the actual work.
     * We'll use this for both the web view and the CSV export.
     */
    private function generateReportData(string $dateStart, string $dateEnd): array
    {
        $allOrders = $this->getOrders(); // Inherited
        
        // Change the array_filter function in ReportsAdminController.php:
        $filteredOrders = array_filter($allOrders, function($order) use ($dateStart, $dateEnd) {
            // Convert $orderDate to a timestamp for easier comparison
            $orderTimestamp = strtotime($order['date']);
            
            // Convert date strings to timestamps for comparison
            // Note: strtotime('YYYY-MM-DD') returns 00:00:00 for that day
            $startOfDay = strtotime($dateStart);
            // Add one day to $dateEnd to cover all hours up to the start of the next day
            $endOfPeriod = strtotime($dateEnd . ' +1 day');

            return in_array($order['status'], ['Shipped', 'Delivered']) &&
                $orderTimestamp >= $startOfDay &&
                $orderTimestamp < $endOfPeriod; // Use < here
        });

        $totalSales = 0;
        $totalOrders = count($filteredOrders);
        $itemsSold = [];

        // --- THIS BLOCK IS NOW FIXED ---
        foreach ($filteredOrders as $order) {
            $totalSales += $order['total'];
            
            // Check if items exist and is an array before looping
            if (isset($order['items']) && is_array($order['items'])) {
                foreach ($order['items'] as $item) {
                    // --- FIX: Use 'product_sku' which comes from the DB ---
                    $sku = $item['product_sku'] ?? null; 
                    if ($sku) { // Only process if SKU exists
                        $qty = $item['quantity'];
                        if (!isset($itemsSold[$sku])) {
                            $itemsSold[$sku] = 0;
                        }
                        $itemsSold[$sku] += $qty;
                    }
                }
            }
        }
        // --- END FIX ---

        // Build maps using SKU as the key
        $allProducts = $this->getProducts(); // Inherited
        $productNameMap = array_column($allProducts, 'name', 'sku');
        $productIdMap = array_column($allProducts, 'id', 'sku');
        
        $bestSellers = [];
        // This loop was already correct
        foreach ($itemsSold as $sku => $quantity) {
            $bestSellers[] = [
                'id' => $productIdMap[$sku] ?? null, // Get the ID from the sku
                'name' => $productNameMap[$sku] ?? 'Unknown Product', // Get the name from the sku
                'quantity' => $quantity
            ];
        }
        
        // --- SYNTAX FIX ---
        // Replaced arrow function with traditional function for wider compatibility.
        usort($bestSellers, function($a, $b) {
            return $b['quantity'] <=> $a['quantity'];
        });
        // --- END FIX ---

        return [
            'stats' => [
                'total_sales' => $totalSales,
                'total_orders' => $totalOrders,
                'total_items_sold' => array_sum($itemsSold),
            ],
            'best_sellers' => array_slice($bestSellers, 0, 10)
        ];
    }

    /**
     * Shows the HTML report page.
     */
    public function index(Request $request, Response $response): Response
    {
        $view = $this->viewFromRequest($request);
        $params = $request->getQueryParams();

        $defaultStart = date('Y-m-d', strtotime('-30 days'));
        $defaultEnd = date('Y-m-d');

        $dateStart = $params['date_start'] ?? $defaultStart;
        $dateEnd = $params['date_end'] ?? $defaultEnd;

        $reportData = $this->generateReportData($dateStart, $dateEnd);

        return $view->render($response, 'Admin/reports.twig', [
            'title' => 'Sales Reports',
            'breadcrumbs' => $this->breadcrumbs($request, [
                ['name' => 'Reports', 'url' => null]
            ]),
            'active_page' => 'reports',
            'app_url' => $_ENV['APP_URL'] ?? '',
            'date_start' => $dateStart,
            'date_end' => $dateEnd,
            'stats' => $reportData['stats'],
            'best_sellers' => $reportData['best_sellers']
        ]);
    }

    /**
     * Generates and downloads a CSV report.
     */
    public function export(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();

        // --- 1. GET ACTOR ---
        $user = $request->getAttribute('user');
        $actorId = $user ? (int)$user['id'] : null;

        $defaultStart = date('Y-m-d', strtotime('-30 days'));
        $defaultEnd = date('Y-m-d');
        $dateStart = $params['date_start'] ?? $defaultStart;
        $dateEnd = $params['date_end'] ?? $defaultEnd;

        $reportData = $this->generateReportData($dateStart, $dateEnd);

        // --- 2. LOG THE EXPORT ACTION ---
        $this->logAction(
            $actorId,
            'export', // actionType
            'report', // targetType
            null,     // targetId
            [ 
                'report_type' => 'sales',
                'date_start' => $dateStart,
                'date_end' => $dateEnd,
                'summary' => $reportData['stats'] // Log summary data
            ]
        );
        // --- END LOG ---

        // --- Build the CSV String (FIXED) ---
        
        // 3. Open a temporary memory stream to write the CSV
        $stream = fopen('php://memory', 'w');

        // 4. Add headers
        fputcsv($stream, ["Sales Report ($dateStart to $dateEnd)"]);
        fputcsv($stream, []); // Blank line

        // 5. Add Summary Data
        fputcsv($stream, ["Metric", "Value"]);
        // REMOVED currency symbols and number formatting for a clean CSV
        fputcsv($stream, ["Total Sales", $reportData['stats']['total_sales']]);
        fputcsv($stream, ["Total Orders", $reportData['stats']['total_orders']]);
        fputcsv($stream, ["Total Items Sold", $reportData['stats']['total_items_sold']]);
        
        fputcsv($stream, []); // Blank line

        // 6. Add Best Sellers Data
        fputcsv($stream, ["Top 10 Best Sellers"]);
        fputcsv($stream, ["Rank", "Product Name", "Quantity Sold"]);
        foreach ($reportData['best_sellers'] as $index => $item) {
            fputcsv($stream, [
                $index + 1,
                $item['name'], // fputcsv handles escaping quotes automatically
                $item['quantity']
            ]);
        }
        // --- END OF CSV BUILDING ---

        // 7. Rewind the stream and get its contents
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        // 8. Set Headers to Force Download
        $filename = "sales-report-{$dateStart}-to-{$dateEnd}.csv";
        $response->getBody()->write($csv);
        
        return $response
            ->withHeader('Content-Type', 'text/csv')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withStatus(200);
    }
}