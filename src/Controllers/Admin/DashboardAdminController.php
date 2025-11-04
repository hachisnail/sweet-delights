<?php
namespace SweetDelights\Mayie\Controllers\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

// Make sure it extends your BaseAdminController
class DashboardAdminController extends BaseAdminController
{
    // --- Removed local path properties ---

    public function __construct()
    {
        // --- Call parent to set up all data paths ---
        parent::__construct();
    }

    // --- Data Helpers (getOrders, getUsers, getProducts) are now inherited ---

    /**
     * Gathers all data and renders the main admin dashboard.
     */
    public function dashboard(Request $request, Response $response): Response
    {
        $view = $this->viewFromRequest($request);

        // --- 1. Load All Data (using inherited methods) ---
        $allOrders = $this->getOrders();
        $allUsers = $this->getUsers();
        $allProducts = $this->getProducts();

        // --- 2. Calculate Stats ---
        $totalRevenue = 0;
        $totalCustomers = 0;
        $processingOrders = [];
        $totalOrderCount = count($allOrders);

        // --- NEW: Chart Data Arrays ---
        $salesByDay = [];
        $orderStatusCounts = [
            'Processing' => 0,
            'Shipped' => 0,
            'Delivered' => 0,
            'Cancelled' => 0
        ];
        // --- END NEW ---

        // Initialize sales for the last 7 days
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $salesByDay[$date] = 0;
        }

        foreach ($allOrders as $order) {
            // -- For Stats --
            if (in_array($order['status'], ['Shipped', 'Delivered'])) {
                $totalRevenue += $order['total'];
            }
            // Get orders that need action
            if ($order['status'] === 'Processing') {
                $processingOrders[] = $order;
            }

            // --- NEW: Tally Data for Charts ---
            
            // Tally sales by day (for completed orders)
            $orderDate = date('Y-m-d', strtotime($order['date']));
            if (isset($salesByDay[$orderDate]) && in_array($order['status'], ['Shipped', 'Delivered'])) {
                $salesByDay[$orderDate] += $order['total'];
            }

            // Tally order statuses
            if (isset($orderStatusCounts[$order['status']])) {
                $orderStatusCounts[$order['status']]++;
            }
            // --- END NEW ---
        }

        foreach ($allUsers as $user) {
            if ($user['role'] === 'customer') {
                $totalCustomers++;
            }
        }
        
        // Sort pending orders by date (newest first)
        usort($processingOrders, function($a, $b) {
            return strtotime($b['date']) <=> strtotime($a['date']);
        });
        $recentPendingOrders = array_slice($processingOrders, 0, 5); // Get top 5

        // --- 3. Find Low Stock Items ---
        $lowStockItems = array_filter($allProducts, function ($product) {
            // Show items that are not out of stock, but running low (e.g., 5 or less)
            return isset($product['stock']) && $product['stock'] > 0 && $product['stock'] <= 5;
        });


        // Sort by stock, lowest first
        usort($lowStockItems, function ($a, $b) {
            $stockA = $a['stock'] ?? 0;
            $stockB = $b['stock'] ?? 0;
            return $stockA <=> $stockB;
        });

        $fiveLowStock = array_slice($lowStockItems, 0, 5); // Get top 5

        
        return $view->render($response, 'Admin/dashboard.twig', [
            'title' => 'Admin Dashboard',
            'active_page' => 'dashboard',
            'show_breadcrumbs' => false, // No breadcrumbs on the main dashboard
            'app_url' => $_ENV['APP_URL'] ?? '',
            'stats' => [
                'revenue' => $totalRevenue,
                'total_orders' => $totalOrderCount,
                'pending_orders' => count($processingOrders),
                'customers' => $totalCustomers
            ],
            'recent_orders' => $recentPendingOrders,
            'low_stock_items' => $fiveLowStock,
            
            // --- NEW: Pass Chart Data to Twig ---
            'chart_data' => [
                'sales_labels' => json_encode(array_keys($salesByDay)),
                'sales_values' => json_encode(array_values($salesByDay)),
                'status_labels' => json_encode(array_keys($orderStatusCounts)),
                'status_values' => json_encode(array_values($orderStatusCounts))
            ]
            // --- END NEW ---
        ]);
    }
}