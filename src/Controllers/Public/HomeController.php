<?php
namespace SweetDelights\Mayie\Controllers\Public;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SweetDelights\Mayie\Controllers\Admin\BaseAdminController;
use \PDO; // Make sure PDO is imported

class HomeController extends BaseAdminController {

    public function __construct()
    {
        parent::__construct();
    }
    
    /**
     * --- NEW ---
     * Fetches all active discounts (both product and category)
     */
    private function getAllActiveDiscounts(): array
    {
        $stmt = $this->db->query("
            (
                -- Get all active product discounts
                SELECT pd.*, p.name as item_name, p.sku as item_sku, 
                       p.image as item_image, 'product' as discount_scope
                FROM product_discounts pd
                JOIN products p ON p.id = pd.product_id
                WHERE pd.active = 1
                  AND pd.product_id IS NOT NULL
                  AND p.is_listed = 1
                  AND (pd.start_date IS NULL OR pd.start_date <= NOW())
                  AND (pd.end_date IS NULL OR pd.end_date >= NOW())
            )
            UNION
            (
                -- Get all active category discounts
                SELECT pd.*, c.name as item_name, c.slug as item_sku, 
                       c.image as item_image, 'category' as discount_scope
                FROM product_discounts pd
                JOIN categories c ON c.id = pd.category_id
                WHERE pd.active = 1
                  AND pd.category_id IS NOT NULL
                  AND (pd.start_date IS NULL OR pd.start_date <= NOW())
                  AND (pd.end_date IS NULL OR pd.end_date >= NOW())
            )
            ORDER BY end_date ASC, id DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * --- MODIFIED ---
     * Now also fetches and passes 'active_discounts'
     */
    public function index(Request $request, Response $response): Response {
        $view = $this->viewFromRequest($request);
        
        // Fetch categories (existing)
        $categories = $this->getCategories();
        $topCategories = array_filter($categories, fn($cat) => $cat['parent_id'] === null);

        // --- NEW ---
        // Fetch active discounts
        $activeDiscounts = $this->getAllActiveDiscounts();
        // --- END NEW ---

        return $view->render($response, 'Public/home.twig', [
            'title' => 'Welcome to FlourEver',
            'categories' => $topCategories,
            'active_discounts' => $activeDiscounts, // <-- Pass discounts to Twig
            'app_url' => $_ENV['APP_URL'] ?? '',
        ]);
    }
}