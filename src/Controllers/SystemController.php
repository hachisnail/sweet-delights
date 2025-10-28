<?php
namespace SweetDelights\Mayie\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class SystemController {

    /**
     * Show the system logs page.
     * This route is only accessible to 'superadmin' roles.
     */
    public function viewLogs(Request $request, Response $response): Response {
        $view = Twig::fromRequest($request);

        // In a real app, you would read from a log file or database.
        // For this mock, we'll just create some fake log entries.
        $mockLogs =  require __DIR__ . '/../Data/logs.php';

        return $view->render($response, 'Admin/system-logs.twig', [
            'title' => 'System Logs',
            'logs'  => $mockLogs
        ]);
    }

    public function manageUsers(Request $request, Response $response): Response {
    $view = Twig::fromRequest($request);

    // Fetch users from DB or mock data
    $mockUsers = require __DIR__ . '/../Data/users.php';

    return $view->render($response, 'Admin/manage-users.twig', [
        'title' => 'Manage Users',
        'users' => $mockUsers
    ]);
}

}
