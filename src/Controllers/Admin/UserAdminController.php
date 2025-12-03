<?php
namespace SweetDelights\Mayie\Controllers\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteContext;

class UserAdminController extends BaseAdminController {

    public function __construct()
    {
        parent::__construct();
    }

    
    /**
     * Show the user list page.
     */
    public function index(Request $request, Response $response): Response {
        $view = $this->viewFromRequest($request);

        $allUsers = $this->getUsers(); 

        
        $safeUsers = array_map(function($user) {
            unset($user['password_hash']);
            return $user;
        }, $allUsers); 

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
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        $loggedInUserRole = $request->getAttribute('user')['role'] ?? 'customer';

        $user = $request->getAttribute('user');
        $actorId = $user ? (int)$user['id'] : null;

        if ($data['role'] === 'superadmin' && $loggedInUserRole !== 'superadmin') {
            $data['role'] = 'customer';
        }

        $existingUser = $this->findUserByEmail($data['email']);
        if ($existingUser) {
            $url = $routeParser->urlFor('app.users.create') . '?error=email_exists';
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
        
        // --- LOG ACTIVITY ---
        $userAfter = $this->findUserById($newId);
        $this->logEntityChange(
            $actorId,
            'create',
            'user',
            $newId,
            null,
            $userAfter
        );

        return $response->withHeader('Location', $routeParser->urlFor('app.users.index'))->withStatus(302);
    }

    /**
     * Show the form to edit an existing user.
     */
    public function edit(Request $request, Response $response, array $args): Response
    {
        $view = $this->viewFromRequest($request);
        $id = (int)$args['id'];
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        
        $loggedInUserRole = $request->getAttribute('user')['role'] ?? 'customer';
        
        $foundUser = $this->findUserById($id);


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
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        $loggedInUserRole = $request->getAttribute('user')['role'] ?? 'customer';

        $user = $request->getAttribute('user');
        $actorId = $user ? (int)$user['id'] : null;

        $userBefore = $this->findUserById($id);

        if (!$userBefore) {
            return $response->withHeader('Location', $routeParser->urlFor('app.users.index'))->withStatus(302);
        }

        
        $isTargetSuperAdmin = $userBefore['role'] === 'superadmin';
        $isAttemptingPromotion = $data['role'] === 'superadmin';

        $roleToSet = $data['role'];
        $isActiveToSet = isset($data['is_active']);

        if ($isAttemptingPromotion && $loggedInUserRole !== 'superadmin') {
            $roleToSet = 'customer';
        }
        
        if ($isTargetSuperAdmin && $loggedInUserRole !== 'superadmin') {
            $roleToSet = 'superadmin';
            $isActiveToSet = $userBefore['is_active'];
        }

        $updateData = [
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'role' => $roleToSet,
            'is_active' => $isActiveToSet ? 1 : 0
        ];
        
        if (!empty($data['password'])) {
            $updateData['password_hash'] = password_hash($data['password'], PASSWORD_DEFAULT);
        }

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


        return $response->withHeader('Location', $routeParser->urlFor('app.users.index'))->withStatus(302);
    }
}