<?php
namespace SweetDelights\Mayie\Controllers\Api; // <-- Updated Namespace

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

// Inherit from the new BaseApiController
class ApiSearchController extends BaseApiController
{
    /**
     * Handles the /api/search route for live search
     */
    public function __invoke(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $query = trim($params['q'] ?? '');

        // Don't search for nothing
        if (empty($query)) {
            return $this->respondWithData($response, ['products' => [], 'categories' => []]);
        }
        
        // Use inherited methods to get data
        $allProducts = $this->getProducts();
        $allCategories = $this->getCategories();

        $foundProducts = [];
        $foundCategories = [];
        $appUrl = $_ENV['APP_URL'] ?? '';

        // 1. Search Categories (Limit 3)
        foreach ($allCategories as $cat) {
            if (count($foundCategories) >= 3) break;
            
            if (stripos($cat['name'], $query) !== false) {
                // Add what we need for the link
                $foundCategories[] = [
                    'name' => $cat['name'],
                    'url' => "/products?category=" . urlencode($cat['slug'])
                ];
            }
        }

        // 2. Search Products (Limit 5)
        foreach ($allProducts as $product) {
            if (count($foundProducts) >= 5) break;

            if (stripos($product['name'], $query) !== false) {
                // Add what we need for the link
                $foundProducts[] = [
                    'name' => $product['name'],
                    'image' => $product['image'],
                    'url' => $appUrl . "/products/" . ($product['sku'] ?? $product['id'])
                ];
            }
        }
        
        $results = [
            'products' => $foundProducts,
            'categories' => $foundCategories
        ];

        // Use the new JSON helper
        return $this->respondWithData($response, $results);
    }
}
