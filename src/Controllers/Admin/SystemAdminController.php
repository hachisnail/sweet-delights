<?php
namespace SweetDelights\Mayie\Controllers\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteContext;
use \PDO; // <-- Not strictly needed but good practice

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
        
        // --- NEW: Handle filter query parameters ---
        $params = $request->getQueryParams();
        $filters = [
            'actor'  => $params['actor'] ?? null,
            'action' => $params['action'] ?? null,
            'target' => $params['target'] ?? null,
        ];

        // --- NEW: Handle pagination ---
        $currentPage = (int)($params['page'] ?? 1);
        $perPage = 20; // Set how many logs to show per page
        
        // --- FIX: Use inherited helper method with filters ---
        $logData = $this->getLogs($filters, $currentPage, $perPage);
        $logs = $logData['logs'];
        $totalLogs = $logData['total'];
        $totalPages = (int)ceil($totalLogs / $perPage);

        $breadcrumbs = $this->breadcrumbs($request, [
            ['name' => 'System Logs', 'url' => null]
        ]);

        return $view->render($response, 'Admin/system-logs.twig', [
            'title' => 'System Logs',
            'logs'  => $logs,
            'breadcrumbs' => $breadcrumbs, 
            'active_page' => 'system_logs',
            'app_url' => $_ENV['APP_URL'] ?? '', // <-- Added for filter form
            'current_filters' => $filters,     // <-- Added for filter form

            // --- NEW PAGINATION VARS ---
            'currentPage' => $currentPage,
            'totalPages' => $totalPages,
            'totalLogs' => $totalLogs
        ]);
    }

    /**
     * Show the details page for a single log entry.
     */
    public function viewLogDetails(Request $request, Response $response, array $args): Response 
    {
        $view = $this->viewFromRequest($request);
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        $id = (int)$args['id'];
        
        // Use the getLogById helper from BaseAdminController
        $log = $this->getLogById($id);

        if (!$log) {
            // Log not found, redirect back to the list
            return $response->withHeader('Location', $routeParser->urlFor('system.logs.index'))->withStatus(302);
        }

        $breadcrumbs = $this->breadcrumbs($request, [
            ['name' => 'System Logs', 'url' => 'system.logs.index'],
            ['name' => 'Log Details', 'url' => null]
        ]);

        return $view->render($response, 'Admin/system-logs-details.twig', [
            'title' => 'Log Details',
            'log'   => $log,
            'breadcrumbs' => $breadcrumbs, 
            'active_page' => 'system_logs', 
        ]);
    }

    /**
     * Show the user list page (for superadmins).
     * (This method is already correct, as getUsors() is now the DB version)
     */
    public function manageUsers(Request $request, Response $response): Response {
        $view = $this->viewFromRequest($request);
        $allUsers = $this->getUsers(); // <-- Inherited DB method
        
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
     * (This method is correct, no data logic)
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
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();

        // --- 1. GET ACTOR ---
        $user = $request->getAttribute('user');
        $actorId = $user ? (int)$user['id'] : null;

        // --- REFACTORED: Use DB helpers ---
        // 1. Check for duplicate email
        if ($this->findUserByEmail($data['email'])) {
            $url = $routeParser->urlFor('system.users.create') . '?error=email_exists';
            return $response->withHeader('Location', $url)->withStatus(302);
        }
        
        // 2. Build the new user array
        $newUser = [
            // 'id' is removed (AUTO_INCREMENT)
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

        // 3. Create the user
        $newId = $this->createNewUser($newUser); // <-- New helper from BaseAdminController
        
        // --- 4. LOG ACTIVITY ---
        $userAfter = $this->findUserById($newId);
        $this->logEntityChange(
            $actorId,
            'create',
            'user',
            $newId,
            null,
            $userAfter
        );
        // --- END LOG ---

        return $response->withHeader('Location', $routeParser->urlFor('system.users.index'))->withStatus(302);
    }

    /**
     * Show the edit form (superadmin version).
     */
    public function editUser(Request $request, Response $response, array $args): Response
    {
        $view = $this->viewFromRequest($request);
        $id = (int)$args['id'];
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        
        // --- REFACTORED: Use DB helper ---
        $foundUser = $this->findUserById($id);
        // --- END REFACTOR ---

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
     *
     * --- FIX: Renamed from 'updateUser' to 'update' to avoid conflict ---
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $data = $request->getParsedBody();
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();

        // --- 1. GET ACTOR ---
        $user = $request->getAttribute('user');
        $actorId = $user ? (int)$user['id'] : null;

        // --- 2. GET "BEFORE" STATE ---
        $userBefore = $this->findUserById($id);
        if (!$userBefore) {
            // User not found
            return $response->withHeader('Location', $routeParser->urlFor('system.users.index'))->withStatus(302);
        }

        // 3. Build the data array for the helper
        $updateData = [
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'role' => $data['role'], // Superadmin can set any role
            'is_active' => isset($data['is_active']) ? 1 : 0
        ];
        
        // 4. Only add password if it's not empty
        if (!empty($data['password'])) {
            $updateData['password_hash'] = password_hash($data['password'], PASSWORD_DEFAULT);
        }

        // 5. Call the parent helper
        try {
            $this->updateUser($id, $updateData);

            // --- 6. LOG ACTIVITY ---
            $userAfter = $this->findUserById($id);
            $this->logEntityChange(
                $actorId,
                'update',
                'user',
                $id,
                $userBefore,
                $userAfter
            );
            // --- END LOG ---

        } catch (\Exception $e) {
            // Handle potential errors, e.g., duplicate email
            // In a real app, log this: error_log($e->getMessage());
            // For now, we just redirect. A flash message would be good.
        }

        return $response->withHeader('Location', $routeParser->urlFor('system.users.index'))->withStatus(302);
    }
}