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

    /**
     * @var string The path to the users data file.
     */
    // --- FIX: All data-path properties removed ---
    // private $usersPath;
    // private $ordersPath;
    // private $productsPath;

    /**
     * --- FIX: Constructor now just calls the parent to get DB access ---
     */
    public function __construct()
    {
        parent::__construct();
        // $this->usersPath = __DIR__ . '/../../Data/users.php';
        // $this->ordersPath = __DIR__ . '/../../Data/orders.php'; 
        // $this->productsPath = __DIR__ . '/../../Data/products.php';
    }

    // --- FIX: All local data-helper functions are removed (getUsers, saveUsers, etc.) ---
    // They are now all inherited from BaseAdminController.


    // --- Controller Methods ---

    public function confirmDelivery(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user'); // Get current user
        $orderId = (int)$args['id'];
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        $redirectUrl = $routeParser->urlFor('account.orders.show', ['id' => $orderId]);
        
        // --- 1. GET "BEFORE" STATE ---
        $orderBefore = $this->getOrderById($orderId);

        // 2. Validate the action
        if (!$orderBefore || $orderBefore['user_id'] !== $user['id'] || $orderBefore['status'] !== 'Shipped') {
            // Either not their order, or not in a 'Shipped' state.
            return $response->withHeader('Location', $redirectUrl)->withStatus(302);
        }

        // --- 3. PERFORM UPDATE ---
        $stmt = $this->db->prepare("
            UPDATE orders 
            SET status = 'Delivered' 
            WHERE id = ? AND user_id = ? AND status = 'Shipped'
        ");
        $stmt->execute([$orderId, $user['id']]);

        // --- 4. LOG ACTIVITY ---
        $orderAfter = $this->getOrderById($orderId); // Get "after" state
        $this->logEntityChange(
            $user['id'],    // The customer is the actor
            'update',
            'order',
            $orderId,
            $orderBefore,
            $orderAfter
        );
        // --- END LOG ---

        // Redirect back to the order page
        return $response->withHeader('Location', $redirectUrl)->withStatus(302);
    }
    

    /**
     * Show the user's account settings page.
     * (Unchanged)
     */

    public function showSettings(Request $request, Response $response): Response {
        // --- FIX: Use inherited view helper ---
        $view = $this->viewFromRequest($request);
        $user = $request->getAttribute('user'); // User from session
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
     * (Unchanged, but fixed a stray semicolon)
     */
    public function showOrders(Request $request, Response $response): Response {
        // --- FIX: Use inherited view helper ---
        $view = $this->viewFromRequest($request);
        $user = $request->getAttribute('user');
        
        // --- FIX: This now calls the inherited getOrders() from the DB ---
        $allOrders = $this->getOrders();
        
        // This filter logic is still perfectly valid.
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
        // --- FIX: Use inherited view helper ---
        $view = $this->viewFromRequest($request);
        $user = $request->getAttribute('user');
        $orderId = (int)$args['id'];

        // --- FIX: Use the efficient getOrderById() helper ---
        $foundOrder = $this->getOrderById($orderId);

        $routeParser = RouteContext::fromRequest($request)->getRouteParser();

        // This security check is still perfect
        if (!$foundOrder || $foundOrder['user_id'] !== $user['id']) {
            return $response->withHeader('Location', $routeParser->urlFor('account.orders'))->withStatus(302);
        }

        // --- FIX: This now calls the inherited getProducts() from the DB ---
        $allProducts = $this->getProducts();
        
        // This logic for back-filling the product 'id' from the 'sku' is still
        // valid and now uses the DB-fetched product list.
        $idMap = [];
        foreach ($allProducts as $product) {
            $idMap[$product['sku']] = $product['id']; 
        }

        if (isset($foundOrder['items']) && is_array($foundOrder['items'])) {
            foreach ($foundOrder['items'] as &$item) {
                // --- FIX: Use 'product_sku' which comes from the DB ---
                if (isset($item['product_sku']) && isset($idMap[$item['product_sku']])) {
                    $item['id'] = $idMap[$item['product_sku']]; 
                } else {
                    $item['id'] = null; // Product might have been deleted
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
        $sessionUser = $request->getAttribute('user'); // Get current user from session
        $data = $request->getParsedBody();            // Get form data
        $actorId = $sessionUser['id'];

        // --- FIX: Use the inherited updateUser() helper ---
        
        // --- 1. GET "BEFORE" STATE ---
        $userBefore = $this->findUserById($actorId);

        // 2. Build the data array for the helper
        $dataToUpdate = [
            'first_name' => $data['first_name'] ?? $sessionUser['first_name'],
            'last_name' => $data['last_name'] ?? $sessionUser['last_name'],
            'email' => $data['email'] ?? $sessionUser['email'],
            'contact_number' => $data['contact_number'] ?? $sessionUser['contact_number'],
            // The updateUser helper will JSON-encode this array
            'address' => [
                'street' => $data['address_street'] ?? ($sessionUser['address']['street'] ?? ''),
                'city' => $data['address_city'] ?? ($sessionUser['address']['city'] ?? ''),
                'state' => $data['address_state'] ?? ($sessionUser['address']['state'] ?? ''),
                'postal_code' => $data['address_postal_code'] ?? ($sessionUser['address']['postal_code'] ?? ''),
            ]
        ];

        // 3. Call the helper to update the database
        $this->updateUser($actorId, $dataToUpdate);

        // 4. Update the session with the new data
        // We merge the new data into the existing session to preserve 'cart', etc.
        $updatedSessionData = array_merge($sessionUser, $dataToUpdate);
        $_SESSION['user'] = $updatedSessionData;

        // --- 5. LOG ACTIVITY ---
        $userAfter = $this->findUserById($actorId); // Get "after" state
        $this->logEntityChange(
            $actorId,
            'update',
            'user',
            $actorId,
            $userBefore,
            $userAfter
        );
        // --- END LOG ---


        // 6. Redirect back with a success message
        return $response->withHeader('Location', '/account/settings?success=profile')->withStatus(302);
    }

    /**
     * MODIFIED: Handle the change password form submission.
     * Now saves the new hash to the file.
     */
    public function updatePassword(Request $request, Response $response): Response {
        $user = $request->getAttribute('user'); // User from session
        $actorId = $user['id'];
        $data = $request->getParsedBody();

        $currentPassword = $data['current_password'] ?? '';
        $newPassword = $data['new_password'] ?? '';

        // --- FIX: Use findUserById() and updateUser() helpers ---

        // 1. Get the definitive user record from the DB, not the session
        // This is our "BEFORE" state
        $dbUser = $this->findUserById($actorId);

        // 2. Check if current password is correct
        if (!$dbUser || !password_verify($currentPassword, $dbUser['password_hash'])) {
            // Failed: Redirect back with an error
            return $response->withHeader('Location', '/account/settings?error=password')->withStatus(302);
        }

        // 3. Success: Password matches.
        // Hash the new password
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        
        // 4. Call the helper to update only the password
        $this->updateUser($actorId, [
            'password_hash' => $newHash
        ]);

        // --- 5. LOG ACTIVITY ---
        // Get "after" state
        $userAfter = $this->findUserById($actorId);

        // !!! SECURITY: Unset password hashes before logging !!!
        unset($dbUser['password_hash']);
        unset($userAfter['password_hash']);

        $this->logEntityChange(
            $actorId,
            'update', // Kept generic for security
            'user',
            $actorId,
            $dbUser, // "before" state (hash removed)
            $userAfter // "after" state (hash removed)
        );
        // --- END LOG ---


        return $response->withHeader('Location', '/account/settings?success=password')->withStatus(302);
    }
}