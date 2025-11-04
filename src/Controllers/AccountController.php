<?php
namespace SweetDelights\Mayie\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Slim\Routing\RouteContext;
class AccountController extends BaseDataController {

    /**
     * @var string The path to the users data file.
     */
    private $usersPath;
    private $ordersPath;
    private $productsPath;
    /**
     * Constructor to set up data paths.
     */
    public function __construct()
    {
        $this->usersPath = __DIR__ . '/../Data/users.php';
        $this->ordersPath = __DIR__ . '/../Data/orders.php'; 
        $this->productsPath = __DIR__ . '/../Data/products.php';
    }

    // --- Data Helper Functions ---

    /**
     * Gets all users from the data file.
     * @return array
     */
    private function getUsers(): array
    {
        if (!file_exists($this->usersPath)) {
            return [];
        }
        return require $this->usersPath;
    }

    /**
     * Saves the entire users array back to the file.
     * @param array $users The array of users to save.
     */
    private function saveUsers(array $users)
    {
        // Re-index the array if keys are not sequential (e.g., after deletions)
        $users = array_values($users);
        $this->saveData($this->usersPath, $users);
    }

    private function getOrders(): array
    {
        if (!file_exists($this->ordersPath)) { 
            return []; 
        }
        return require $this->ordersPath;
    }

    private function saveOrders(array $data) 
    { 
        $this->saveData($this->ordersPath, $data); 
    }

    private function getProducts(): array
    {
        if (!file_exists($this->productsPath)) { return []; }
        return require $this->productsPath;
    }
    /**
     * Helper function to write data to a PHP file.
     * (This logic is copied from your BaseAdminController)
     * @param string $path The file path to write to.
     * @param array $data The data to save.
     */


    // --- Controller Methods ---

    public function confirmDelivery(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user'); // Get current user
        $orderId = (int)$args['id'];
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        $redirectUrl = $routeParser->urlFor('account.orders.show', ['id' => $orderId]);
        
        $allOrders = $this->getOrders();
        $orderUpdated = false;

        foreach ($allOrders as &$order) {
            // Find the correct order
            if ($order['id'] === $orderId) {
                
                // SECURITY CHECKS:
                // 1. Does this order belong to the logged-in user?
                // 2. Is the order status "Shipped"?
                if ($order['user_id'] === $user['id'] && $order['status'] === 'Shipped') {
                    $order['status'] = 'Delivered';
                    $orderUpdated = true;
                }
                
                break;
            }
        }
        unset($order);

        if ($orderUpdated) {
            $this->saveOrders($allOrders);
        }

        // Redirect back to the order page
        return $response->withHeader('Location', $redirectUrl)->withStatus(302);
    }
    

    /**
     * Show the user's account settings page.
     * (Unchanged)
     */

    public function showSettings(Request $request, Response $response): Response {
        $view = Twig::fromRequest($request);
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
        $view = Twig::fromRequest($request);
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
        $view = Twig::fromRequest($request);
        $user = $request->getAttribute('user');
        $orderId = (int)$args['id'];

        $allOrders = $this->getOrders();
        $foundOrder = null;
        foreach ($allOrders as $order) {
            if ($order['id'] === $orderId) {
                $foundOrder = $order;
                break;
            }
        }   

        $routeParser = RouteContext::fromRequest($request)->getRouteParser();

        if (!$foundOrder || $foundOrder['user_id'] !== $user['id']) {
            return $response->withHeader('Location', $routeParser->urlFor('account.orders'))->withStatus(302);
        }

        // --- NEW LOGIC: INJECT SKU ---
        $allProducts = $this->getProducts();
        // Create a simple lookup map for efficiency [id => sku]
        $skuMap = [];
        foreach ($allProducts as $product) {
            $skuMap[$product['id']] = $product['sku'];
        }

        // Loop through order items and add the sku
        if (isset($foundOrder['items']) && is_array($foundOrder['items'])) {
            foreach ($foundOrder['items'] as &$item) {
                if (isset($skuMap[$item['id']])) {
                    $item['sku'] = $skuMap[$item['id']];
                } else {
                    $item['sku'] = null; // Product might have been deleted
                }
            }
            unset($item); // Unset the reference
        }
        // --- END NEW LOGIC ---

        return $view->render($response, 'User/order-details.twig', [
            'title' => 'Order Details #' . $foundOrder['id'],
            'order' => $foundOrder // This $foundOrder now contains the SKU for each item
        ]);
    }
    /**
     * MODIFIED: Handle the profile update form submission.
     * Now saves to file AND updates session.
     */
    public function updateProfile(Request $request, Response $response): Response {
        $sessionUser = $request->getAttribute('user'); // Get current user from session
        $data = $request->getParsedBody();        // Get form data
        $allUsers = $this->getUsers();            // Get all users from file
        
        $updatedSessionData = $sessionUser; // Start with current session data

        // Find the user in the file array and update them
        foreach ($allUsers as $index => &$fileUser) {
            if ($fileUser['id'] === $sessionUser['id']) {
                
                // 1. Update the data in the file array
                $fileUser['first_name'] = $data['first_name'] ?? $fileUser['first_name'];
                $fileUser['last_name'] = $data['last_name'] ?? $fileUser['last_name'];
                $fileUser['email'] = $data['email'] ?? $fileUser['email'];
                $fileUser['contact_number'] = $data['contact_number'] ?? $fileUser['contact_number'];
                $fileUser['address'] = [
                    'street' => $data['address_street'] ?? $fileUser['address']['street'],
                    'city' => $data['address_city'] ?? $fileUser['address']['city'],
                    'state' => $data['address_state'] ?? $fileUser['address']['state'],
                    'postal_code' => $data['address_postal_code'] ?? $fileUser['address']['postal_code'],
                ];

                // 2. Prepare the data for the session update
                // Merge the *updated file data* with the *current session data*
                // This preserves session-only data like 'cart' and 'favourites'.
                $updatedSessionData = array_merge($sessionUser, $fileUser);
                break;
            }
        }
        unset($fileUser); // Unset the reference

        // 3. Save the modified user array back to the file
        $this->saveUsers($allUsers);

        // 4. Update the session with the newly saved data
        $_SESSION['user'] = $updatedSessionData;

        // 5. Redirect back with a success message
        return $response->withHeader('Location', '/account/settings?success=profile')->withStatus(302);
    }

    /**
     * MODIFIED: Handle the change password form submission.
     * Now saves the new hash to the file.
     */
    public function updatePassword(Request $request, Response $response): Response {
        $user = $request->getAttribute('user'); // User from session
        $data = $request->getParsedBody();

        $currentPassword = $data['current_password'] ?? '';
        $newPassword = $data['new_password'] ?? '';

        // 1. Load all users from the file
        $allUsers = $this->getUsers();
        $foundUser = null;
        $foundUserIndex = -1;

        foreach ($allUsers as $index => $u) {
            if ($u['id'] === $user['id']) {
                $foundUser = $u;
                $foundUserIndex = $index;
                break;
            }
        }

        // 2. Check if current password is correct
        if (!$foundUser || !password_verify($currentPassword, $foundUser['password_hash'])) {
            // Failed: Redirect back with an error
            return $response->withHeader('Location', '/account/settings?error=password')->withStatus(302);
        }

        // 3. Success: Password matches.
        // Hash the new password
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        
        // Update the hash in the user array
        $allUsers[$foundUserIndex]['password_hash'] = $newHash;
        
        // 4. Save the updated user array back to the file
        $this->saveUsers($allUsers);

        // 5. Redirect back with a success message
        return $response->withHeader('Location', '/account/settings?success=password')->withStatus(302);
    }
}