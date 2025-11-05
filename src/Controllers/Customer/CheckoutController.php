<?php
namespace SweetDelights\Mayie\Controllers\Customer;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
// use Slim\Views\Twig; // <-- Removed
// use SweetDelights\Mayie\Controllers\BaseDataController; // <-- Removed
use SweetDelights\Mayie\Controllers\Admin\BaseAdminController; // <-- Added
use Slim\Routing\RouteContext;


// --- FIX: Extend the BaseAdminController ---
class CheckoutController extends BaseAdminController {

    // --- FIX: All data-path properties and file-helpers removed ---
    // (e.g., $usersPath, $productsPath, getProducts(), saveProducts(), etc.)
    // They are all now inherited from BaseAdminController.

    public function __construct()
    {
        // --- FIX: Constructor just calls the parent to get DB access ---
        parent::__construct();
    }

    /**
     * Show the checkout page.
     * (Unchanged)
     */
    public function showCheckout(Request $request, Response $response): Response {
        // --- FIX: Use inherited view helper ---
        $view = $this->viewFromRequest($request);
        $user = $request->getAttribute('user');
        $cart = $user['cart'] ?? [];
        
        // --- FIX: This now calls the inherited getConfig() from the DB ---
        $config = $this->getConfig(); 

        if (empty($cart)) {
            $routeParser = RouteContext::fromRequest($request)->getRouteParser();
            return $response->withHeader('Location', $routeParser->urlFor('products.index'))->withStatus(302);
        }

        // --- MODIFIED: Use config for totals ---
        $subtotal = 0;
        foreach ($cart as $item) {
            $subtotal += $item['price'] * $item['quantity'];
        }
        $tax = $subtotal * ($config['tax_rate'] ?? 0);
        $shipping = $config['shipping_fee'] ?? 0;
        $total = $subtotal + $tax + $shipping;

        return $view->render($response, 'User/checkout.twig', [
            'title' => 'Checkout',
            'user' => $user,
            'cart' => $cart,
            'totals' => [
                'subtotal' => $subtotal,
                'tax' => $tax,
                'shipping' => $shipping,
                'total' => $total
            ],
            'config' => $config,
            'error' => $request->getQueryParams()['error'] ?? null
        ]);
    }

    /**
     * Process the checkout:
     * 1. Validate Address
     * 2. Start DB Transaction (replaces file lock)
     * 3. Validate stock (with row-level locking)
     * 4. Reduce stock
     * 5. Create order
     * 6. Create order_items
     * 7. Log Activity
     * 8. Commit Transaction
     * 9. Clear cart
     */
    public function processCheckout(Request $request, Response $response): Response {
        $user = $request->getAttribute('user');
        $cart = $user['cart'] ?? [];
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        $config = $this->getConfig();

        // --- 1. Validate Address (Unchanged) ---
        $addr = $user['address'];
        if (empty($addr['street']) || empty($addr['city']) || empty($addr['state']) || empty($addr['postal_code'])) {
            return $response->withHeader('Location', '/checkout?error=address')->withStatus(302);
        }

        // --- 2. NEW: Start Database Transaction (Replaces flock) ---
        $newOrderId = null; // Initialize here
        try {
            $this->db->beginTransaction();

            // --- 3. Validate Stock (Now inside transaction) ---
            $stockErrors = [];
            foreach ($cart as $item) {
                // Find the product_id from the SKU
                $productStmt = $this->db->prepare("SELECT id FROM products WHERE sku = ?");
                $productStmt->execute([$item['sku']]);
                $product = $productStmt->fetch();

                if (!$product) {
                    $stockErrors[] = "Product {$item['name']} not found.";
                    continue;
                }

                // Find the specific size and *lock the row* for this transaction
                $sizeStmt = $this->db->prepare(
                    "SELECT stock FROM product_sizes WHERE product_id = ? AND name = ? FOR UPDATE"
                );
                $sizeStmt->execute([$product['id'], $item['selectedSize']]);
                $size = $sizeStmt->fetch();

                if (!$size || $item['quantity'] > $size['stock']) {
                    $stockErrors[] = "Not enough stock for {$item['name']} ({$item['selectedSize']}).";
                }
            }

            if (!empty($stockErrors)) {
                // This will trigger the catch block and roll back the transaction
                throw new \Exception('Stock validation failed: ' . implode(', ', $stockErrors));
            }

            // --- 4. Mock Payment (Always Succeeds) ---
            // (No change)

            // --- 5. Reduce Stock (Still inside transaction) ---
            foreach ($cart as $item) {
                // We need the product_id again to be safe
                $productStmt = $this->db->prepare("SELECT id FROM products WHERE sku = ?");
                $productStmt->execute([$item['sku']]);
                $product = $productStmt->fetch();

                $updateStmt = $this->db->prepare(
                    "UPDATE product_sizes SET stock = stock - ? WHERE product_id = ? AND name = ?"
                );
                $updateStmt->execute([$item['quantity'], $product['id'], $item['selectedSize']]);
            }

            // --- 6. Create Order Record ---
            $subtotal = array_reduce($cart, fn($sum, $item) => $sum + ($item['price'] * $item['quantity']), 0);
            $tax = $subtotal * ($config['tax_rate'] ?? 0);
            $shipping = $config['shipping_fee'] ?? 0;
            $total = $subtotal + $tax + $shipping;

            $orderStmt = $this->db->prepare(
                "INSERT INTO orders (user_id, customer_name, address, date, status, subtotal, tax, shipping_fee, total)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $orderStmt->execute([
                $user['id'],
                $user['first_name'] . ' ' . $user['last_name'],
                json_encode($user['address']), // Store address as JSON
                date('Y-m-d H:i:s'),
                'Processing',
                $subtotal,
                $tax,
                $shipping,
                $total
            ]);
            // --- FIX: Get ID from database, don't calculate it ---
            $newOrderId = (int)$this->db->lastInsertId();

            // --- 7. Create order_items Records ---
            $itemStmt = $this->db->prepare(
                "INSERT INTO order_items (order_id, sku, product_name, size, price, quantity, image)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );

            $productImageStmt = $this->db->prepare("SELECT image FROM products WHERE sku = ?");

            foreach ($cart as $item) {

                $productImageStmt->execute([$item['sku']]);
                $productData = $productImageStmt->fetch();
                $productImage = $productData ? $productData['image'] : null;

                $itemStmt->execute([
                    $newOrderId,
                    $item['sku'],
                    $item['name'],
                    $item['selectedSize'],
                    $item['price'],
                    $item['quantity'],
                    $productImage
                ]);
            }
            
            // --- 8. LOG ACTIVITY (Inside transaction) ---
            $orderAfter = $this->getOrderById($newOrderId);
            $this->logEntityChange(
                $user['id'],    // The customer is the actor
                'create',       // actionType
                'order',        // entityType
                $newOrderId,    // entityId
                null,           // before
                $orderAfter    // after
            );
            // --- END LOG ---

            // --- 9. NEW: Commit Transaction (Releases all locks) ---
            $this->db->commit();

        } catch (\Exception $e) {
            // --- NEW: Rollback Transaction on *any* failure ---
            $this->db->rollBack();
            error_log('Checkout Failed: ' . $e->getMessage()); // Log the error

            // Redirect back with an error
            if (str_contains($e->getMessage(), 'Stock validation failed')) {
                return $response->withHeader('Location', '/checkout?error=stock')->withStatus(302);
            }
            // Generic error for lock failure or other DB issues
            return $response->withHeader('Location', '/checkout?error=lock')->withStatus(302);
        }

        // --- 10. Clear Cart (Only happens on success) ---
        // --- FIX: Use the inherited saveUserKey helper ---
        $this->saveUserKey($user['id'], 'cart', []);
        $_SESSION['user']['cart'] = []; // Update session immediately

        // --- 11. Redirect to Success Page ---
        // --- FIX: Corrected syntax error ( _ -> . ) ---
        $successUrl = $routeParser->urlFor('checkout.success') . '?order_id=' . $newOrderId;
        return $response->withHeader('Location', $successUrl)->withStatus(302);
    }

    /**
     * Shows the "Order Successful" page.
     * (Unchanged)
     */
    public function showSuccess(Request $request, Response $response): Response {
        // --- FIX: Use inherited view helper ---
        $view = $this->viewFromRequest($request);
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