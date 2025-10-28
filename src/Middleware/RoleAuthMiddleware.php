<?php
namespace SweetDelights\Mayie\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse; 

class RoleAuthMiddleware implements MiddlewareInterface {
    
    /**
     * @var array
     */
    protected $allowedRoles;

    /**
     * Constructor to accept the list of allowed roles.
     * @param array $allowedRoles
     */
    public function __construct(array $allowedRoles) {
        $this->allowedRoles = $allowedRoles;
    }

    public function process(Request $request, RequestHandler $handler): Response {
        // 1. Get user from the request attribute (set by the global AuthMiddleware)
        $user = $request->getAttribute('user');

        // 2. Check for authorization
        $isAuthorized = false;
        if ($user && in_array($user['role'], $this->allowedRoles)) {
            $isAuthorized = true;
        }

        // 3. If not authorized, redirect
        if (!$isAuthorized) {
            $response = new SlimResponse();
            
            // If logged in but wrong role, go home. 
            // If not logged in at all, go to login.
            $redirectUrl = $user ? '/' : '/login';
            return $response->withHeader('Location', $redirectUrl)->withStatus(302);
        }
        
        // 4. User is authorized, proceed
        return $handler->handle($request);
    }
}