<?php
namespace SweetDelights\Mayie\Controllers\Api;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Slim\Routing\RouteContext;
use SweetDelights\Mayie\Services\MailService; 

class ApiAuthController extends BaseApiController { 

    // --- REMOVED: __construct, getUsers, saveUsers ---
    // They are all inherited from BaseApiController
    
    // --- LOGIN & LOGOUT (Modified) ---

    /**
     * Show the login page. (Unchanged)
     */
    public function showLogin(Request $request, Response $response): Response {
        $view = Twig::fromRequest($request);
        $params = $request->getQueryParams();

        return $view->render($response, 'Public/login.twig', [
             'title'      => 'Login',
             'error'      => $params['error'] ?? null,
             'hideHeader' => true 
        ]);
    }

    /**
     * Handle the login form submission. (MODIFIED)
     */
    public function login(Request $request, Response $response): Response {
        $data = $request->getParsedBody();
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();

        $users = $this->getUsers(); // <-- Uses inherited method

        $foundUser = null;
        foreach ($users as $user) {
            if ($user['email'] === $email) {
                $foundUser = $user;
                break;
            }
        }

        if ($foundUser && password_verify($password, $foundUser['password_hash'])) {
            
            // --- NEW VERIFICATION CHECK ---
            if (!$foundUser['is_verified']) {
                // User exists, password is correct, but not verified
                return $response
                    ->withHeader('Location', '/login?error=not_verified')
                    ->withStatus(302);
            }
            // --- END CHECK ---

            // --- ADD THIS CHECK ---
            if (!$foundUser['is_active']) {
                return $response
                    ->withHeader('Location', '/login?error=disabled')
                    ->withStatus(302);
            }
            // --- END ADD ---

            // SUCCESS: Store user data in session
            $_SESSION['user'] = [
                'id' => $foundUser['id'],
                'first_name' => $foundUser['first_name'],
                'last_name' => $foundUser['last_name'],
                'email' => $foundUser['email'],
                'contact_number' => $foundUser['contact_number'],
                'address' => $foundUser['address'],
                'role' => $foundUser['role'],
                'cart' => $foundUser['cart'] ?? [],
                'favourites' => $foundUser['favourites'] ?? []
            ];

            if (in_array($foundUser['role'], ['admin', 'superadmin'])) {
                return $response->withHeader('Location', '/app/dashboard')->withStatus(302);
            }
            return $response->withHeader('Location', '/')->withStatus(302);
        }

        // FAILED: Redirect back to login with an error flag
        return $response->withHeader('Location', '/login?error=invalid')->withStatus(302);
    }

    /**
     * Log the user out. (Unchanged)
     */
    public function logout(Request $request, Response $response): Response {
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();
        return $response->withHeader('Location', '/')->withStatus(302);
    }

    // --- REGISTRATION & VERIFICATION (All New) ---

    /**
     * Show the registration page.
     */
    public function showRegister(Request $request, Response $response): Response {
        $view = Twig::fromRequest($request);
        return $view->render($response, 'Public/register.twig', [
             'title'      => 'Create Account',
             'hideHeader' => true,
             'error'      => $request->getQueryParams()['error'] ?? null
        ]);
    }

    /**
     * Handle the registration form submission.
     */
    public function register(Request $request, Response $response): Response {
        $data = $request->getParsedBody();
        $users = $this->getUsers(); // <-- Uses inherited method
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();

        // 1. Validate data
        if ($data['password'] !== $data['confirm_password']) {
            return $response->withHeader('Location', '/register?error=password_match')->withStatus(302);
        }

        // 2. Check for duplicate email
        foreach ($users as $user) {
            if ($user['email'] === $data['email']) {
                return $response->withHeader('Location', '/register?error=email_exists')->withStatus(302);
            }
        }

        // 3. Create new user
        $newId = empty($users) ? 1 : max(array_column($users, 'id')) + 1;
        $token = bin2hex(random_bytes(32)); // Unique verification token

        $newUser = [
            'id' => $newId,
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'contact_number' => '', // Empty by default
            'address' => [ 
                'street' => '',
                'city' => '',
                'state' => '',
                'postal_code' => '',
            ],
            'password_hash' => password_hash($data['password'], PASSWORD_DEFAULT),
            'role' => 'customer', // Default role
            'is_verified' => false,
            'is_active' => true, // <-- NEW: Set to active by default
            'verification_token' => $token,
            'password_reset_token' => null, // <-- NEW
            'password_reset_expires' => null, // <-- NEW
            'cart' => [],
            'favourites' => []
        ];

        // 4. Send verification email
        $view = Twig::fromRequest($request);
        $mailService = new MailService($view);
        $emailSent = $mailService->sendVerificationEmail($newUser, $token);

        if (!$emailSent) {
            // This is a server error, don't save the user
             return $response->withHeader('Location', '/register?error=email_failed')->withStatus(302);
        }

        // 5. Save user to file
        $users[] = $newUser;
        $this->saveUsers($users); // <-- Uses inherited method

        // 6. Redirect to a "please verify" message page
        return $response->withHeader('Location', '/verify-message')->withStatus(302);
    }

    /**
     * Show the "Please check your email" message.
     */
    public function showVerificationMessage(Request $request, Response $response): Response {
        $view = Twig::fromRequest($request);
        return $view->render($response, 'Public/verify-message.twig', [
             'title'      => 'Check Your Email',
             'hideHeader' => true 
        ]);
    }

    /**
     * Handle the email verification link click.
     */
    public function verifyEmail(Request $request, Response $response): Response {
        $token = $request->getQueryParams()['token'] ?? '';
        if (empty($token)) {
            $response->getBody()->write('Invalid token.');
            return $response->withStatus(400);
        }

        $users = $this->getUsers(); // <-- Uses inherited method
        $userFound = false;
        
        foreach ($users as &$user) {
            if ($user['verification_token'] === $token) {
                $user['is_verified'] = true;
                $user['verification_token'] = null; // Token is now used, remove it
                $userFound = true;
                break;
            }
        }
        unset($user);

        if ($userFound) {
            $this->saveUsers($users); // <-- Uses inherited method
            // Redirect to login with a success message
            return $response->withHeader('Location', '/login?error=verified')->withStatus(302);
        }

        $response->getBody()->write('Invalid or expired token.');
        return $response->withStatus(400);
    }


    public function showForgotPassword(Request $request, Response $response): Response {
        $view = Twig::fromRequest($request);
        return $view->render($response, 'Public/forgot-password.twig', [
             'title'      => 'Forgot Password',
             'hideHeader' => true,
             'success'    => $request->getQueryParams()['success'] ?? null
        ]);
    }

    /**
     * Handle the "Forgot Password" submission.
     */
    public function handleForgotPassword(Request $request, Response $response): Response {
        $data = $request->getParsedBody();
        $email = $data['email'] ?? '';
        $users = $this->getUsers(); // <-- Uses inherited method
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        $userFound = false;

        foreach ($users as &$user) {
            if ($user['email'] === $email) {
                // Generate a token and expiry
                $token = bin2hex(random_bytes(32));
                $expires = time() + 3600; // 1 hour from now

                $user['password_reset_token'] = $token;
                $user['password_reset_expires'] = $expires;
                $userFound = $user; // Store the user data to pass to the mailer
                break;
            }
        }
        unset($user);

        if ($userFound) {
            // Save the token to the users file
            $this->saveUsers($users); // <-- Uses inherited method

            // Send the email
            $view = Twig::fromRequest($request);
            $mailService = new MailService($view);
            $mailService->sendPasswordResetEmail($userFound, $token);
        }

        // IMPORTANT: Always show a success message, even if the email wasn't found.
        // This prevents attackers from guessing which emails are in your system.
        return $response->withHeader('Location', '/forgot-password?success=1')->withStatus(302);
    }

    /**
     * Show the "Reset Password" form (to enter new password).
     */
    public function showResetPassword(Request $request, Response $response): Response {
        $token = $request->getQueryParams()['token'] ?? '';
        $users = $this->getUsers(); // <-- Uses inherited method
        $validToken = false;
        $error = null;

        if (empty($token)) {
            $error = 'invalid';
        } else {
            foreach ($users as $user) {
                if (($user['password_reset_token'] ?? null) === $token) {
                    // Token found, now check expiry
                    if (time() > ($user['password_reset_expires'] ?? 0)) {
                        $error = 'expired';
                    } else {
                        $validToken = true;
                    }
                    break;
                }
            }
            if (!$validToken && !$error) {
                $error = 'invalid';
            }
        }
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'Public/reset-password.twig', [
             'title'      => 'Reset Your Password',
             'hideHeader' => true,
             'token'      => $token,
             'error'      => $error
        ]);
    }

    /**
     * Handle the "Reset Password" form submission.
     */
    public function handleResetPassword(Request $request, Response $response): Response {
        $data = $request->getParsedBody();
        $token = $data['token'] ?? '';
        $password = $data['password'] ?? '';
        $confirm = $data['confirm_password'] ?? '';
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();

        if ($password !== $confirm) {
            return $response->withHeader('Location', '/reset-password?token=' . $token . '&error=match')->withStatus(302);
        }

        $users = $this->getUsers(); // <-- Uses inherited method
        $userUpdated = false;

        foreach ($users as &$user) {
            if (($user['password_reset_token'] ?? null) === $token) {
                // Token found, check expiry
                if (time() > ($user['password_reset_expires'] ?? 0)) {
                    return $response->withHeader('Location', '/reset-password?token=' . $token . '&error=expired')->withStatus(302);
                }

                // Token is valid! Update the user.
                $user['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                $user['password_reset_token'] = null; // Invalidate token
                $user['password_reset_expires'] = null;
                $userUpdated = true;
                break;
            }
        }
        unset($user);

        if ($userUpdated) {
            $this->saveUsers($users); // <-- Uses inherited method
            // Success! Redirect to login with a message.
            return $response->withHeader('Location', '/login?error=reset_success')->withStatus(302);
        }

        // Token was not found
        return $response->withHeader('Location', '/reset-password?token=' . $token . '&error=invalid')->withStatus(302);
    }
}

