<?php
namespace SweetDelights\Mayie\Controllers\Api; // <-- Updated Namespace

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ApiSearchController extends BaseApiController
{
    /**
     * Handles the /api/search route for live search
     */
    public function __invoke(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $query = trim($params['q'] ?? '');

        if (empty($query)) {
            return $this->respondWithData($response, ['products' => [], 'categories' => []]);
        }
        
        $allProducts = $this->getProducts();
        $allCategories = $this->getCategories();

        $foundProducts = [];
        $foundCategories = [];
        $appUrl = $_ENV['APP_URL'] ?? '';

        foreach ($allCategories as $cat) {
            if (count($foundCategories) >= 3) break;
            
            if (stripos($cat['name'], $query) !== false) {
                $foundCategories[] = [
                    'name' => $cat['name'],
                    'url' => "/products?category=" . urlencode($cat['slug'])
                ];
            }
        }

        foreach ($allProducts as $product) {
            if (count($foundProducts) >= 5) break;

            if (stripos($product['name'], $query) !== false) {
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

        return $this->respondWithData($response, $results);
    }
}
