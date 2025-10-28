<?php
namespace SweetDelights\Mayie\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Views\Twig;

class AuthMiddleware implements MiddlewareInterface {
    
    protected $twig;

    public function __construct(Twig $twig) {
        $this->twig = $twig;
    }

    public function process(Request $request, RequestHandler $handler): Response {
        // 1. Get the user data from the session
        $user = $_SESSION['user'] ?? null;

        // 2. Add 'user' as a global variable available in ALL Twig templates
        $this->twig->getEnvironment()->addGlobal('user', $user);

        // 3. Add 'user' to the Request attributes for other middleware to access
        $request = $request->withAttribute('user', $user);

        // 4. Continue to the next middleware or route
        return $handler->handle($request);
    }
}