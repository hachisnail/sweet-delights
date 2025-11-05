<?php
namespace SweetDelights\Mayie\Controllers\Api;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Slim\Routing\RouteContext;
use SweetDelights\Mayie\Services\MailService; 
use SweetDelights\Mayie\Controllers\Admin\BaseAdminController;

class ApiAuthController extends BaseAdminController
{ 
    // --- LOGIN & LOGOUT ---

    /**
     * Show the login page.
     */
    public function showLogin(Request $request, Response $response): Response
    {
        $view = Twig::fromRequest($request);
        $params = $request->getQueryParams();

        return $view->render($response, 'Public/login.twig', [
            'title'       => 'Login',
            'error'       => $params['error'] ?? null,
            'hideHeader'  => true
        ]);
    }

    /**
     * Handle login form submission.
     */
    public function login(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        $ipAddress = $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown';

        $foundUser = $this->findUserByEmail($email);

        if ($foundUser && password_verify($password, $foundUser['password_hash'])) {

            // --- VERIFICATION CHECK ---
            if (!$foundUser['is_verified']) {
                $this->logAction(
                    $foundUser['id'],
                    'login_fail',
                    'user',
                    $foundUser['id'],
                    ['reason' => 'not_verified', 'ip' => $ipAddress]
                );
                return $response->withHeader('Location', '/login?error=not_verified')->withStatus(302);
            }

            // --- ACTIVE CHECK ---
            if (!$foundUser['is_active']) {
                $this->logAction(
                    $foundUser['id'],
                    'login_fail',
                    'user',
                    $foundUser['id'],
                    ['reason' => 'disabled', 'ip' => $ipAddress]
                );
                return $response->withHeader('Location', '/login?error=disabled')->withStatus(302);
            }

            // SUCCESS: Store user data in session
            $_SESSION['user'] = [
                'id'             => $foundUser['id'],
                'first_name'     => $foundUser['first_name'],
                'last_name'      => $foundUser['last_name'],
                'email'          => $foundUser['email'],
                'contact_number' => $foundUser['contact_number'],
                'address'        => $foundUser['address'],
                'role'           => $foundUser['role'],
                'cart'           => $foundUser['cart'],
                'favourites'     => $foundUser['favourites']
            ];

            // Log successful login
            $this->logAction(
                $foundUser['id'],
                'login_success',
                'user',
                $foundUser['id'],
                ['ip' => $ipAddress]
            );

            if (in_array($foundUser['role'], ['admin', 'superadmin'])) {
                return $response->withHeader('Location', '/app/dashboard')->withStatus(302);
            }
            return $response->withHeader('Location', '/')->withStatus(302);
        }

        // FAILED LOGIN
        $this->logAction(
            $foundUser['id'] ?? null,
            'login_fail',
            'user',
            $foundUser['id'] ?? null,
            ['reason' => 'invalid_credentials', 'attempted_email' => $email, 'ip' => $ipAddress]
        );

        return $response->withHeader('Location', '/login?error=invalid')->withStatus(302);
    }

    /**
     * Log the user out.
     */
    public function logout(Request $request, Response $response): Response
    {
        if (isset($_SESSION['user']['id'])) {
            $this->logAction(
                $_SESSION['user']['id'],
                'logout',
                'user',
                $_SESSION['user']['id'],
                null
            );
        }

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

    // --- REGISTRATION & VERIFICATION ---

    public function showRegister(Request $request, Response $response): Response
    {
        $view = Twig::fromRequest($request);
        return $view->render($response, 'Public/register.twig', [
            'title'      => 'Create Account',
            'hideHeader' => true,
            'error'      => $request->getQueryParams()['error'] ?? null
        ]);
    }

    public function register(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $ipAddress = $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown';

        // Password match check
        if ($data['password'] !== $data['confirm_password']) {
            return $response->withHeader('Location', '/register?error=password_match')->withStatus(302);
        }

        // Duplicate email check
        if ($this->findUserByEmail($data['email'])) {
            return $response->withHeader('Location', '/register?error=email_exists')->withStatus(302);
        }

        // Determine if this is the first user
        $countStmt = $this->db->query("SELECT COUNT(*) FROM users");
        $userCount = $countStmt->fetchColumn();

        $role = 'customer';
        $isVerified = false;
        $token = bin2hex(random_bytes(32));

        if ($userCount == 0) {
            $role = 'superadmin';
            $isVerified = true;
            $token = null;
        }

        // Build new user
        $newUser = [
            'first_name'             => $data['first_name'],
            'last_name'              => $data['last_name'],
            'email'                  => $data['email'],
            'contact_number'         => '',
            'address'                => ['street' => '', 'city' => '', 'state' => '', 'postal_code' => ''],
            'password_hash'          => password_hash($data['password'], PASSWORD_DEFAULT),
            'role'                   => $role,
            'is_verified'            => $isVerified,
            'is_active'              => true,
            'verification_token'     => $token,
            'password_reset_token'   => null,
            'password_reset_expires' => null,
            'cart'                   => [],
            'favourites'             => []
        ];

        $newUserId = $this->createNewUser($newUser);

        // Log registration with "after" state
        $this->logAction(
            $newUserId,
            'register',
            'user',
            $newUserId,
            [
                'before' => null,
                'after'  => [
                    'email'       => $newUser['email'],
                    'role'        => $newUser['role'],
                    'is_verified' => $newUser['is_verified'],
                    'is_active'   => $newUser['is_active']
                ],
                'meta' => [
                    'ip' => $ipAddress,
                    'registration_type' => ($role === 'superadmin' ? 'first_user' : 'customer_signup')
                ]
            ]
        );

        if ($role === 'superadmin') {
            // Auto-login the first superadmin
            $_SESSION['user'] = [
                'id'             => $newUserId,
                'first_name'     => $newUser['first_name'],
                'last_name'      => $newUser['last_name'],
                'email'          => $newUser['email'],
                'contact_number' => $newUser['contact_number'],
                'address'        => $newUser['address'],
                'role'           => $newUser['role'],
                'cart'           => $newUser['cart'],
                'favourites'     => $newUser['favourites']
            ];

            return $response->withHeader('Location', '/app/dashboard')->withStatus(302);
        }

        // Customer registration path
        $view = Twig::fromRequest($request);
        $mailService = new MailService($view);
        $emailSent = $mailService->sendVerificationEmail($newUser, $token);

        if (!$emailSent) {
            return $response->withHeader('Location', '/register?error=email_failed')->withStatus(302);
        }

        return $response->withHeader('Location', '/verify-message')->withStatus(302);
    }

    public function showVerificationMessage(Request $request, Response $response): Response
    {
        $view = Twig::fromRequest($request);
        return $view->render($response, 'Public/verify-message.twig', [
            'title'      => 'Check Your Email',
            'hideHeader' => true
        ]);
    }

   public function verifyEmail(Request $request, Response $response): Response
    {
        $token = $request->getQueryParams()['token'] ?? '';
        if (empty($token)) {
            $response->getBody()->write('Invalid token.');
            return $response->withStatus(400);
        }

        $stmt = $this->db->prepare("SELECT * FROM users WHERE verification_token = ?");
        $stmt->execute([$token]);
        $userToVerify = $stmt->fetch();

        if (!$userToVerify) {
            $response->getBody()->write('Invalid token.');
            return $response->withStatus(400);
        }

        $userId = $userToVerify['id'];

        // Before/after logging for verification
        $before = [
            'is_verified' => $userToVerify['is_verified'],
            'verification_token' => $userToVerify['verification_token']
        ];

        if ($this->verifyUserEmail($token)) {
            $after = [
                'is_verified' => true,
                'verification_token' => null
            ];

            $this->logAction(
                $userId,
                'verify_email',
                'user',
                $userId,
                [
                    'before' => $before,
                    'after'  => $after,
                    'meta'   => ['ip' => $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown']
                ]
            );

            return $response->withHeader('Location', '/login?error=verified')->withStatus(302);
        }

        $response->getBody()->write('Invalid or expired token.');
        return $response->withStatus(400);
    }

    // --- PASSWORD RESET ---

    public function showForgotPassword(Request $request, Response $response): Response
    {
        $view = Twig::fromRequest($request);
        return $view->render($response, 'Public/forgot-password.twig', [
            'title'      => 'Forgot Password',
            'hideHeader' => true,
            'success'    => $request->getQueryParams()['success'] ?? null
        ]);
    }

    public function handleForgotPassword(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $email = $data['email'] ?? '';
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        $ipAddress = $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown';

        $userFound = $this->findUserByEmail($email);

        if ($userFound) {
            $token = bin2hex(random_bytes(32));
            $expires = time() + 3600;

            $this->saveUserResetToken($userFound['id'], $token, $expires);

            $this->logAction(
                $userFound['id'],
                'request_password_reset',
                'user',
                $userFound['id'],
                ['ip' => $ipAddress]
            );

            $view = Twig::fromRequest($request);
            $mailService = new MailService($view);
            $mailService->sendPasswordResetEmail($userFound, $token);
        }

        return $response->withHeader('Location', '/forgot-password?success=1')->withStatus(302);
    }

    public function showResetPassword(Request $request, Response $response): Response
    {
        $token = $request->getQueryParams()['token'] ?? '';
        $validToken = false;
        $error = null;

        if (empty($token)) {
            $error = 'invalid';
        } else {
            $stmt = $this->db->prepare("SELECT * FROM users WHERE password_reset_token = ?");
            $stmt->execute([$token]);
            $user = $stmt->fetch();

            if ($user) {
                if (time() > ($user['password_reset_expires'] ?? 0)) {
                    $error = 'expired';
                } else {
                    $validToken = true;
                }
            } else {
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

    public function handleResetPassword(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $token = $data['token'] ?? '';
        $password = $data['password'] ?? '';
        $confirm = $data['confirm_password'] ?? '';
        $ipAddress = $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown';

        if ($password !== $confirm) {
            return $response->withHeader('Location', '/reset-password?token=' . $token . '&error=match')->withStatus(302);
        }

        $stmt = $this->db->prepare("SELECT * FROM users WHERE password_reset_token = ?");
        $stmt->execute([$token]);
        $user = $stmt->fetch();

        if (!$user) {
            return $response->withHeader('Location', '/reset-password?token=' . $token . '&error=invalid')->withStatus(302);
        }

        if (time() > ($user['password_reset_expires'] ?? 0)) {
            return $response->withHeader('Location', '/reset-password?token=' . $token . '&error=expired')->withStatus(302);
        }

        // --- Before/After log for password reset ---
        $before = [
            'password_hash' => '***old_hash***',
            'password_reset_token' => $user['password_reset_token'],
            'password_reset_expires' => $user['password_reset_expires']
        ];

        $newHash = password_hash($password, PASSWORD_DEFAULT);
        $updateStmt = $this->db->prepare("
            UPDATE users 
            SET password_hash = ?, password_reset_token = NULL, password_reset_expires = NULL 
            WHERE id = ?
        ");
        $updateStmt->execute([$newHash, $user['id']]);

        $after = [
            'password_hash' => '***new_hash***',
            'password_reset_token' => null,
            'password_reset_expires' => null
        ];

        $this->logAction(
            $user['id'],
            'password_reset_success',
            'user',
            $user['id'],
            [
                'before' => $before,
                'after'  => $after,
                'meta'   => ['ip' => $ipAddress]
            ]
        );

        return $response->withHeader('Location', '/login?error=reset_success')->withStatus(302);
    }

}
