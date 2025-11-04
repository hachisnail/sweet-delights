<?php
namespace SweetDelights\Mayie\Controllers\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteContext;

class OrderAdminController extends BaseAdminController
{
    private $ordersPath;
    private $usersPath;
    private $productsPath;

    public function __construct()
    {
        $this->ordersPath = __DIR__ . '/../../Data/orders.php';
        $this->usersPath = __DIR__ . '/../../Data/users.php';
        $this->productsPath = __DIR__ . '/../../Data/products.php';
    }

    // --- Data Helpers ---
    private function getOrders(): array { return file_exists($this->ordersPath) ? require $this->ordersPath : []; }
    private function saveOrders(array $data) { $this->saveData($this->ordersPath, $data); }
    private function getUsers(): array { return file_exists($this->usersPath) ? require $this->usersPath : []; }
    private function getProducts(): array { return file_exists($this->productsPath) ? require $this->productsPath : []; }

    /**
     * List all orders with filters.
     */
    public function index(Request $request, Response $response): Response
    {
        $view = $this->viewFromRequest($request);
        $params = $request->getQueryParams();
        
        $filterStatus = $params['status'] ?? '';
        
        $allOrders = $this->getOrders();

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
        
        $allOrders = $this->getOrders();
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

        // --- Inject SKU into items for linking ---
        $skuMap = array_column($this->getProducts(), 'sku', 'id');
        if (isset($foundOrder['items']) && is_array($foundOrder['items'])) {
            foreach ($foundOrder['items'] as &$item) {
                $item['sku'] = $skuMap[$item['id']] ?? null;
            }
            unset($item);
        }
        
        // --- Get Customer Details ---
        $allUsers = $this->getUsers();
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
                // Admin tried to set a status they shouldn't (like 'Delivered')
                // Just redirect back without making a change.
                $routeParser = RouteContext::fromRequest($request)->getRouteParser();
                $url = $routeParser->urlFor('app.orders.show', ['id' => $orderId]);
                return $response->withHeader('Location', $url)->withStatus(302);
            }
            // --- END VALIDATION ---

            $allOrders = $this->getOrders();
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