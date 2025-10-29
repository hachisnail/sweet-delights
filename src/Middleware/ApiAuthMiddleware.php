<?php
namespace SweetDelights\Mayie\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

class ApiAuthMiddleware implements MiddlewareInterface {

    public function process(Request $request, RequestHandler $handler): Response {
        // Just check if the user session exists.
        // This middleware is perfect for any logged-in user.
        if (!isset($_SESSION['user'])) {
            $response = new SlimResponse();
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Authentication required']));
            
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(401);
        }
        
        // User is logged in, proceed to the API controller
        return $handler->handle($request);
    }
}