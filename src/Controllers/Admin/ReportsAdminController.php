<?php
namespace SweetDelights\Mayie\Controllers\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ReportsAdminController extends BaseAdminController
{
    private $ordersPath;
    private $productsPath;

    public function __construct()
    {
        $this->ordersPath = __DIR__ . '/../../Data/orders.php';
        $this->productsPath = __DIR__ . '/../../Data/products.php';
    }

    private function getOrders(): array { return file_exists($this->ordersPath) ? require $this->ordersPath : []; }
    private function getProducts(): array { return file_exists($this->productsPath) ? require $this->productsPath : []; }


    /**
     * This is the helper function that does the actual work.
     * We'll use this for both the web view and the CSV export.
     */
    private function generateReportData(string $dateStart, string $dateEnd): array
    {
        $allOrders = $this->getOrders();
        
        $filteredOrders = array_filter($allOrders, function($order) use ($dateStart, $dateEnd) {
            $orderDate = date('Y-m-d', strtotime($order['date']));
            return in_array($order['status'], ['Shipped', 'Delivered']) &&
                   $orderDate >= $dateStart &&
                   $orderDate <= $dateEnd;
        });

        $totalSales = 0;
        $totalOrders = count($filteredOrders);
        $itemsSold = [];

        foreach ($filteredOrders as $order) {
            $totalSales += $order['total'];
            foreach ($order['items'] as $item) {
                $id = $item['id'];
                $qty = $item['quantity'];
                if (!isset($itemsSold[$id])) {
                    $itemsSold[$id] = 0;
                }
                $itemsSold[$id] += $qty;
            }
        }

        $productMap = array_column($this->getProducts(), 'name', 'id');
        $bestSellers = [];
        foreach ($itemsSold as $id => $quantity) {
            $bestSellers[] = [
                'id' => $id,
                'name' => $productMap[$id] ?? 'Unknown Product',
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

        $defaultStart = date('Y-m-d', strtotime('-30 days'));
        $defaultEnd = date('Y-m-d');
        $dateStart = $params['date_start'] ?? $defaultStart;
        $dateEnd = $params['date_end'] ?? $defaultEnd;

        $reportData = $this->generateReportData($dateStart, $dateEnd);

        // --- Build the CSV String (FIXED) ---
        
        // 1. Open a temporary memory stream to write the CSV
        $stream = fopen('php://memory', 'w');

        // 2. Add headers
        fputcsv($stream, ["Sales Report ($dateStart to $dateEnd)"]);
        fputcsv($stream, []); // Blank line

        // 3. Add Summary Data
        fputcsv($stream, ["Metric", "Value"]);
        // REMOVED currency symbols and number formatting for a clean CSV
        fputcsv($stream, ["Total Sales", $reportData['stats']['total_sales']]);
        fputcsv($stream, ["Total Orders", $reportData['stats']['total_orders']]);
        fputcsv($stream, ["Total Items Sold", $reportData['stats']['total_items_sold']]);
        
        fputcsv($stream, []); // Blank line

        // 4. Add Best Sellers Data
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

        // 5. Rewind the stream and get its contents
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        // 6. Set Headers to Force Download
        $filename = "sales-report-{$dateStart}-to-{$dateEnd}.csv";
        $response->getBody()->write($csv);
        
        return $response
            ->withHeader('Content-Type', 'text/csv')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withStatus(200);
    }
}