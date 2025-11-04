<?php
namespace SweetDelights\Mayie\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class HomeController {
public function index(Request $request, Response $response): Response {
    $view = Twig::fromRequest($request);
    $categories = require __DIR__ . '/../Data/categories.php';
    
    // Only top-level categories
    $topCategories = array_filter($categories, fn($cat) => $cat['parent_id'] === null);

    return $view->render($response, 'Public/home.twig', [
        'title' => 'Welcome to FlourEver',
        'categories' => $topCategories,
        'app_url' => $_ENV['APP_URL'] ?? '',
    ]);
}

}
