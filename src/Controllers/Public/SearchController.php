<?php
namespace SweetDelights\Mayie\Controllers\Public;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
// use Slim\Views\Twig; // <-- Removed
use SweetDelights\Mayie\Controllers\Admin\BaseAdminController; // <-- Added

// --- FIX: Extend the BaseAdminController ---
class SearchController extends BaseAdminController
{
    // --- FIX: Call the parent constructor to get DB access ---
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Handles the /search route (full page)
     */
    public function index(Request $request, Response $response): Response
    {
        // --- FIX: Use inherited view helper ---
        $view = $this->viewFromRequest($request);
        $params = $request->getQueryParams();
        $query = trim($params['q'] ?? '');

        // --- FIX: Use inherited DB helpers ---
        $allProducts = $this->getProducts();
        $allCategories = $this->getCategories();

        $foundProducts = [];
        $foundCategories = [];
        $appUrl = $_ENV['APP_URL'] ?? '';

        // Only search if the query is not empty
        if (!empty($query)) {
            
            // 1. Search Categories (Full search, no limit)
            foreach ($allCategories as $cat) {
                if (stripos($cat['name'], $query) !== false) {
                    $foundCategories[] = $cat; // Add the full category object
                }
            }

            // 2. Search Products (Full search, no limit)
            // Build a category map for product normalization
            $categoryMap = [];
            foreach ($allCategories as $cat) {
                $categoryMap[$cat['id']] = $cat;
            }

            foreach ($allProducts as $product) {
                $category_name = 'Uncategorized';
                if (isset($product['category_id'], $categoryMap[$product['category_id']])) {
                    $category_name = $categoryMap[$product['category_id']]['name'];
                }

                // Search name, description, category
                $searchableText = $product['name'] . ' ' . $category_name;
                if (!empty($product['description'])) {
                    $searchableText .= ' ' . $product['description'];
                }

                if (stripos($searchableText, $query) !== false) {
                    // It's a match! Normalize this product for display.
                    
                    // --- FIX: Remove JSON decode, sizes are already an array ---
                    /*
                    if (isset($product['sizes']) && is_string($product['sizes'])) {
                        $product['sizes'] = json_decode($product['sizes'], true) ?? [];
                    }
                    */
                    
                    if (!empty($product['sizes'])) {
                        $prices = array_column($product['sizes'], 'price');
                        $product['price'] = min($prices);
                    } else {
                        $product['price'] = $product['price'] ?? 0;
                    }
                    
                    $product['category_name'] = $category_name;
                    $foundProducts[] = $product; // Add the full product object
                }
            }
        }

        // Render the Twig template
        return $view->render($response, 'Public/search-results.twig', [
            'title' => "Search Results for '$query'",
            'query' => $query,
            'products' => $foundProducts,
            'categories' => $foundCategories,
            'app_url' => $appUrl,
        ]);
    }
}
