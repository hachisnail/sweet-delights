<?php
namespace SweetDelights\Mayie\Controllers\Customer;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
// use Slim\Views\Twig; // <-- Removed
// use SweetDelights\Mayie\Controllers\BaseDataController; // <-- Removed
use SweetDelights\Mayie\Controllers\Admin\BaseAdminController; // <-- Added
use Slim\Routing\RouteContext;

// --- FIX: Extend the BaseAdminController ---
class AccountController extends BaseAdminController {



    public function __construct()
    {
        parent::__construct();

    }



    public function confirmDelivery(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user'); 
        $orderId = (int)$args['id'];
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        $redirectUrl = $routeParser->urlFor('account.orders.show', ['id' => $orderId]);
        
        $orderBefore = $this->getOrderById($orderId);

        if (!$orderBefore || $orderBefore['user_id'] !== $user['id'] || $orderBefore['status'] !== 'Shipped') {
            return $response->withHeader('Location', $redirectUrl)->withStatus(302);
        }

        $stmt = $this->db->prepare("
            UPDATE orders 
            SET status = 'Delivered' 
            WHERE id = ? AND user_id = ? AND status = 'Shipped'
        ");
        $stmt->execute([$orderId, $user['id']]);

        $orderAfter = $this->getOrderById($orderId);
        $this->logEntityChange(
            $user['id'],    
            'update',
            'order',
            $orderId,
            $orderBefore,
            $orderAfter
        );

        return $response->withHeader('Location', $redirectUrl)->withStatus(302);
    }
    

    /**
     * Show the user's account settings page.
     */

    public function showSettings(Request $request, Response $response): Response {
        $view = $this->viewFromRequest($request);
        $user = $request->getAttribute('user'); 
        $params = $request->getQueryParams();

        return $view->render($response, 'User/settings.twig', [
            'title' => 'Account Settings',
            'user'  => $user,
            'success' => $params['success'] ?? null,
            'error'   => $params['error'] ?? null
        ]);
    }

    /**
     * Show the user's order history page.
     */
    public function showOrders(Request $request, Response $response): Response {
        $view = $this->viewFromRequest($request);
        $user = $request->getAttribute('user');
        
        $allOrders = $this->getOrders();
        
        $userOrders = array_filter($allOrders, function($order) use ($user) {
            return $order['user_id'] === $user['id'];
        });

        usort($userOrders, function($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });
        
        return $view->render($response, 'User/orders.twig', [
            'title' => 'My Orders',
            'orders' => $userOrders
        ]);
    }

   public function showOrderDetails(Request $request, Response $response, array $args): Response
    {
        $view = $this->viewFromRequest($request);
        $user = $request->getAttribute('user');
        $orderId = (int)$args['id'];

        $foundOrder = $this->getOrderById($orderId);

        $routeParser = RouteContext::fromRequest($request)->getRouteParser();

        if (!$foundOrder || $foundOrder['user_id'] !== $user['id']) {
            return $response->withHeader('Location', $routeParser->urlFor('account.orders'))->withStatus(302);
        }

        $allProducts = $this->getProducts();
        

        $idMap = [];
        foreach ($allProducts as $product) {
            $idMap[$product['sku']] = $product['id']; 
        }

        if (isset($foundOrder['items']) && is_array($foundOrder['items'])) {
            foreach ($foundOrder['items'] as &$item) {
                if (isset($item['product_sku']) && isset($idMap[$item['product_sku']])) {
                    $item['id'] = $idMap[$item['product_sku']]; 
                } else {
                    $item['id'] = null; 
                }
            }
            unset($item);
        }

        return $view->render($response, 'User/order-details.twig', [
            'title' => 'Order Details #' . $foundOrder['id'],
            'order' => $foundOrder
        ]);
   }
    /**
     * MODIFIED: Handle the profile update form submission.
     * Now saves to file AND updates session.
     */
    public function updateProfile(Request $request, Response $response): Response {
        $sessionUser = $request->getAttribute('user');
        $data = $request->getParsedBody();         
        $actorId = $sessionUser['id'];

        
        $userBefore = $this->findUserById($actorId);

        $dataToUpdate = [
            'first_name' => $data['first_name'] ?? $sessionUser['first_name'],
            'last_name' => $data['last_name'] ?? $sessionUser['last_name'],
            'email' => $data['email'] ?? $sessionUser['email'],
            'contact_number' => $data['contact_number'] ?? $sessionUser['contact_number'],
            'address' => [
                'street' => $data['address_street'] ?? ($sessionUser['address']['street'] ?? ''),
                'city' => $data['address_city'] ?? ($sessionUser['address']['city'] ?? ''),
                'state' => $data['address_state'] ?? ($sessionUser['address']['state'] ?? ''),
                'postal_code' => $data['address_postal_code'] ?? ($sessionUser['address']['postal_code'] ?? ''),
            ]
        ];

        $this->updateUser($actorId, $dataToUpdate);

        $updatedSessionData = array_merge($sessionUser, $dataToUpdate);
        $_SESSION['user'] = $updatedSessionData;

        $userAfter = $this->findUserById($actorId); 
        $this->logEntityChange(
            $actorId,
            'update',
            'user',
            $actorId,
            $userBefore,
            $userAfter
        );


        return $response->withHeader('Location', '/account/settings?success=profile')->withStatus(302);
    }

    /**
     * MODIFIED: Handle the change password form submission.
     * Now saves the new hash to the file.
     */
    public function updatePassword(Request $request, Response $response): Response {
        $user = $request->getAttribute('user'); 
        $actorId = $user['id'];
        $data = $request->getParsedBody();

        $currentPassword = $data['current_password'] ?? '';
        $newPassword = $data['new_password'] ?? '';


        $dbUser = $this->findUserById($actorId);

        if (!$dbUser || !password_verify($currentPassword, $dbUser['password_hash'])) {
            return $response->withHeader('Location', '/account/settings?error=password')->withStatus(302);
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        
        $this->updateUser($actorId, [
            'password_hash' => $newHash
        ]);

        $userAfter = $this->findUserById($actorId);

        unset($dbUser['password_hash']);
        unset($userAfter['password_hash']);

        $this->logEntityChange(
            $actorId,
            'update', 
            'user',
            $actorId,
            $dbUser, 
            $userAfter 
        );


        return $response->withHeader('Location', '/account/settings?success=password')->withStatus(302);
    }
}