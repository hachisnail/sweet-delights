<?php
namespace SweetDelights\Mayie\Controllers\Api;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

// Inherit from the new BaseApiController
class ApiCartController extends BaseApiController {

    /**
     * Syncs the user's cart to the session and file.
     * The new syncUserKey method handles all the logic.
     */
    public function sync(Request $request, Response $response): Response {
        return $this->syncUserKey($request, $response, 'cart');
    }
}
