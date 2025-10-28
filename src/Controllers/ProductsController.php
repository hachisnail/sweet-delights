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

        // call from db
        $products = require __DIR__ . '/../Data/products.php';


        return $view->render($response, 'Public/products.twig', [
            'title' => 'Our Products - Sweet Delights',
            'products' => $products,
            'app_url' => $_ENV['APP_URL'],
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

        return $view->render($response, 'Public/product-detail.twig', [
            'title' => $product['name'],
            'product' => $product,
            'app_url' => $_ENV['APP_URL'] ?? '',
        ]);
    }

}
