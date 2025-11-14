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

        // --- Calculate Discounts ---
        $subtotal = 0;
        $totalDiscount = 0;
        $cartWithDetails = [];
        
        // This query is already correct
        $productStmt = $this->db->prepare("SELECT id, category_id FROM products WHERE sku = ?");

        foreach ($cart as $item) {
            // 1. Find product_id and category_id
            $productStmt->execute([$item['sku']]);
            $product = $productStmt->fetch();
            $productId = $product ? (int)$product['id'] : null;
            $categoryId = ($product && $product['category_id']) ? (int)$product['category_id'] : null;
            
            // 2. Find best active discount
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
        $shipping = $config['shipping_fee'] ?? 0;
        $total = $discountedSubtotal + $tax + $shipping;

        return $view->render($response, 'User/checkout.twig', [
            'title' => 'Checkout',
            'user' => $user,
            'cart' => $cartWithDetails,
            'totals' => [
                'subtotal' => $subtotal,
                'total_discount' => $totalDiscount,
                'tax' => $tax,
                'shipping' => $shipping,
                'total' => $total
            ],
            'config' => $config,
            'error' => $request->getQueryParams()['error'] ?? null
        ]);
    }

    /**
     * Process the checkout
     */
    public function processCheckout(Request $request, Response $response): Response {
        $user = $request->getAttribute('user');
        $cart = $user['cart'] ?? [];
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        $config = $this->getConfig();
        
        $parsedBody = $request->getParsedBody();
        $shippingMethod = $parsedBody['shipping_method'] ?? 'delivery';
        $paymentMethod = $parsedBody['payment_method'] ?? 'card';

        $addr = $user['address'];
        if (empty($addr['street']) || empty($addr['city']) || empty($addr['state']) || empty($addr['postal_code'])) {
            return $response->withHeader('Location', '/checkout?error=address')->withStatus(302);
        }

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

            // --- 6. Reduce Stock ---
            $productStmtForStock->closeCursor();
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

            // --- 7. RECALCULATE Totals (SERVER-SIDE) ---
            
            $subtotal = 0;
            $totalDiscount = 0;
            $cartWithDetails = [];
            
            // --- FIX 1: This query MUST select category_id ---
            $productStmt = $this->db->prepare("SELECT id, category_id, image FROM products WHERE sku = ?");

            foreach ($cart as $item) {
                $productStmt->execute([$item['sku']]);
                $product = $productStmt->fetch();
                $productId = $product ? (int)$product['id'] : null;
                // --- FIX 2: Get category_id for the discount check ---
                $categoryId = ($product && $product['category_id']) ? (int)$product['category_id'] : null;
                $item['image'] = $product ? $product['image'] : $item['image'];

                // --- FIX 3: Call the correct discount function ---
                $discount = $this->findActiveDiscount($productId, $categoryId);

                // 3. Calculate prices
                $item['original_price'] = (float)$item['price'];
                $item['discount_amount'] = 0;
                $item['final_price'] = $item['original_price'];

                if ($discount) {
                    if ($discount['discount_type'] === 'percent') {
                        $item['discount_amount'] = $item['original_price'] * ((float)$discount['discount_value'] / 100);
                    } else {
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

            $orderStmt = $this->db->prepare(
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
                $subtotal,
                $totalDiscount,
                $tax,
                $shipping, 
                $total
            ]);
            $newOrderId = (int)$this->db->lastInsertId();

            // --- 8. Create order_items Records ---
            $itemStmt = $this->db->prepare(
                "INSERT INTO order_items (order_id, sku, product_name, size, price, original_price, discount_amount, quantity, image)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );

            foreach ($cartWithDetails as $item) {
                $itemStmt->execute([
                    $newOrderId,
                    $item['sku'],
                    $item['name'],
                    $item['selectedSize'],
                    $item['final_price'],
                    $item['original_price'],
                    $item['discount_amount'],
                    $item['quantity'],
                    $item['image'] ?? null
                ]);
            }

            // ... (rest of the function: logging, associations, commit, etc. is correct) ...
            
            $orderAfter = $this->getOrderById($newOrderId);
            $this->logEntityChange(
                $user['id'], 'create', 'order', $newOrderId, null, $orderAfter
            );

            $purchasedSkus = array_column($cart, 'sku');
            $this->updateProductAssociations($purchasedSkus);

            $this->db->commit();

        } catch (\Exception $e) {
            $this->db->rollBack();
            error_log('Checkout Failed: ' . $e->getMessage()); 

            if (str_contains($e->getMessage(), 'Stock validation failed')) {
                return $response->withHeader('Location', '/checkout?error=stock')->withStatus(302);
            }
            return $response->withHeader('Location', '/checkout?error=lock')->withStatus(302);
        }

        $this->saveUserKey($user['id'], 'cart', []);
        $_SESSION['user']['cart'] = [];

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

    /**
     * Finds the best active discount for a product.
     * Checks for a product-specific discount first, then for a category discount.
     */
/**
 * Finds the best active discount for a product.
 * Order of priority:
 * 1. Product-level discount
 * 2. Category-level discount
 * 3. Parent category discount (recursive)
 */
private function findActiveDiscount(?int $productId, ?int $categoryId): ?array
{
    // 1. Check product-specific discount
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
        if ($discount) return $discount;
    }

    // 2. Recursively check category + parent categories
    return $this->findCategoryDiscountRecursive($categoryId);
}

/**
 * Recursively checks for active category discounts.
 */
private function findCategoryDiscountRecursive(?int $categoryId): ?array
{
    if (!$categoryId) return null;

    // A. Check discount for this category
    $stmt = $this->db->prepare("
        SELECT * FROM product_discounts
        WHERE category_id = ? AND active = 1
          AND (start_date IS NULL OR start_date <= NOW())
          AND (end_date IS NULL OR end_date >= NOW())
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$categoryId]);
    $discount = $stmt->fetch();

    if ($discount) return $discount;

    // B. Get parent category
    $stmt = $this->db->prepare("SELECT parent_id FROM categories WHERE id = ?");
    $stmt->execute([$categoryId]);
    $parentId = $stmt->fetchColumn();

    if (!$parentId) return null;

    // C. Check parent recursively
    return $this->findCategoryDiscountRecursive($parentId);
}

}