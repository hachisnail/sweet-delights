<?php
namespace SweetDelights\Mayie\Controllers\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteContext;
use \PDO; 

class SystemAdminController extends BaseAdminController {

    public function __construct()
    {
        parent::__construct();
    }


    /**
     * Show the system logs page.
     */
    public function viewLogs(Request $request, Response $response): Response {
        $view = $this->viewFromRequest($request);
        
        $params = $request->getQueryParams();
        $filters = [
            'actor'  => $params['actor'] ?? null,
            'action' => $params['action'] ?? null,
            'target' => $params['target'] ?? null,
        ];

        $currentPage = (int)($params['page'] ?? 1);
        $perPage = 20;
        
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
            'app_url' => $_ENV['APP_URL'] ?? '', 
            'current_filters' => $filters,     

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
        
        $log = $this->getLogById($id);

        if (!$log) {
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
        $allUsers = $this->getUsers(); 
        
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
     * Show the form to create a new user.
     */
    public function createUser(Request $request, Response $response): Response
    {
        $view = $this->viewFromRequest($request);
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();

        return $view->render($response, 'Admin/manage-user-form.twig', [
            'title' => 'Add New User',
            'form_action' => $routeParser->urlFor('system.users.store'),
            'form_mode' => 'create',
            'user_roles' => ['customer', 'admin', 'superadmin'],
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

        $user = $request->getAttribute('user');
        $actorId = $user ? (int)$user['id'] : null;

        if ($this->findUserByEmail($data['email'])) {
            $url = $routeParser->urlFor('system.users.create') . '?error=email_exists';
            return $response->withHeader('Location', $url)->withStatus(302);
        }
        
        $newUser = [
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

        $newId = $this->createNewUser($newUser);
        
        $userAfter = $this->findUserById($newId);
        $this->logEntityChange(
            $actorId,
            'create',
            'user',
            $newId,
            null,
            $userAfter
        );

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
        
        $foundUser = $this->findUserById($id);

        if (!$foundUser) {
            return $response->withHeader('Location', $routeParser->urlFor('system.users.index'))->withStatus(302);
        }

        return $view->render($response, 'Admin/manage-user-form.twig', [
            'title' => 'Edit User: ' . $foundUser['first_name'],
            'user' => $foundUser,
            'form_action' => $routeParser->urlFor('system.users.update', ['id' => $id]),
            'form_mode' => 'edit',
            'user_roles' => ['customer', 'admin', 'superadmin'], 
            'breadcrumbs' => $this->breadcrumbs($request, [
                ['name' => 'Manage All Users', 'url' => 'system.users.index'],
                ['name' => 'Edit User', 'url' => null]
            ]),
            'active_page' => 'system_users',
            'app_url' => $_ENV['APP_URL'] ?? ''
        ]);
    }

    /**
     * Update the user.
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $data = $request->getParsedBody();
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();

        $user = $request->getAttribute('user');
        $actorId = $user ? (int)$user['id'] : null;

        $userBefore = $this->findUserById($id);
        if (!$userBefore) {
            return $response->withHeader('Location', $routeParser->urlFor('system.users.index'))->withStatus(302);
        }

        $updateData = [
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'role' => $data['role'], 
            'is_active' => isset($data['is_active']) ? 1 : 0
        ];
        
        if (!empty($data['password'])) {
            $updateData['password_hash'] = password_hash($data['password'], PASSWORD_DEFAULT);
        }

        try {
            $this->updateUser($id, $updateData);

            $userAfter = $this->findUserById($id);
            $this->logEntityChange(
                $actorId,
                'update',
                'user',
                $id,
                $userBefore,
                $userAfter
            );

        } catch (\Exception $e) {

        }

        return $response->withHeader('Location', $routeParser->urlFor('system.users.index'))->withStatus(302);
    }
}