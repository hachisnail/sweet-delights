<?php
namespace SweetDelights\Mayie\Controllers\Api;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

use Slim\Views\Twig;

class AuthController {

    /**
     * Show the login page.
     */
    public function showLogin(Request $request, Response $response): Response {
        $view = Twig::fromRequest($request);
        $params = $request->getQueryParams();

        return $view->render($response, 'Public/login.twig', [
             'title'      => 'Login',
             'error'      => isset($params['error']),
             'hideHeader' => true 
        ]);
    }

    /**
     * Handle the login form submission.
     */
    public function login(Request $request, Response $response): Response {
        $data = $request->getParsedBody();
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';

        $users = require __DIR__ . '/../Data/users.php';

        $foundUser = null;
        foreach ($users as $user) {
            if ($user['email'] === $email) {
                $foundUser = $user;
                break;
            }
        }

        if ($foundUser && password_verify($password, $foundUser['password_hash'])) {
            // SUCCESS: Store user data in session
            
            // ✅ --- START FIX ---
            // Load all user data into the session, including cart and favourites
            $_SESSION['user'] = [
                'id' => $foundUser['id'],
                'name' => $foundUser['name'], 
                'email' => $foundUser['email'],
                'role' => $foundUser['role'],
                // These lines load the persistent data from users.php
                'cart' => $foundUser['cart'] ?? [],
                'favourites' => $foundUser['favourites'] ?? []
            ];
            // ✅ --- END FIX ---


            // --- THIS IS THE NEW REDIRECT LOGIC ---
            if (in_array($foundUser['role'], ['admin', 'superadmin'])) {
                // Redirect admins to the admin dashboard
                return $response
                    ->withHeader('Location', '/app/dashboard')
                    ->withStatus(302);
            }

            // Redirect all other users (e.g., customers) to the home page
            return $response
                ->withHeader('Location', '/')
                ->withStatus(302);
            // -------------------------------------
        }

        // FAILED: Redirect back to login with an error flag
        return $response
            ->withHeader('Location', '/login?error=1')
            ->withStatus(302);
    }

    /**
     * Log the user out by destroying the session.
     */
    public function logout(Request $request, Response $response): Response {
        // Unset all session values
        $_SESSION = [];

        // Destroy the session
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();

        // Redirect back to the home page
        return $response
            ->withHeader('Location', '/')
            ->withStatus(302);
    }
}
