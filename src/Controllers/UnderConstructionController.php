<?php
namespace SweetDelights\Mayie\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * A reusable controller to show an "Under Construction" page.
 * You can use this for routes or modules that are not ready yet.
 */
class UnderConstructionController
{
    public function show(Request $request, Response $response, array $args = []): Response
    {
        $view = Twig::fromRequest($request);

        // Optionally allow custom title/message via route or query
        $title = $args['title'] ?? 'Under Construction';
        $message = $args['message'] ?? 'This feature is currently being built. Please check back later!';

        return $view->render($response, 'Shared/under-construction.twig', [
            'title' => $title,
            'message' => $message
        ]);
    }
}
