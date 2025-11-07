<?php
namespace SweetDelights\Mayie\Controllers\Customer;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SweetDelights\Mayie\Controllers\Admin\BaseAdminController;
use Slim\Routing\RouteContext;


class CheckoutController extends BaseAdminController {

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Show the checkout page.
     */
    public function showCheckout(Request $request, Response $response): Response {
        $view = $this->viewFromRequest($request);
        $user = $request->getAttribute('user');
        $cart = $user['cart'] ?? [];
        
        $config = $this->getConfig(); 

        if (empty($cart)) {
            $routeParser = RouteContext::fromRequest($request)->getRouteParser();
            return $response->withHeader('Location', $routeParser->urlFor('products.index'))->withStatus(302);
        }

        $subtotal = 0;
        foreach ($cart as $item) {
            $subtotal += $item['price'] * $item['quantity'];
        }
        $tax = $subtotal * ($config['tax_rate'] ?? 0);
        $shipping = $config['shipping_fee'] ?? 0; // Default shipping fee
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
     * (Steps omitted for brevity)
     */
    public function processCheckout(Request $request, Response $response): Response {
        $user = $request->getAttribute('user');
        $cart = $user['cart'] ?? [];
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        $config = $this->getConfig();
        
        // --- 1. Get Form Data ---
        $parsedBody = $request->getParsedBody();
        $shippingMethod = $parsedBody['shipping_method'] ?? 'delivery';
        $paymentMethod = $parsedBody['payment_method'] ?? 'card';

        // --- 2. Validate Address ---
        $addr = $user['address'];
        if (empty($addr['street']) || empty($addr['city']) || empty($addr['state']) || empty($addr['postal_code'])) {
            return $response->withHeader('Location', '/checkout?error=address')->withStatus(302);
        }

        // --- 3. Start Database Transaction ---
        $newOrderId = null;
        try {
            $this->db->beginTransaction();

            // --- 4. Validate Stock ---
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
                throw new \Exception('Stock validation failed: ' . implode(', ', $stockErrors));
            }

            // --- 5. Mock Payment Validation (if 'card') ---
            // (Frontend JS handles 'required' attributes)

            // --- 6. Reduce Stock ---
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

            // --- 7. RECALCULATE Totals & Create Order Record ---
            $subtotal = array_reduce($cart, fn($sum, $item) => $sum + ($item['price'] * $item['quantity']), 0);
            $tax = $subtotal * ($config['tax_rate'] ?? 0);
            
            $shipping = ($shippingMethod === 'pickup') ? 0 : ($config['shipping_fee'] ?? 0);
            
            $total = $subtotal + $tax + $shipping;

            $orderStmt = $this->db->prepare(
                "INSERT INTO orders (user_id, customer_name, address, shipping_method, date, payment_method, status, subtotal, tax, shipping_fee, total)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $orderStmt->execute([
                $user['id'],
                $user['first_name'] . ' ' . $user['last_name'],
                json_encode($user['address']),
                $shippingMethod, 
                date('Y-m-d H:i:s'),
                $paymentMethod, 
                'Processing',
                $subtotal,
                $tax,
                $shipping, 
                $total
            ]);
            $newOrderId = (int)$this->db->lastInsertId();

            // --- 8. Create order_items Records ---
            
            // --- FIX 1: Changed $this.db to $this->db ---
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

            // --- 9. LOG ACTIVITY ---
            $orderAfter = $this->getOrderById($newOrderId);
            $this->logEntityChange(
                $user['id'],
                'create',
                'order',
                $newOrderId,
                null,
                $orderAfter
            );

            // --- 10. UPDATE MARKET-BASKET ASSOCIATIONS ---
            $purchasedSkus = array_column($cart, 'sku');
            $this->updateProductAssociations($purchasedSkus);

            // --- 11. Commit Transaction ---
            $this->db->commit();

        } catch (\Exception $e) {
            // --- Rollback Transaction on *any* failure ---
            $this->db->rollBack();
            error_log('Checkout Failed: ' . $e->getMessage()); 

            if (str_contains($e->getMessage(), 'Stock validation failed')) {
                return $response->withHeader('Location', '/checkout?error=stock')->withStatus(302);
            }
            return $response->withHeader('Location', '/checkout?error=lock')->withStatus(302);
        }

        // --- 12. Clear Cart (Only happens on success) ---
        
        // --- FIX 2: Changed $this.saveUserKey to $this->saveUserKey ---
        $this->saveUserKey($user['id'], 'cart', []);
        $_SESSION['user']['cart'] = []; // Update session immediately

        // --- 13. Redirect to Success Page ---
        $successUrl = $routeParser->urlFor('checkout.success') . '?order_id=' . $newOrderId;
        return $response->withHeader('Location', $successUrl)->withStatus(302);
    }

    /**
     * Shows the "Order Successful" page.
     */
    public function showSuccess(Request $request, Response $response): Response {
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