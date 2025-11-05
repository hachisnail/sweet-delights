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

    public function index(Request $request, Response $response): Response
    {
        // --- FIX: Use inherited view helper ---
        $view = $this->viewFromRequest($request);
        
        // --- FIX: Use inherited DB helpers ---
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
        ]);
    }

    // --- Product Detail ---
    public function show(Request $request, Response $response, array $args): Response
    {
        // --- FIX: Use inherited view helper ---
        $view = $this->viewFromRequest($request);
        
        // --- FIX: Use inherited DB helpers ---
        $allProducts = $this->getProducts();
        $allCategories = $this->getProducts();
        
        $skuOrId = $args['sku']; 
        
        $product = null;
        foreach ($allProducts as $p) {
            // --- NEW: Add 'is_listed' check ---
            if (($p['sku'] === $skuOrId || $p['id'] == $skuOrId) && $p['is_listed'] == 1) {
                $product = $p;
                break;
            }
        }
        
        // --- This logic is now combined above ---
        // if (!$product) { ... }

        if (!$product) {
            return $view->render($response->withStatus(404), 'Public/product-detail.twig', [
                'title' => 'Product Not Found',
                'product' => null,
                'error_message' => 'Sorry, we couldn’t find that product. It might have been unlisted or removed.',
                'app_url' => $_ENV['APP_URL'] ?? '',
            ]);
        }

        $categoryMap = [];
        foreach ($allCategories as $cat) {
            $categoryMap[$cat['id']] = $cat; 
        }

        if (isset($product['category_id']) && $product['category_id'] !== null && isset($categoryMap[$product['category_id']])) {
            
            $productCategory = $categoryMap[$product['category_id']];
            
            if (isset($productCategory['parent_id']) && $productCategory['parent_id'] !== null && isset($categoryMap[$productCategory['parent_id']])) {
                $parentCategory = $categoryMap[$productCategory['parent_id']];
                $productCategory['parent'] = $parentCategory;
            }
            
            $product['category'] = $productCategory;
            $product['category_name'] = $productCategory['name'];

        } else {
            $product['category_name'] = 'Uncategorized';
            $product['category'] = null;
        }



        return $view->render($response, 'Public/product-detail.twig', [
            'title' => $product['name'],
            'product' => $product, 
            'app_url' => $_ENV['APP_URL'] ?? '',
        ]);
    }
}