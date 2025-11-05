<?php
namespace SweetDelights\Mayie\Controllers\Public;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
// Remove: use Slim\Views\Twig;
// Import the BaseAdminController to extend it
use SweetDelights\Mayie\Controllers\Admin\BaseAdminController;

// --- FIX: Extend the BaseAdminController ---
class HomeController extends BaseAdminController {

    // --- FIX: Call the parent constructor to get DB access ---
    public function __construct()
    {
        parent::__construct();
    }
    
    public function index(Request $request, Response $response): Response {
        // --- FIX: Use the inherited view helper ---
        $view = $this->viewFromRequest($request);
        
        // --- FIX: Use the inherited DB method instead of loading from a file ---
        $categories = $this->getCategories();
        
        // Only top-level categories
        $topCategories = array_filter($categories, fn($cat) => $cat['parent_id'] === null);

        return $view->render($response, 'Public/home.twig', [
            'title' => 'Welcome to FlourEver',
            'categories' => $topCategories,
            'app_url' => $_ENV['APP_URL'] ?? '',
        ]);
    }

}
