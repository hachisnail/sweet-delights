<?php
namespace SweetDelights\Mayie\Controllers\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

//  INHERIT from BaseAdminController
class SystemAdminController extends BaseAdminController {

    /**
     * Show the system logs page.
     * This route is only accessible to 'superadmin' roles.
     */
    public function viewLogs(Request $request, Response $response): Response {
        //  USE helper from base class
        $view = $this->viewFromRequest($request);

        // In a real app, you would read from a log file or database.
        // For this mock, we'll just create some fake log entries.
        $mockLogs =  require __DIR__ . '/../../Data/logs.php';

        $breadcrumbs = $this->breadcrumbs($request, [
            ['name' => 'System Logs', 'url' => null]
        ]);

        return $view->render($response, 'Admin/system-logs.twig', [
            'title' => 'System Logs',
            'logs'  => $mockLogs,
            'breadcrumbs' => $breadcrumbs, // 
            'active_page' => 'system_logs', 
        ]);
    }

    public function manageUsers(Request $request, Response $response): Response {
        //  USE helper from base class
        $view = $this->viewFromRequest($request);

        // Fetch users from DB or mock data
        $mockUsers = require __DIR__ . '/../../Data/users.php';

        //  USE the breadcrumbs helper
        $breadcrumbs = $this->breadcrumbs($request, [
            ['name' => 'Manage Users', 'url' => null]
        ]);

        return $view->render($response, 'Admin/manage-users.twig', [
            'title' => 'Manage Users',
            'users' => $mockUsers,
            'breadcrumbs' => $breadcrumbs, 
            'active_page' => 'system_users', 
        ]);
    }
}

