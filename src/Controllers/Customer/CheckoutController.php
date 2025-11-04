<?php
namespace SweetDelights\Mayie\Controllers\Customer;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use SweetDelights\Mayie\Controllers\BaseDataController;
use Slim\Routing\RouteContext;


class CheckoutController extends BaseDataController {

    private $usersPath;
    private $productsPath;
    private $ordersPath;
    private $lockFilePath; 
    private $configPath;

    public function __construct()
    {
        $this->usersPath = __DIR__ . '/../../Data/users.php';
        $this->productsPath = __DIR__ . '/../../Data/products.php';
        $this->ordersPath = __DIR__ . '/../../Data/orders.php';
        $this->lockFilePath = __DIR__ . '/../../Data/products.lock'; 
        $this->configPath = __DIR__ . '/../../Data/config.php';
    }

    // --- Data Helpers (Unchanged) ---
    private function getProducts(): array { return require $this->productsPath; }
    private function saveProducts(array $data) { $this->saveData($this->productsPath, $data); }
    private function getUsers(): array { return require $this->usersPath; }
    private function saveUsers(array $data) { $this->saveData($this->usersPath, $data); }
    private function getOrders(): array { return require $this->ordersPath; }
    private function saveOrders(array $data) { $this->saveData($this->ordersPath, $data); }

    private function getConfig(): array { return require $this->configPath; }

    /**
     * Show the checkout page.
     * (Unchanged)
     */
    public function showCheckout(Request $request, Response $response): Response {
        $view = Twig::fromRequest($request);
        $user = $request->getAttribute('user');
        $cart = $user['cart'] ?? [];
        $config = $this->getConfig(); // <-- GET CONFIG

        if (empty($cart)) {
            $routeParser = RouteContext::fromRequest($request)->getRouteParser();
            return $response->withHeader('Location', $routeParser->urlFor('products.index'))->withStatus(302);
        }

        // --- MODIFIED: Use config for totals ---
        $subtotal = 0;
        foreach ($cart as $item) {
            $subtotal += $item['price'] * $item['quantity'];
        }
        $tax = $subtotal * $config['tax_rate'];
        $shipping = $config['shipping_fee'];
        $total = $subtotal + $tax + $shipping;

        return $view->render($response, 'User/checkout.twig', [
            'title' => 'Checkout',
            'user' => $user,
            'cart' => $cart,
            'totals' => [
                'subtotal' => $subtotal,
                'tax' => $tax,
                'shipping' => $shipping, // <-- PASS SHIPPING
                'total' => $total
            ],
            'config' => $config, // <--- THIS IS THE FIX
            'error' => $request->getQueryParams()['error'] ?? null
        ]);
    }

    /**
     * Process the checkout:
     * 1. Validate Address
     * 2. Acquire Lock
     * 3. Validate stock
     * 4. Reduce stock
     * 5. Release Lock
     * 6. Create order
     * 7. Clear cart
     */
    public function processCheckout(Request $request, Response $response): Response {
        $user = $request->getAttribute('user');
        $cart = $user['cart'] ?? [];
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        $config = $this->getConfig();

        // --- 1. NEW: Validate Address ---
        $addr = $user['address'];
        if (empty($addr['street']) || empty($addr['city']) || empty($addr['state']) || empty($addr['postal_code'])) {
            return $response->withHeader('Location', '/checkout?error=address')->withStatus(302);
        }

        // --- 2. NEW: Acquire Lock ---
        // We use a separate lock file to manage concurrency.
        $lockFileHandle = fopen($this->lockFilePath, 'c');
        if (!flock($lockFileHandle, LOCK_EX)) {
            // Failed to get lock, tell user to try again
            return $response->withHeader('Location', '/checkout?error=lock')->withStatus(302);
        }
        
        // --- 3. Validate Stock (Now inside lock) ---
        $allProducts = $this->getProducts(); // Read products *after* getting lock
        $stockErrors = [];

        foreach ($cart as $item) {
            foreach ($allProducts as $product) {
                if ($product['id'] == $item['id']) {
                    if (!empty($product['sizes'])) {
                        foreach ($product['sizes'] as $size) {
                            if ($size['name'] == $item['selectedSize']) {
                                if ($item['quantity'] > $size['stock']) {
                                    $stockErrors[] = "Not enough stock for {$item['name']} ({$size['name']}).";
                                }
                                break;
                            }
                        }
                    } else {
                        if ($item['quantity'] > $product['stock']) {
                            $stockErrors[] = "Not enough stock for {$item['name']}.";
                        }
                    }
                    break;
                }
            }
        }

        if (!empty($stockErrors)) {
            // Stock error found. Release lock and redirect.
            flock($lockFileHandle, LOCK_UN);
            fclose($lockFileHandle);
            return $response->withHeader('Location', '/checkout?error=stock')->withStatus(302);
        }

        // --- 4. Mock Payment (Always Succeeds) ---
        // (This is fine)

        // --- 5. Reduce Stock (Still inside lock) ---
        foreach ($cart as $item) {
            foreach ($allProducts as &$product) {
                if ($product['id'] == $item['id']) {
                    if (!empty($product['sizes'])) {
                        $totalStock = 0;
                        foreach ($product['sizes'] as &$size) {
                            if ($size['name'] == $item['selectedSize']) {
                                $size['stock'] -= $item['quantity'];
                            }
                            $totalStock += $size['stock'];
                        }
                        $product['stock'] = $totalStock;
                    } else {
                        $product['stock'] -= $item['quantity'];
                    }
                    break;
                }
            }
        }
        unset($product); unset($size);
        $this->saveProducts($allProducts); // Save updated stock to disk

        // --- 6. NEW: Release Lock ---
        flock($lockFileHandle, LOCK_UN);
        fclose($lockFileHandle);

        // --- 7. Create Order Record ---
        // (This logic is outside the lock, which is fine)
        $allOrders = $this->getOrders();
        $newOrderId = empty($allOrders) ? 1 : max(array_column($allOrders, 'id')) + 1;

        $subtotal = array_reduce($cart, fn($sum, $item) => $sum + ($item['price'] * $item['quantity']), 0);
        $tax = $subtotal * $config['tax_rate'];
        $shipping = $config['shipping_fee'];
        $total = $subtotal + $tax + $shipping;

        $newOrder = [
            'id' => $newOrderId,
            'user_id' => $user['id'],
            'customer_name' => $user['first_name'] . ' ' . $user['last_name'],
            'address' => $user['address'],
            'date' => date('Y-m-d H:i:s'),
            'status' => 'Processing',
            'items' => $cart,
            'subtotal' => $subtotal,      // <-- SAVE THIS
            'tax' => $tax,              // <-- SAVE THIS
            'shipping_fee' => $shipping,
            'total' => $total
        ];
        $allOrders[] = $newOrder;
        $this->saveOrders($allOrders);

        // --- 8. Clear Cart ---
        $allUsers = $this->getUsers();
        foreach ($allUsers as &$fileUser) {
            if ($fileUser['id'] === $user['id']) {
                $fileUser['cart'] = [];
                break;
            }
        }
        unset($fileUser);
        $this->saveUsers($allUsers);
        $_SESSION['user']['cart'] = [];

        // --- 9. Redirect to Success Page ---
        $successUrl = $routeParser->urlFor('checkout.success') . '?order_id=' . $newOrderId;
        return $response->withHeader('Location', $successUrl)->withStatus(302);
    }

    /**
     * Shows the "Order Successful" page.
     * (Unchanged)
     */
    public function showSuccess(Request $request, Response $response): Response {
        $view = Twig::fromRequest($request);
        $orderId = $request->getQueryParams()['order_id'] ?? null;

        if (!$orderId) {
            $routeParser = RouteContext::fromRequest($request)->getRouteParser();
            return $response->withHeader('Location', $routeParser->urlFor('home'))->withStatus(302);
        }

        return $view->render($response, 'User/checkout-success.twig', [
            'title' => 'Order Successful',
            'order_id' => $orderId
        ]);
    }
}
