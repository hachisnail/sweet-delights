<?php
namespace SweetDelights\Mayie\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class ProductsController
{
    // (buildTree and getCategoryWithDescendants are unchanged)
// ... (Omitted for brevity) ...
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

    public function index(Request $request, Response $response): Response
    {
        $view = Twig::fromRequest($request);
        $allProducts = require __DIR__ . '/../Data/products.php';
        $allCategories = require __DIR__ . '/../Data/categories.php'; 

        $categoryMap = [];
        foreach ($allCategories as $cat) {
            $categoryMap[$cat['id']] = $cat;
        }

        foreach ($allProducts as &$p) {
            // Decode string sizes (legacy support)
            if (isset($p['sizes']) && is_string($p['sizes'])) {
                $p['sizes'] = json_decode($p['sizes'], true) ?? [];
            }

            // ✅ --- START PRICE REFACTOR ---
            // Check if sizes is an array and has items
            if (isset($p['sizes']) && is_array($p['sizes']) && count($p['sizes']) > 0) {
                // Get all prices from the sizes
                $prices = array_column($p['sizes'], 'price');
                if (!empty($prices)) {
                    // Set the top-level price to the minimum
                    $p['price'] = min($prices);
                } else {
                    // Fallback if sizes exist but have no prices (data error)
                    $p['price'] = $p['price'] ?? 0;
                }
            } else {
                // No sizes, just use the top-level price
                $p['price'] = $p['price'] ?? 0;
            }
            // ✅ --- END PRICE REFACTOR ---
            
            // Add category name (safer way)
            if (isset($p['category_id']) && isset($categoryMap[$p['category_id']])) {
                $p['category_name'] = $categoryMap[$p['category_id']]['name'];
                $p['category_slug'] = $categoryMap[$p['category_id']]['slug'];
            } else {
                $p['category_name'] = 'Uncategorized';
                $p['category_slug'] = 'uncategorized';
            }
        }
        unset($p);

        // (Rest of the function is unchanged)
        $params = $request->getQueryParams();
        $categorySlug = $params['category'] ?? null;
        
        $productsToDisplay = $allProducts;
        $selectedCategory = null;

        if ($categorySlug) {
            foreach ($allCategories as $cat) {
                if ($cat['slug'] === $categorySlug) {
                    $selectedCategory = $cat;
                    break;
                }
            }
            
            if ($selectedCategory) {
                $categoryIdsToFilter = $this->getCategoryWithDescendants($allCategories, $selectedCategory['id']);
                
                $productsToDisplay = array_filter($allProducts, fn($p) => 
                    isset($p['category_id']) && in_array($p['category_id'], $categoryIdsToFilter)
                );
            } else {
                $productsToDisplay = []; 
            }
        }

        $nestedCategories = $this->buildTree($allCategories);

        return $view->render($response, 'Public/products.twig', [
            'title' => 'Our Products - FlourEver',
            'products' => $productsToDisplay,
            'app_url' => $_ENV['APP_URL'],
            'all_categories' => $nestedCategories, 
            'selected_category' => $selectedCategory,
        ]);
    }


    public function show(Request $request, Response $response, array $args): Response
    {
        // (No change from your last version)
        $view = Twig::fromRequest($request);
        $products = require __DIR__ . '/../Data/products.php';
        $allCategories = require __DIR__ . '/../Data/categories.php'; 
        
        $id = (int) $args['id'];
        
        $product = null;
        foreach ($products as $p) {
            if ($p['id'] == $id) {
                $product = $p;
                break;
            }
        }

        if (!$product) {
            $response->getBody()->write("Product not found");
            return $response->withStatus(404);
        }

        $categoryMap = [];
        foreach ($allCategories as $cat) {
            $categoryMap[$cat['id']] = $cat; 
        }

        if (isset($product['category_id']) && isset($categoryMap[$product['category_id']])) {
            $product['category_name'] = $categoryMap[$product['category_id']]['name'];
        } else {
            $product['category_name'] = 'Uncategorized';
        }

        if (isset($product['sizes'])) {
            if (is_string($product['sizes'])) {
                $product['sizes'] = json_decode($product['sizes'], true) ?? [];
            }
        } else {
            $product['sizes'] = [];
        }
        
        // ✅ Re-find and set base price just in case data is out of sync
        if (is_array($product['sizes']) && count($product['sizes']) > 0) {
             $prices = array_column($product['sizes'], 'price');
             if (!empty($prices)) {
                $product['price'] = min($prices);
             }
        }

        return $view->render($response, 'Public/product-detail.twig', [
            'title' => $product['name'],
            'product' => $product,
            'app_url' => $_ENV['APP_URL'] ?? '',
        ]);
    }
}

