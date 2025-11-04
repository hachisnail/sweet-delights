<?php
namespace SweetDelights\Mayie\Controllers\Api;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ApiCartController extends \SweetDelights\Mayie\Controllers\BaseDataController {

    public function sync(Request $request, Response $response): Response {
        if (!isset($_SESSION['user'])) {
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Not authorized']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
        }

        $cartData = $request->getParsedBody();
        $userId = $_SESSION['user']['id'];
        
        $_SESSION['user']['cart'] = $cartData;

        $filePath = __DIR__ . '/../../Data/users.php';
        
        $users = require $filePath; 

        $updatedUsers = array_map(function($user) use ($userId, $cartData) {
            if ($user['id'] === $userId) {
                $user['cart'] = $cartData;
            }
            return $user;
        }, $users);

        $dataToWrite = "<?php\n\nreturn " . var_export($updatedUsers, true) . ";\n";
        $this->saveData($filePath, $updatedUsers);
        // try {
        //     file_put_contents($filePath, $dataToWrite, LOCK_EX);
        // } catch (\Exception $e) {
            
        //     // ✅ ADD THIS LINE TO SEE THE ERROR
        //     error_log('Failed to write to users.php: ' . $e->getMessage());

        //     $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Failed to save persistent cart.']));
        //     return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        // }
        
        $response->getBody()->write(json_encode(['status' => 'success', 'cart' => $cartData]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
    }
}