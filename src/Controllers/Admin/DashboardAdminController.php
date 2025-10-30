<?php
namespace SweetDelights\Mayie\Controllers\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class DashboardAdminController extends BaseAdminController
{
    public function dashboard(Request $request, Response $response): Response
    {
        // Use Twig view from base class
        $view = $this->viewFromRequest($request);

        // Render the default dashboard (no breadcrumbs)
        return $view->render($response, 'Admin/dashboard.twig', [
            'title' => 'Admin Dashboard',
            'active_page' => 'dashboard',
            'show_breadcrumbs' => false,
        ]);
    }
}
