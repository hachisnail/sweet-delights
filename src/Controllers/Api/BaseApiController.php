<?php
namespace SweetDelights\Mayie\Controllers\Api;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
// Inherit from your most powerful base controller to get all data methods
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
     * to both the session and the users.php file.
     */
    protected function syncUserKey(Request $request, Response $response, string $keyToSync): Response
    {
        if (!isset($_SESSION['user'])) {
            return $this->respondUnauthorized($response);
        }

        $dataToSync = $request->getParsedBody();
        $userId = $_SESSION['user']['id'];
        
        // 1. Update the session
        $_SESSION['user'][$keyToSync] = $dataToSync;

        // 2. Load users and update the file
        $users = $this->getUsers();

        $updatedUsers = array_map(function($user) use ($userId, $keyToSync, $dataToSync) {
            if ($user['id'] === $userId) {
                $user[$keyToSync] = $dataToSync;
            }
            return $user;
        }, $users);

        // 3. Save to file (using inherited method)
        $this->saveUsers($updatedUsers);
        
        // 4. Send success response
        $responseData = [
            'status' => 'success',
            $keyToSync => $dataToSync
        ];
        return $this->respondWithData($response, $responseData, 200);
    }
}

