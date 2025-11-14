<?php
namespace SweetDelights\Mayie\Controllers\Public;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SweetDelights\Mayie\Controllers\Admin\BaseAdminController;

class ProductsController extends BaseAdminController
{
    public function __construct()
    {
        parent::__construct();
    }


    private function buildTree(array $elements, $parentId = null): array
    {
        $branch = [];
        foreach ($elements as $element) {
            if ($element['parent_id'] == $parentId) {
                $children = $this->buildTree($elements, $element['id']);
                if ($children) {
                    $element['children'] = $children;
                }
                $branch[] = $element;
            }
        }
        return $branch;
    }

    private function getCategoryWithDescendants(array $allCategories, int $parentId): array
    {
        $ids = [$parentId];
        foreach ($allCategories as $category) {
            if ($category['parent_id'] == $parentId) {
                $ids = array_merge($ids, $this->getCategoryWithDescendants($allCategories, $category['id']));
            }
        }
        return $ids;
    }

    private function findCategoryWithChildren(array $tree, string $slug): ?array
    {
        foreach ($tree as $node) {
            if ($node['slug'] === $slug) {
                return $node;
            }
            if (isset($node['children'])) {
                $found = $this->findCategoryWithChildren($node['children'], $slug);
                if ($found) return $found;
            }
        }
        return null;
    }

    /**
     * Get all active, listed discounts for the banner
     */
private function getAllActiveDiscounts(): array
{
    $stmt = $this->db->query("
        (
            -- Get all active product discounts
            SELECT pd.*, p.name as item_name, p.sku as item_sku, 
                   p.image as item_image, 'product' as discount_scope
            FROM product_discounts pd
            JOIN products p ON p.id = pd.product_id
            WHERE pd.active = 1
              AND pd.product_id IS NOT NULL
              AND p.is_listed = 1
              AND (pd.start_date IS NULL OR pd.start_date <= NOW())
              AND (pd.end_date IS NULL OR pd.end_date >= NOW())
        )
        UNION
        (
            -- Get all active category discounts
            SELECT pd.*, c.name as item_name, c.slug as item_sku, 
                   c.image as item_image, 'category' as discount_scope
            FROM product_discounts pd
            JOIN categories c ON c.id = pd.category_id
            WHERE pd.active = 1
              AND pd.category_id IS NOT NULL
              AND (pd.start_date IS NULL OR pd.start_date <= NOW())
              AND (pd.end_date IS NULL OR pd.end_date >= NOW())
        )
        ORDER BY end_date ASC, id DESC
    ");
    return $stmt->fetchAll() ?: [];
}
    /**
     * Finds a single, active discount for a product.
     */
    
    
    /**
     * Finds the best active discount for a product.
     * Checks for a product-specific discount first, then for a category discount.
     */
    private function findActiveDiscountForProduct(array $product): ?array
    {
        if (empty($product['id'])) {
            return null;
        }

        // 1. Check for a product-specific discount first
        $stmt = $this->db->prepare("
            SELECT * FROM product_discounts
            WHERE product_id = ? AND active = 1
              AND (start_date IS NULL OR start_date <= NOW())
              AND (end_date IS NULL OR end_date >= NOW())
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$product['id']]);
        $discount = $stmt->fetch();

        if ($discount) {
            return $discount; // Found product-specific discount
        }

        // 2. If no product discount, check for a category discount
        if (empty($product['category_id'])) {
            return null; // No category to check
        }

        $stmt = $this->db->prepare("
            SELECT * FROM product_discounts
            WHERE category_id = ? AND active = 1
              AND (start_date IS NULL OR start_date <= NOW())
              AND (end_date IS NULL OR end_date >= NOW())
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$product['category_id']]);
        $discount = $stmt->fetch();

        return $discount ?: null; // Return category discount or null
    }

    /**
     * Applies discount logic to a product array (and its sizes).
     * Returns the modified product array.
     */
    private function applyDiscountToProduct(array $product): array
    {
        if (empty($product['id'])) {
            return $product; // Can't get discount without ID
        }

       $discount = $this->findActiveDiscountForProduct($product);

        // Set defaults for base product
        $product['original_price'] = (float)$product['price'];
        $product['discount_type'] = null;
        $product['discount_value'] = 0;
        $product['discount_amount'] = 0;
        $basePrice = (float)$product['price'];

        if ($discount) {
            $discountAmount = 0;
            if ($discount['discount_type'] === 'percent') {
                $discountAmount = $basePrice * ((float)$discount['discount_value'] / 100);
            } else {
                $discountAmount = (float)$discount['discount_value'];
            }
            if ($discountAmount > $basePrice) $discountAmount = $basePrice;
            
            $product['price'] = $basePrice - $discountAmount; // 'price' is now the final, discounted price
            $product['discount_type'] = $discount['discount_type'];
            $product['discount_value'] = (float)$discount['discount_value']; // The raw value (e.g., 10 or 50)
            $product['discount_amount'] = $discountAmount;
        }

        // --- NOW, APPLY TO SIZES ---
        // (Assuming $product['sizes'] is an array of size arrays)
        if (isset($product['sizes']) && is_array($product['sizes'])) {
            foreach ($product['sizes'] as &$size) {
                // Set defaults
                $size['original_price'] = (float)$size['price'];
                $size['discount_type'] = null;
                $size['discount_value'] = 0;
                $size['discount_amount'] = 0;
                $sizePrice = (float)$size['price'];

                if ($discount) { // Apply the *same* product discount to all sizes
                    $sizeDiscountAmount = 0;
                    if ($discount['discount_type'] === 'percent') {
                        $sizeDiscountAmount = $sizePrice * ((float)$discount['discount_value'] / 100);
                    } else {
                        $sizeDiscountAmount = (float)$discount['discount_value'];
                    }
                    if ($sizeDiscountAmount > $sizePrice) $sizeDiscountAmount = $sizePrice;

                    $size['price'] = $sizePrice - $sizeDiscountAmount; // Final price for the size
                    $size['discount_type'] = $discount['discount_type'];
                    $size['discount_value'] = (float)$discount['discount_value'];
                    $size['discount_amount'] = $sizeDiscountAmount;
                }
            }
            unset($size); // Good practice
        }
        
        return $product;
    }

    public function index(Request $request, Response $response): Response
    {
        // --- FIX: Use inherited view helper ---
        $view = $this->viewFromRequest($request);
        
        // --- FIX: Use inherited DB helpers ---
        $activeDiscounts = $this->getAllActiveDiscounts();
        $allProducts = $this->getProducts();
        $allCategories = $this->getCategories(); 

        $allProducts = array_filter($allProducts, function($p) {
            return isset($p['is_listed']) && $p['is_listed'] == 1;
        });
        // --- END NEW ---

        // Build category map for product labeling
        $categoryMap = [];
        foreach ($allCategories as $cat) {
            $categoryMap[$cat['id']] = $cat;
        }

        // Normalize products
        foreach ($allProducts as &$p) {


            if (isset($p['category_id'], $categoryMap[$p['category_id']])) {
                $p['category_name'] = $categoryMap[$p['category_id']]['name'];
                $p['category_slug'] = $categoryMap[$p['category_id']]['slug'];
            } else {
                $p['category_name'] = 'Uncategorized';
                $p['category_slug'] = 'uncategorized';
            }
        }
        unset($p);

        // --- NEW: Apply discount logic to all products ---
        foreach ($allProducts as &$p) {
            $p = $this->applyDiscountToProduct($p);
        }
        unset($p);

        // Build nested category tree
        $nestedCategories = $this->buildTree($allCategories);

        // Handle filtering
        $params = $request->getQueryParams();
        $categorySlug = $params['category'] ?? null;

        $productsToDisplay = $allProducts;
        $selectedCategory = null;
        $currentCategory = null;

        if ($categorySlug) {
            // Find category in flat list (for filtering)
            foreach ($allCategories as $cat) {
                if ($cat['slug'] === $categorySlug) {
                    $currentCategory = $cat;
                    break;
                }
            }

            if ($currentCategory) {
                // Get all descendants for filtering
                $categoryIdsToFilter = $this->getCategoryWithDescendants($allCategories, $currentCategory['id']);

                $productsToDisplay = array_filter($allProducts, fn($p) => 
                    isset($p['category_id']) && in_array($p['category_id'], $categoryIdsToFilter)
                );

                // Find the selected category (could be parent or child)
                $selectedCategory = $this->findCategoryWithChildren($nestedCategories, $categorySlug);
                
                // If selected is a subcategory, show its parent’s subcategories
                if (!$selectedCategory) {
                    foreach ($nestedCategories as $parent) {
                        if (isset($parent['children'])) {
                            foreach ($parent['children'] as $child) {
                                if ($child['slug'] === $categorySlug) {
                                    $selectedCategory = $parent;
                                    break 2;
                                }
                            }
                        }
                    }
                }
            } else {
                $productsToDisplay = [];
            }
        }

        return $view->render($response, 'Public/products.twig', [
            'title' => 'Our Products - FlourEver',
            'products' => $productsToDisplay,
            'app_url' => $_ENV['APP_URL'] ?? '', 
            'all_categories' => $nestedCategories,
            'selected_category' => $selectedCategory,
            'current_category' => $currentCategory,
            'active_discounts' => $activeDiscounts,
        ]);
    }

    private function getRelatedProductsBySKU(string $sku, int $limit = 4): array
    {
        // Make sure limit is always safe integer
        $limit = max(1, (int)$limit);

        $sql = "
            SELECT p.*
            FROM products p
            INNER JOIN product_associations pa 
                ON (
                    (p.sku = pa.product_sku_2 AND pa.product_sku_1 = :sku1)
                    OR
                    (p.sku = pa.product_sku_1 AND pa.product_sku_2 = :sku2)
                )
            WHERE p.sku != :sku3
            ORDER BY pa.support_count DESC
            LIMIT $limit
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':sku1', $sku, \PDO::PARAM_STR);
        $stmt->bindValue(':sku2', $sku, \PDO::PARAM_STR);
        $stmt->bindValue(':sku3', $sku, \PDO::PARAM_STR);

        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }




        // --- Product Detail ---
    public function show(Request $request, Response $response, array $args): Response
    {
    $view = $this->viewFromRequest($request);
    $allProducts = $this->getProducts();
    $allCategories = $this->getCategories();


    $skuOrId = $args['sku'];
    $product = null;


    foreach ($allProducts as $p) {
    if (($p['sku'] === $skuOrId || $p['id'] == $skuOrId) && $p['is_listed'] == 1) {
    $product = $p;
    break;
    }
    }

        // --- NEW: Apply discount logic to the found product ---
    if ($product) {
        $product = $this->applyDiscountToProduct($product);
    }


    if (!$product) {
    return $view->render($response->withStatus(404), 'Public/product-detail.twig', [
    'title' => 'Product Not Found',
    'product' => null,
    'error_message' => 'Sorry, we couldn’t find that product. It might have been unlisted or removed.',
    'app_url' => $_ENV['APP_URL'] ?? '',
    ]);
    }


    // --- Attach category info ---
    $categoryMap = [];
    foreach ($allCategories as $cat) {
    $categoryMap[$cat['id']] = $cat;
    }


    if (isset($product['category_id']) && $product['category_id'] !== null && isset($categoryMap[$product['category_id']])) {
    $productCategory = $categoryMap[$product['category_id']];
    if (isset($productCategory['parent_id']) && $productCategory['parent_id'] !== null && isset($categoryMap[$productCategory['parent_id']])) {
    $productCategory['parent'] = $categoryMap[$productCategory['parent_id']];
    }
    $product['category'] = $productCategory;
    $product['category_name'] = $productCategory['name'];
    } else {
    $product['category_name'] = 'Uncategorized';
    $product['category'] = null;
    }


    // --- Fetch related products ---
    $relatedProducts = $this->getRelatedProductsBySKU($product['sku']);
    // --- NEW: Apply discount logic to related products ---
    foreach ($relatedProducts as &$related) {
        $related = $this->applyDiscountToProduct($related);
    }
    unset($related);

    return $view->render($response, 'Public/product-detail.twig', [
    'title' => $product['name'],
    'product' => $product,
    'related_products' => $relatedProducts,
    'app_url' => $_ENV['APP_URL'] ?? '',
    ]);
    }

}