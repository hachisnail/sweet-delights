<?php
namespace SweetDelights\Mayie\Controllers\Public;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class AboutController {
    public function index(Request $request, Response $response): Response {
        $view = Twig::fromRequest($request);

        return $view->render($response, 'Public/about.twig', [
            'title' => 'About Us - FlourEver',
            'description' => 'Learn about our story and passion for baking.',
            'team' => [
                ['name' => 'Mayie', 'role' => 'Founder & Head Baker'],
                ['name' => 'Jeffe', 'role' => 'Pastry Designer'],
            ],
        ]);
    }
}
