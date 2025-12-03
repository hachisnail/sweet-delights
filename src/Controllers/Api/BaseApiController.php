<?php
namespace SweetDelights\Mayie\Controllers\Api;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SweetDelights\Mayie\Controllers\Admin\BaseAdminController; 

class BaseApiController extends BaseAdminController
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Helper to send a standardized JSON success response.
     */
    protected function respondWithData(Response $response, $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    /**
     * Helper to send a standardized JSON error response.
     */
    protected function respondWithError(Response $response, string $message, int $status = 400): Response
    {
        $data = ['status' => 'error', 'message' => $message];
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    /**
     * Helper to send a standardized 401 Unauthorized response.
     */
    protected function respondUnauthorized(Response $response, string $message = 'Not authorized'): Response
    {
        return $this->respondWithError($response, $message, 401);
    }

    /**
     * Reusable function to sync a user key (like 'cart' or 'favourites')
     * to both the session and the database.
     */
    protected function syncUserKey(Request $request, Response $response, string $keyToSync): Response
    {
        if (!isset($_SESSION['user'])) {
            return $this->respondUnauthorized($response);
        }

        $dataToSync = $request->getParsedBody();
        $userId = $_SESSION['user']['id'];
        
        $_SESSION['user'][$keyToSync] = $dataToSync;

        $this->saveUserKey($userId, $keyToSync, $dataToSync);
        
        $responseData = [
            'status' => 'success',
            $keyToSync => $dataToSync
        ];
        return $this->respondWithData($response, $responseData, 200);
    }
}

