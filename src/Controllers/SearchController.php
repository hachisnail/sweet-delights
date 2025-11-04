<?php
namespace SweetDelights\Mayie\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig; // <-- Required for rendering the page

class SearchController
{
    /**
     * Handles the /search route (full page)
     */
    public function index(Request $request, Response $response): Response
    {
        $view = Twig::fromRequest($request);
        $params = $request->getQueryParams();
        $query = trim($params['q'] ?? '');

        $allProducts = require __DIR__ . '/../Data/products.php';
        $allCategories = require __DIR__ . '/../Data/categories.php';

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
                    
                    if (isset($product['sizes']) && is_string($product['sizes'])) {
                        $product['sizes'] = json_decode($product['sizes'], true) ?? [];
                    }
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

