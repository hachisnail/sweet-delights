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

        // --- NEW: Calculate Discounts ---
        $subtotal = 0;
        $totalDiscount = 0;
        $cartWithDetails = [];
        
        $productStmt = $this->db->prepare("SELECT id, category_id FROM products WHERE sku = ?");

        foreach ($cart as $item) {
            // 1. Find product_id from SKU
            $productStmt->execute([$item['sku']]);
            $product = $productStmt->fetch();
            $productId = $product ? (int)$product['id'] : null;
            $categoryId = ($product && $product['category_id']) ? (int)$product['category_id'] : null;
            $discount = $this->findActiveDiscount($productId, $categoryId);

            $item['discount_type'] = $discount ? $discount['discount_type'] : null;
            $item['discount_value'] = $discount ? (float)$discount['discount_value'] : 0;
            // 3. Calculate prices
            $item['original_price'] = (float)$item['price'];
            $item['discount_amount'] = 0;
            $item['final_price'] = $item['original_price'];

            if ($discount) {
                if ($discount['discount_type'] === 'percent') {
                    $item['discount_amount'] = $item['original_price'] * ((float)$discount['discount_value'] / 100);
                } else { // 'fixed'
                    $item['discount_amount'] = (float)$discount['discount_value'];
                }

                // Ensure discount isn't more than the item price
                if ($item['discount_amount'] > $item['original_price']) {
                    $item['discount_amount'] = $item['original_price'];
                }

                $item['final_price'] = $item['original_price'] - $item['discount_amount'];
            }

            // 4. Add to totals
            $subtotal += $item['original_price'] * $item['quantity'];
            $totalDiscount += $item['discount_amount'] * $item['quantity'];
            
            $cartWithDetails[] = $item;
        }
        
        $discountedSubtotal = $subtotal - $totalDiscount;
        $tax = $discountedSubtotal * ($config['tax_rate'] ?? 0);
        $shipping = $config['shipping_fee'] ?? 0; // Default shipping fee
        $total = $discountedSubtotal + $tax + $shipping;
        // --- END NEW ---

        return $view->render($response, 'User/checkout.twig', [
            'title' => 'Checkout',
            'user' => $user,
            'cart' => $cartWithDetails, // Pass the new detailed cart
            'totals' => [
                'subtotal' => $subtotal,
                'total_discount' => $totalDiscount, // Pass new discount total
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
            $productStmtForStock = $this->db->prepare("SELECT id FROM products WHERE sku = ?");
            foreach ($cart as $item) {
                $productStmtForStock->execute([$item['sku']]);
                $product = $productStmtForStock->fetch();

                if (!$product) {
                    $stockErrors[] = "Product {$item['name']} not found.";
                    continue;
                }

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
            $productStmtForStock->closeCursor(); // Close previous cursor
            $productStmtForStock = $this->db->prepare("SELECT id FROM products WHERE sku = ?");
            foreach ($cart as $item) {
                $productStmtForStock->execute([$item['sku']]);
                $product = $productStmtForStock->fetch();

                $updateStmt = $this->db->prepare(
                    "UPDATE product_sizes SET stock = stock - ? WHERE product_id = ? AND name = ?"
                );
                $updateStmt->execute([$item['quantity'], $product['id'], $item['selectedSize']]);
            }
            $productStmtForStock->closeCursor();

            // --- 7. RECALCULATE Totals & Create Order Record (WITH DISCOUNTS) ---
            
            // --- NEW: Securely recalculate all totals on the server ---
            $subtotal = 0;
            $totalDiscount = 0;
            $cartWithDetails = [];
            
            $productStmt = $this->db->prepare("SELECT id, image FROM products WHERE sku = ?");

            foreach ($cart as $item) {
                // 1. Find product_id from SKU
                $productStmt->execute([$item['sku']]);
                $product = $productStmt->fetch();
                $productId = $product ? (int)$product['id'] : null;
                $item['image'] = $product ? $product['image'] : $item['image']; // Get image now

                // 2. Find active discount
                $discount = $productId ? $this->getActiveDiscountForProduct($productId) : null;

                // 3. Calculate prices
                $item['original_price'] = (float)$item['price'];
                $item['discount_amount'] = 0;
                $item['final_price'] = $item['original_price'];

                if ($discount) {
                    if ($discount['discount_type'] === 'percent') {
                        $item['discount_amount'] = $item['original_price'] * ((float)$discount['discount_value'] / 100);
                    } else { // 'fixed'
                        $item['discount_amount'] = (float)$discount['discount_value'];
                    }
                    if ($item['discount_amount'] > $item['original_price']) {
                        $item['discount_amount'] = $item['original_price'];
                    }
                    $item['final_price'] = $item['original_price'] - $item['discount_amount'];
                }

                // 4. Add to totals
                $subtotal += $item['original_price'] * $item['quantity'];
                $totalDiscount += $item['discount_amount'] * $item['quantity'];
                
                $cartWithDetails[] = $item;
            }
            $productStmt->closeCursor();
            
            $discountedSubtotal = $subtotal - $totalDiscount;
            $tax = $discountedSubtotal * ($config['tax_rate'] ?? 0);
            $shipping = ($shippingMethod === 'pickup') ? 0 : ($config['shipping_fee'] ?? 0);
            $total = $discountedSubtotal + $tax + $shipping;
            // --- END NEW RECALCULATION ---


            $orderStmt = $this->db->prepare(
                // Added total_discount column
                "INSERT INTO orders (user_id, customer_name, address, shipping_method, date, payment_method, status, subtotal, total_discount, tax, shipping_fee, total)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $orderStmt->execute([
                $user['id'],
                $user['first_name'] . ' ' . $user['last_name'],
                json_encode($user['address']),
                $shippingMethod, 
                date('Y-m-d H:i:s'),
                $paymentMethod, 
                'Processing',
                $subtotal,        // Original subtotal
                $totalDiscount,   // Total discount
                $tax,
                $shipping, 
                $total            // Final total
            ]);
            $newOrderId = (int)$this->db->lastInsertId();

            // --- 8. Create order_items Records ---
            
            // Updated to include original_price and discount_amount
            $itemStmt = $this->db->prepare(
                "INSERT INTO order_items (order_id, sku, product_name, size, price, original_price, discount_amount, quantity, image)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );

            // We already have $cartWithDetails from Step 7
            foreach ($cartWithDetails as $item) {
                $itemStmt->execute([
                    $newOrderId,
                    $item['sku'],
                    $item['name'],
                    $item['selectedSize'],
                    $item['final_price'],      // The final (discounted) price
                    $item['original_price'], // The original price
                    $item['discount_amount'],  // The discount applied
                    $item['quantity'],
                    $item['image'] ?? null       // Use image fetched in step 7
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
        // ... (This method remains unchanged) ...
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


    /**
     * --- NEW HELPER FUNCTION ---
     * Finds a single, active discount for a product.
     */
/**
 * Finds the best active discount for a product.
 * Checks for a product-specific discount first, then for a category discount.
 */
/**
 * Finds the best active discount for a product.
 * Checks for a product-specific discount first, then for a category discount.
 */
private function findActiveDiscount(?int $productId, ?int $categoryId): ?array
{
    // 1. Check for product-specific discount
    if ($productId) {
        $stmt = $this->db->prepare("
            SELECT * FROM product_discounts
            WHERE product_id = ? AND active = 1
              AND (start_date IS NULL OR start_date <= NOW())
              AND (end_date IS NULL OR end_date >= NOW())
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$productId]);
        $discount = $stmt->fetch();
        if ($discount) {
            return $discount;
        }
    }

    // 2. Check for category-specific discount
    if ($categoryId) {
        $stmt = $this->db->prepare("
            SELECT * FROM product_discounts
            WHERE category_id = ? AND active = 1
              AND (start_date IS NULL OR start_date <= NOW())
              AND (end_date IS NULL OR end_date >= NOW())
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$categoryId]);
        $discount = $stmt->fetch();
        if ($discount) {
            return $discount;
        }
    }

    return null;
}
}