<?php
namespace SweetDelights\Mayie\Controllers\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

//  INHERIT from BaseAdminController
class UserAdminController extends BaseAdminController {

    /**
     * Show the customer users list.
     * This route is accessible to 'admin' and 'superadmin' roles.
     * It only displays users with the 'customer' role.
     */
    public function index(Request $request, Response $response): Response {
        //  USE helper from base class
        $view = $this->viewFromRequest($request);

        // Load all users from the mock data file
        $allUsers = require __DIR__ . '/../../Data/users.php';

        // Filter the users to only include 'customer' roles
        $customerUsers = array_filter($allUsers, function($user) {
            return $user['role'] === 'customer';
        });
        
        // Remove password hashes before sending to the template
        $safeUsers = array_map(function($user) {
            unset($user['password_hash']);
            return $user;
        }, $customerUsers);

        //  USE the breadcrumbs helper
        $breadcrumbs = $this->breadcrumbs($request, [
            ['name' => 'Customer Accounts', 'url' => null]
        ]);

        return $view->render($response, 'Admin/users.twig', [
            'title' => 'Customer Accounts',
            'users' => $safeUsers,
            'breadcrumbs' => $breadcrumbs, //  ADDED
            'active_page' => 'users_admin', //  ADDED
        ]);
    }
}

