<?php
namespace SweetDelights\Mayie\Controllers\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteContext;

class UserAdminController extends BaseAdminController {

    // --- FIX: Call the parent constructor ---
    public function __construct()
    {
        parent::__construct();
    }

    // --- Data Helpers (getUsers/saveUsers) are now inherited ---
    
    /**
     * Show the user list page.
     */
    public function index(Request $request, Response $response): Response {
        $view = $this->viewFromRequest($request);

        // Load all users from the mock data file
        $allUsers = $this->getUsers(); // <-- Inherited

        // --- FIX 1: REMOVED THE 'customer' FILTER ---
        // (This was already commented out in your file, which is good)
        
        // Remove password hashes before sending to the template
        $safeUsers = array_map(function($user) {
            unset($user['password_hash']);
            return $user;
        }, $allUsers); // <-- Use $allUsers

        $breadcrumbs = $this->breadcrumbs($request, [
            ['name' => 'User Accounts', 'url' => null]
        ]);

        return $view->render($response, 'Admin/users.twig', [
            'title' => 'User Accounts',
            'users' => $safeUsers,
            'breadcrumbs' => $breadcrumbs,
            'active_page' => 'users',
            'app_url' => $_ENV['APP_URL'] ?? ''
        ]);
    }

    /**
     * Show the form to create a new user.
     */
    public function create(Request $request, Response $response): Response
    {
        $view = $this->viewFromRequest($request);
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        
        $loggedInUserRole = $request->getAttribute('user')['role'] ?? 'customer';
        
        $user_roles = ['customer', 'admin'];
        if ($loggedInUserRole === 'superadmin') {
            $user_roles[] = 'superadmin';
        }

        return $view->render($response, 'Admin/user-form.twig', [
            'title' => 'Add New User',
            'form_action' => $routeParser->urlFor('app.users.store'),
            'form_mode' => 'create',
            'user_roles' => $user_roles,
            'breadcrumbs' => $this->breadcrumbs($request, [
                ['name' => 'User Accounts', 'url' => 'app.users.index'],
                ['name' => 'Add New', 'url' => null]
            ]),
            'active_page' => 'users'
        ]);
    }

    /**
     * Store the new user in the data file.
     */
    public function store(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $users = $this->getUsers(); // <-- Inherited
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        $loggedInUserRole = $request->getAttribute('user')['role'] ?? 'customer';

        if ($data['role'] === 'superadmin' && $loggedInUserRole !== 'superadmin') {
            $data['role'] = 'customer';
        }

        foreach ($users as $user) {
            if ($user['email'] === $data['email']) {
                $url = $routeParser->urlFor('app.users.create') . '?error=email_exists';
                return $response->withHeader('Location', $url)->withStatus(302);
            }
        }
        
        $newId = empty($users) ? 1 : max(array_column($users, 'id')) + 1;
        $newUser = [
            'id' => $newId,
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'contact_number' => $data['contact_number'] ?? '',
            'address' => [
                'street' => '', 'city' => '', 'state' => '', 'postal_code' => '',
            ],
            'password_hash' => password_hash($data['password'], PASSWORD_DEFAULT),
            'role' => $data['role'],
            'is_verified' => true,
            'is_active' => isset($data['is_active']),
            'verification_token' => null,
            'password_reset_token' => null,
            'password_reset_expires' => null,
            'cart' => [],
            'favourites' => []
        ];

        $users[] = $newUser;
        $this->saveUsers($users); // <-- Inherited

        return $response->withHeader('Location', $routeParser->urlFor('app.users.index'))->withStatus(302);
    }

    /**
     * Show the form to edit an existing user.
     */
    public function edit(Request $request, Response $response, array $args): Response
    {
        $view = $this->viewFromRequest($request);
        $id = (int)$args['id'];
        $users = $this->getUsers(); // <-- Inherited
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        
        $loggedInUserRole = $request->getAttribute('user')['role'] ?? 'customer';
        $foundUser = null;

        foreach ($users as $user) {
            if ($user['id'] === $id) {
                $foundUser = $user;
                break;
            }
        }

        if (!$foundUser) {
            return $response->withHeader('Location', $routeParser->urlFor('app.users.index'))->withStatus(302);
        }

        $user_roles = ['customer', 'admin'];
        if ($loggedInUserRole === 'superadmin') {
            $user_roles[] = 'superadmin';
        }

        return $view->render($response, 'Admin/user-form.twig', [
            'title' => 'Edit User: ' . $foundUser['first_name'],
            'user' => $foundUser,
            'form_action' => $routeParser->urlFor('app.users.update', ['id' => $id]),
            'form_mode' => 'edit',
            'user_roles' => $user_roles,
            'breadcrumbs' => $this->breadcrumbs($request, [
                ['name' => 'User Accounts', 'url' => 'app.users.index'],
                ['name' => 'Edit User', 'url' => null]
            ]),
            'active_page' => 'users',
            'app_url' => $_ENV['APP_URL'] ?? ''
        ]);
    }

    /**
     * Update the user in the data file.
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $data = $request->getParsedBody();
        $users = $this->getUsers(); // <-- Inherited
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        $loggedInUserRole = $request->getAttribute('user')['role'] ?? 'customer';
        $userUpdated = false;

        foreach ($users as &$user) {
            if ($user['id'] === $id) {
                
                $isTargetSuperAdmin = $user['role'] === 'superadmin';
                $isAttemptingPromotion = $data['role'] === 'superadmin';

                if ($isAttemptingPromotion && $loggedInUserRole !== 'superadmin') {
                    $data['role'] = 'customer';
                }
                
                if ($isTargetSuperAdmin && $loggedInUserRole !== 'superadmin') {
                    $data['role'] = 'superadmin';
                    $data['is_active'] = $user['is_active'] ? 'on' : null;
                }

                $user['first_name'] = $data['first_name'];
                $user['last_name'] = $data['last_name'];
                $user['email'] = $data['email'];
                $user['role'] = $data['role'];
                $user['is_active'] = isset($data['is_active']);
                
                if (!empty($data['password'])) {
                    $user['password_hash'] = password_hash($data['password'], PASSWORD_DEFAULT);
                }
                
                $userUpdated = true;
                break;
            }
        }
        unset($user);

        if ($userUpdated) {
            $this->saveUsers($users); // <-- Inherited
        }

        return $response->withHeader('Location', $routeParser->urlFor('app.users.index'))->withStatus(302);
    }
}