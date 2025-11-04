<?php
namespace SweetDelights\Mayie\Controllers\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteContext;

class OrderAdminController extends BaseAdminController
{
    // --- All data helpers and __construct are now in BaseAdminController ---

    /**
     * List all orders with filters.
     */
    public function index(Request $request, Response $response): Response
    {
        $view = $this->viewFromRequest($request);
        $params = $request->getQueryParams();
        
        $filterStatus = $params['status'] ?? '';
        
        $allOrders = $this->getOrders(); // Inherited

        // Filter by status if provided
        if ($filterStatus) {
            $filteredOrders = array_filter($allOrders, fn($order) => $order['status'] === $filterStatus);
        } else {
            $filteredOrders = $allOrders;
        }

        // Sort by date, newest first
        usort($filteredOrders, fn($a, $b) => strtotime($b['date']) <=> strtotime($a['date']));

        return $view->render($response, 'Admin/orders.twig', [
            'title' => 'Manage Orders',
            'orders' => $filteredOrders,
            'current_status' => $filterStatus,
            'breadcrumbs' => $this->breadcrumbs($request, [
                ['name' => 'Orders', 'url' => null]
            ]),
            'active_page' => 'orders',
            'app_url' => $_ENV['APP_URL'] ?? ''
        ]);
    }

    /**
     * Show details for a single order.
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $view = $this->viewFromRequest($request);
        $orderId = (int)$args['id'];
        
        $allOrders = $this->getOrders(); // Inherited
        $foundOrder = null;
        foreach ($allOrders as $order) {
            if ($order['id'] === $orderId) {
                $foundOrder = $order;
                break;
            }
        }

        if (!$foundOrder) {
            $routeParser = RouteContext::fromRequest($request)->getRouteParser();
            return $response->withHeader('Location', $routeParser->urlFor('app.orders.index'))->withStatus(302);
        }

        // --- START: ROBUST FALLBACK LOGIC ---
        // This block handles items with id, sku, or both
        $allProducts = $this->getProducts();
        $productMapById = array_column($allProducts, null, 'id');
        $productMapBySku = array_column($allProducts, null, 'sku');

        if (isset($foundOrder['items']) && is_array($foundOrder['items'])) {
            foreach ($foundOrder['items'] as &$item) {
                $productData = null;

                if (isset($item['sku'])) { 
                    // Priority: Look up by SKU
                    $productData = $productMapBySku[$item['sku']] ?? null;
                } elseif (isset($item['id'])) { 
                    // Fallback: Look up by ID
                    $productData = $productMapById[$item['id']] ?? null;
                }

                // Now, ensure the item has both id and sku for the template
                if ($productData) {
                    // We found the product, so we can populate both keys
                    $item['id'] = $productData['id'];
                    $item['sku'] = $productData['sku'];
                } else {
                    // Product was deleted or malformed.
                    // This is the FIX: We must ensure both keys exist 
                    // before the loop continues, to prevent errors.
                    if (!isset($item['id'])) {
                         $item['id'] = null; // Ensure 'id' key exists
                    }
                    if (!isset($item['sku'])) {
                        $item['sku'] = null; // Ensure 'sku' key exists
                    }
                }
            }
            unset($item); // Unset the reference
        }
        // --- END: ROBUST FALLBACK LOGIC ---
        
        // --- Get Customer Details ---
        $allUsers = $this->getUsers(); // Inherited
        foreach($allUsers as $user) {
            if ($user['id'] === $foundOrder['user_id']) {
                $foundOrder['customer_email'] = $user['email'];
                $foundOrder['customer_contact'] = $user['contact_number'];
                break;
            }
        }

        return $view->render($response, 'Admin/order-details.twig', [
            'title' => "Order #" . $foundOrder['id'],
            'order' => $foundOrder,
            'order_statuses' => ['Processing', 'Shipped', 'Cancelled'],
            'breadcrumbs' => $this->breadcrumbs($request, [
                ['name' => 'Orders', 'url' => 'app.orders.index'],
                ['name' => "Order #" . $foundOrder['id'], 'url' => null]
            ]),
            'active_page' => 'orders',
            'app_url' => $_ENV['APP_URL'] ?? ''
        ]);
    }

    /**
     * Update an order's status.
     */
    public function updateStatus(Request $request, Response $response, array $args): Response
    {
        $orderId = (int)$args['id'];
        $data = $request->getParsedBody();
        $newStatus = $data['status'] ?? 'Processing';
        
        // --- ADD VALIDATION ---
        $allowedAdminStatuses = ['Processing', 'Shipped', 'Cancelled'];
        if (!in_array($newStatus, $allowedAdminStatuses)) {
            $routeParser = RouteContext::fromRequest($request)->getRouteParser();
            $url = $routeParser->urlFor('app.orders.show', ['id' => $orderId]);
            return $response->withHeader('Location', $url)->withStatus(302);
        }
        // --- END VALIDATION ---

        $allOrders = $this->getOrders(); // Inherited
        $orderUpdated = false;

        foreach ($allOrders as &$order) {
            if ($order['id'] === $orderId) {
                $order['status'] = $newStatus;
                $orderUpdated = true;
                break;
            }
        }
        unset($order);

        if ($orderUpdated) {
            $this->saveOrders($allOrders); 
        }

        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        $url = $routeParser->urlFor('app.orders.show', ['id' => $orderId]);
        return $response->withHeader('Location', $url)->withStatus(302);
    }
}