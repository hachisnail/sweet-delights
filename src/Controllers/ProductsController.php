<?php
namespace SweetDelights\Mayie\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

// Controller to handle product listing and details
// to do: implement inventory management (iom), search, filtering, and pagination, product management (pm)

class ProductsController
{
        public function index(Request $request, Response $response): Response
        {
            $view = Twig::fromRequest($request);
            $products = require __DIR__ . '/../Data/products.php';

            foreach ($products as &$p) {
                if (isset($p['sizes']) && is_string($p['sizes'])) {
                    if (str_starts_with(trim($p['sizes']), '[')) {
                        $p['sizes'] = json_decode($p['sizes'], true);
                    } else {
                        $p['sizes'] = array_map('trim', explode(',', $p['sizes']));
                    }
                }
            }
            unset($p);

            $params = $request->getQueryParams();
            $category = $params['category'] ?? null;

            if ($category) {
                $products = array_filter($products, fn($p) => strtolower($p['category']) === strtolower($category));
            }

            return $view->render($response, 'Public/products.twig', [
                'title' => 'Our Products - FlourEver',
                'products' => $products,
                'app_url' => $_ENV['APP_URL'],
                'selected_category' => $category,
            ]);
        }


        public function show(Request $request, Response $response, array $args): Response
        {
            $view = Twig::fromRequest($request);

            $products = require __DIR__ . '/../Data/products.php';
            $id = (int) $args['id'];
            $product = $products[$id - 1] ?? null;

            if (!$product) {
                $response->getBody()->write("Product not found");
                return $response->withStatus(404);
            }

            // ✅ Ensure sizes is always an array
            if (isset($product['sizes'])) {
                if (is_string($product['sizes'])) {
                    // Handle both CSV and JSON strings
                    if (str_starts_with(trim($product['sizes']), '[')) {
                        $product['sizes'] = json_decode($product['sizes'], true);
                    } else {
                        $product['sizes'] = array_map('trim', explode(',', $product['sizes']));
                    }
                }
            } else {
                $product['sizes'] = []; // Default fallback
            }

            return $view->render($response, 'Public/product-detail.twig', [
                'title' => $product['name'],
                'product' => $product,
                'app_url' => $_ENV['APP_URL'] ?? '',
            ]);
        }


}
