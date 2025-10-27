<?php
namespace SweetDelights\Mayie\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class HomeController {
    public function index(Request $request, Response $response): Response {
        $view = Twig::fromRequest($request);
        return $view->render($response, 'public/home.twig', [
            'title' => 'Welcome to FlourEver',
        ]);
    }
}
