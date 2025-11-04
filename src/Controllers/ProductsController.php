<?php
namespace SweetDelights\Mayie\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class ProductsController
{
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

    public function index(Request $request, Response $response): Response
    {
        $view = Twig::fromRequest($request);
        $allProducts = require __DIR__ . '/../Data/products.php';
        $allCategories = require __DIR__ . '/../Data/categories.php'; 

        // Build category map for product labeling
        $categoryMap = [];
        foreach ($allCategories as $cat) {
            $categoryMap[$cat['id']] = $cat;
        }

        // Normalize products
        foreach ($allProducts as &$p) {
            if (isset($p['sizes']) && is_string($p['sizes'])) {
                $p['sizes'] = json_decode($p['sizes'], true) ?? [];
            }

            if (!empty($p['sizes'])) {
                $prices = array_column($p['sizes'], 'price');
                $p['price'] = min($prices);
            } else {
                $p['price'] = $p['price'] ?? 0;
            }

            if (isset($p['category_id'], $categoryMap[$p['category_id']])) {
                $p['category_name'] = $categoryMap[$p['category_id']]['name'];
                $p['category_slug'] = $categoryMap[$p['category_id']]['slug'];
            } else {
                $p['category_name'] = 'Uncategorized';
                $p['category_slug'] = 'uncategorized';
            }
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
            'app_url' => $_ENV['APP_URL'],
            'all_categories' => $nestedCategories,
            'selected_category' => $selectedCategory,
            'current_category' => $currentCategory,
        ]);
    }

    // --- Product Detail ---
// --- Product Detail ---
    public function show(Request $request, Response $response, array $args): Response
    {
        $view = Twig::fromRequest($request);
        $products = require __DIR__ . '/../Data/products.php';
        $allCategories = require __DIR__ . '/../Data/categories.php'; 
        
        $skuOrId = $args['sku']; 
        
        $product = null;
        foreach ($products as $p) {
            if (isset($p['sku']) && $p['sku'] === $skuOrId) {
                $product = $p;
                break;
            }
        }

        if (!$product) {
            foreach ($products as $p) {
                if ($p['id'] == $skuOrId) {
                    $product = $p;
                    break;
                }
            }
        }

        if (!$product) {
            return $view->render($response->withStatus(404), 'Public/product-detail.twig', [
                'title' => 'Product Not Found',
                'product' => null,
                'error_message' => 'Sorry, we couldn’t find that product. It might have been removed or renamed.',
                'app_url' => $_ENV['APP_URL'] ?? '',
            ]);
        }

        // --- START BREADCRUMB LOGIC ---
        // Build a map of all categories by their ID
        $categoryMap = [];
        foreach ($allCategories as $cat) {
            $categoryMap[$cat['id']] = $cat; 
        }

        // Check if the product has a category and it exists in our map
        if (isset($product['category_id'], $categoryMap[$product['category_id']])) {
            
            // 1. Get the product's direct category object
            $productCategory = $categoryMap[$product['category_id']];
            
            // 2. Check if this category has a parent
            if (isset($productCategory['parent_id'], $categoryMap[$productCategory['parent_id']])) {
                // 3. If it does, get the parent category object
                $parentCategory = $categoryMap[$productCategory['parent_id']];
                // 4. Attach the parent to the category object
                $productCategory['parent'] = $parentCategory;
            }
            
            // 5. Attach the complete category object (with parent) to the product
            $product['category'] = $productCategory;
            $product['category_name'] = $productCategory['name'];

        } else {
            // Fallback for uncategorized products
            $product['category_name'] = 'Uncategorized';
            $product['category'] = null;
        }
        // --- END BREADCRUMB LOGIC ---


        // --- This is your existing logic for sizes and price ---
        if (isset($product['sizes'])) {
            if (is_string($product['sizes'])) {
                $product['sizes'] = json_decode($product['sizes'], true) ?? [];
            }
        } else {
            $product['sizes'] = [];
        }

        if (is_array($product['sizes']) && count($product['sizes']) > 0) {
            $prices = array_column($product['sizes'], 'price');
            if (!empty($prices)) {
                $product['price'] = min($prices);
            }
        }
        // --- End of existing logic ---

        return $view->render($response, 'Public/product-detail.twig', [
            'title' => $product['name'],
            'product' => $product, // This $product array now contains product.category and product.category.parent
            'app_url' => $_ENV['APP_URL'] ?? '',
        ]);
    }
}
