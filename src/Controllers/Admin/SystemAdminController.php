<?php
namespace SweetDelights\Mayie\Controllers\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteContext;

class SystemAdminController extends BaseAdminController {

    // --- FIX: Call the parent constructor ---
    public function __construct()
    {
        parent::__construct();
    }

    // --- Data Helpers (getUsers/saveUsers) are now inherited ---


    /**
     * Show the system logs page.
     */
    public function viewLogs(Request $request, Response $response): Response {
        $view = $this->viewFromRequest($request);
        
        // --- FIX: Use inherited helper method ---
        $mockLogs = $this->getLogs(); 

        $breadcrumbs = $this->breadcrumbs($request, [
            ['name' => 'System Logs', 'url' => null]
        ]);

        return $view->render($response, 'Admin/system-logs.twig', [
            'title' => 'System Logs',
            'logs'  => $mockLogs,
            'breadcrumbs' => $breadcrumbs, 
            'active_page' => 'system_logs', 
        ]);
    }

    /**
     * Show the user list page (for superadmins).
     */
    public function manageUsers(Request $request, Response $response): Response {
        $view = $this->viewFromRequest($request);
        $allUsers = $this->getUsers(); // <-- Inherited
        
        $safeUsers = array_map(function($user) {
            unset($user['password_hash']);
            return $user;
        }, $allUsers);

        $breadcrumbs = $this->breadcrumbs($request, [
            ['name' => 'Manage All Users', 'url' => null]
        ]);

        return $view->render($response, 'Admin/manage-users.twig', [
            'title' => 'Manage All Users',
            'users' => $safeUsers,
            'breadcrumbs' => $breadcrumbs,
            'active_page' => 'system_users',
            'app_url' => $_ENV['APP_URL'] ?? ''
        ]);
    }

    /**
     * Show the form to create a new user (superadmin version).
     */
    public function createUser(Request $request, Response $response): Response
    {
        $view = $this->viewFromRequest($request);
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();

        return $view->render($response, 'Admin/manage-user-form.twig', [
            'title' => 'Add New User',
            'form_action' => $routeParser->urlFor('system.users.store'),
            'form_mode' => 'create',
            'user_roles' => ['customer', 'admin', 'superadmin'], // Superadmin can create any role
            'breadcrumbs' => $this->breadcrumbs($request, [
                ['name' => 'Manage All Users', 'url' => 'system.users.index'],
                ['name' => 'Add New', 'url' => null]
            ]),
            'active_page' => 'system_users'
        ]);
    }

    /**
     * Store the new user (superadmin version).
     */
    public function storeUser(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $users = $this->getUsers(); // <-- Inherited
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();

        // Check for duplicate email
        foreach ($users as $user) {
            if ($user['email'] === $data['email']) {
                $url = $routeParser->urlFor('system.users.create') . '?error=email_exists';
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
            'role' => $data['role'], // No security check, superadmin can set any role
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

        return $response->withHeader('Location', $routeParser->urlFor('system.users.index'))->withStatus(302);
    }

    /**
     * Show the edit form (superadmin version).
     */
    public function editUser(Request $request, Response $response, array $args): Response
    {
        $view = $this->viewFromRequest($request);
        $id = (int)$args['id'];
        $users = $this->getUsers(); // <-- Inherited
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        $foundUser = null;

        foreach ($users as $user) {
            if ($user['id'] === $id) {
                $foundUser = $user;
                break;
            }
        }

        if (!$foundUser) {
            return $response->withHeader('Location', $routeParser->urlFor('system.users.index'))->withStatus(302);
        }

        return $view->render($response, 'Admin/manage-user-form.twig', [
            'title' => 'Edit User: ' . $foundUser['first_name'],
            'user' => $foundUser,
            'form_action' => $routeParser->urlFor('system.users.update', ['id' => $id]),
            'form_mode' => 'edit',
            'user_roles' => ['customer', 'admin', 'superadmin'], // Superadmin can see all roles
            'breadcrumbs' => $this->breadcrumbs($request, [
                ['name' => 'Manage All Users', 'url' => 'system.users.index'],
                ['name' => 'Edit User', 'url' => null]
            ]),
            'active_page' => 'system_users',
            'app_url' => $_ENV['APP_URL'] ?? ''
        ]);
    }

    /**
     * Update the user (superadmin version).
     */
    public function updateUser(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $data = $request->getParsedBody();
        $users = $this->getUsers(); // <-- Inherited
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        $userUpdated = false;

        foreach ($users as &$user) {
            if ($user['id'] === $id) {
                
                // NO SECURITY CHECKS. Superadmin can change anything.
                
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

        return $response->withHeader('Location', $routeParser->urlFor('system.users.index'))->withStatus(302);
    }
}