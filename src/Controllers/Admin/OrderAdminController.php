<?php
namespace SweetDelights\Mayie\Controllers\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteContext;
use \PDO;

class OrderAdminController extends BaseAdminController
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * List all orders with filters.
     */
    public function index(Request $request, Response $response): Response
    {
        $view = $this->viewFromRequest($request);
        $params = $request->getQueryParams();
        
        $filterStatus = $params['status'] ?? '';
        
        $sql = "SELECT * FROM orders";
        $queryParams = [];
        
        if ($filterStatus) {
            $sql .= " WHERE status = ?";
            $queryParams[] = $filterStatus;
        }
        
        $sql .= " ORDER BY date DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($queryParams);
        $filteredOrders = $stmt->fetchAll();

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
        
        $foundOrder = $this->getOrderById($orderId);

        if (!$foundOrder) {
            $routeParser = RouteContext::fromRequest($request)->getRouteParser();
            return $response->withHeader('Location', $routeParser->urlFor('app.orders.index'))->withStatus(302);
        }

        $allProducts = $this->getProducts();
        $productMapBySku = array_column($allProducts, null, 'sku');

        if (isset($foundOrder['items']) && is_array($foundOrder['items'])) {
            foreach ($foundOrder['items'] as &$item) {
                $productData = null;
                
                if (isset($item['product_sku'])) { 
                    $productData = $productMapBySku[$item['product_sku']] ?? null;
                }

                if ($productData) {
                    $item['id'] = $productData['id'];
                    $item['sku'] = $productData['sku'];
                } else {
                    $item['id'] = null;
                    $item['sku'] = $item['product_sku'] ?? 'unknown';
                }
            }
            unset($item); 
        }
        
        $customer = $this->findUserById($foundOrder['user_id']); 
        if ($customer) {
            $foundOrder['customer_email'] = $customer['email'];
            $foundOrder['customer_contact'] = $customer['contact_number'];
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
            'app_url' => $_ENV['APP_URL'] ?? '',
            'request' => $request
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
            $routeParser = RouteContext::fromRequest($request)->getRouteParser();
            
            $url = $routeParser->urlFor('app.orders.show', ['id' => $orderId]); 
            
            $user = $request->getAttribute('user');
            $actorId = $user ? (int)$user['id'] : null;
            
            $allowedAdminStatuses = ['Processing', 'Shipped', 'Cancelled'];
            if (!in_array($newStatus, $allowedAdminStatuses)) {
                return $response->withHeader('Location', $url)->withStatus(302);
            }
            
            $orderBefore = $this->getOrderById($orderId);
            if (!$orderBefore) {
                return $response->withHeader('Location', $routeParser->urlFor('app.orders.index'))->withStatus(302);
            }

            $currentStatus = $orderBefore['status'];
            if (($currentStatus === 'Shipped' || $currentStatus === 'Delivered') && $newStatus === 'Processing') {
                return $response->withHeader('Location', $url . '?error=revert')->withStatus(302);
            }

            if ($orderBefore['status'] === $newStatus) {
                return $response->withHeader('Location', $url)->withStatus(302);
            }

            $stmt = $this->db->prepare("UPDATE orders SET status = ? WHERE id = ?");
            $stmt->execute([$newStatus, $orderId]);
            
            $orderAfter = $this->getOrderById($orderId);
            
            $this->logEntityChange(
                $actorId, 'update', 'order', $orderId, $orderBefore, $orderAfter
            );
            
            return $response->withHeader('Location', $url . '?success=status')->withStatus(302);
        }
}