<?php
namespace SweetDelights\Mayie\Controllers\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ReportsAdminController extends BaseAdminController
{

    /**
     * This is the helper function that does the actual work.
     * We'll use this for both the web view and the CSV export.
     */
private function generateReportData(string $dateStart, string $dateEnd): array
{
    $allOrders = $this->getOrders(); 
    
    $filteredOrders = array_filter($allOrders, function($order) use ($dateStart, $dateEnd) {
        $orderTimestamp = strtotime($order['date']);
        
        $startOfDay = strtotime($dateStart);
        $endOfPeriod = strtotime($dateEnd . ' +1 day');

        return $order['status'] !== 'Cancelled' &&
               $orderTimestamp >= $startOfDay &&
               $orderTimestamp < $endOfPeriod; 
    });

        $totalSales = 0;
        $totalOrders = count($filteredOrders);
        $itemsSold = [];

        foreach ($filteredOrders as $order) {
            $totalSales += $order['total'];
            
            if (isset($order['items']) && is_array($order['items'])) {
                foreach ($order['items'] as $item) {
                    $sku = $item['sku'] ?? null; 
                    if ($sku) { 
                        $qty = $item['quantity'];
                        if (!isset($itemsSold[$sku])) {
                            $itemsSold[$sku] = 0;
                        }
                        $itemsSold[$sku] += $qty;
                    }
                }
            }
        }

        $allProducts = $this->getProducts();
        $productNameMap = array_column($allProducts, 'name', 'sku');
        $productIdMap = array_column($allProducts, 'id', 'sku');
        
        $bestSellers = [];
        foreach ($itemsSold as $sku => $quantity) {
            $bestSellers[] = [
                'id' => $productIdMap[$sku] ?? null, 
                'name' => $productNameMap[$sku] ?? 'Unknown Product', 
                'quantity' => $quantity
            ];
        }
        
        usort($bestSellers, function($a, $b) {
            return $b['quantity'] <=> $a['quantity'];
        });

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

        $user = $request->getAttribute('user');
        $actorId = $user ? (int)$user['id'] : null;

        $defaultStart = date('Y-m-d', strtotime('-30 days'));
        $defaultEnd = date('Y-m-d');
        $dateStart = $params['date_start'] ?? $defaultStart;
        $dateEnd = $params['date_end'] ?? $defaultEnd;

        $reportData = $this->generateReportData($dateStart, $dateEnd);

        $this->logAction(
            $actorId,
            'export', 
            'report', 
            null,     
            [ 
                'report_type' => 'sales',
                'date_start' => $dateStart,
                'date_end' => $dateEnd,
                'summary' => $reportData['stats'] 
            ]
        );

        
        $stream = fopen('php://memory', 'w');

        fputcsv($stream, ["Sales Report ($dateStart to $dateEnd)"]);
        fputcsv($stream, []); 

        fputcsv($stream, ["Metric", "Value"]);
        fputcsv($stream, ["Total Sales", $reportData['stats']['total_sales']]);
        fputcsv($stream, ["Total Orders", $reportData['stats']['total_orders']]);
        fputcsv($stream, ["Total Items Sold", $reportData['stats']['total_items_sold']]);
        
        fputcsv($stream, []); 

        fputcsv($stream, ["Top 10 Best Sellers"]);
        fputcsv($stream, ["Rank", "Product Name", "Quantity Sold"]);
        foreach ($reportData['best_sellers'] as $index => $item) {
            fputcsv($stream, [
                $index + 1,
                $item['name'], 
                $item['quantity']
            ]);
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        $filename = "sales-report-{$dateStart}-to-{$dateEnd}.csv";
        $response->getBody()->write($csv);
        
        return $response
            ->withHeader('Content-Type', 'text/csv')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withStatus(200);
    }
}