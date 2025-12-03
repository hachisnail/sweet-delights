<?php
namespace SweetDelights\Mayie\Controllers\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteContext;

class ProductDiscountAdminController extends BaseAdminController
{
    /**
     * Show all product discounts
     */
    public function index(Request $request, Response $response): Response
    {
        $view = $this->viewFromRequest($request);

        $stmt = $this->db->query("
            SELECT pd.*, p.name AS product_name, p.sku AS product_sku, c.name AS category_name
            FROM product_discounts pd
            LEFT JOIN products p ON p.id = pd.product_id
            LEFT JOIN categories c ON c.id = pd.category_id
            ORDER BY pd.id DESC
        ");
        $discounts = $stmt->fetchAll();

        $breadcrumbs = $this->breadcrumbs($request, [
            ['name' => 'Product Discounts', 'url' => null],
        ]);

        return $view->render($response, 'Admin/product-discounts.twig', [
            'title' => 'Product Discounts',
            'discounts' => $discounts,
            'active_page' => 'product_discounts',
            'breadcrumbs' => $breadcrumbs,
        ]);
    }

    /**
     * Show form to create a new discount
     */
    public function create(Request $request, Response $response): Response
    {
        $view = $this->viewFromRequest($request);

        $products = $this->db->query("
            SELECT id, name, sku, image FROM products ORDER BY name ASC
        ")->fetchAll();

        $categories = $this->db->query("
            SELECT id, name, slug FROM categories ORDER BY name ASC
        ")->fetchAll();

        $routeParser = RouteContext::fromRequest($request)->getRouteParser();

        $breadcrumbs = $this->breadcrumbs($request, [
            ['name' => 'Product Discounts', 'url' => 'admin.discounts'],
            ['name' => 'Add New', 'url' => null],
        ]);

        return $view->render($response, 'Admin/product-discount-form.twig', [
            'title' => 'Add Discount',
            'products' => $products,
            'categories' => $categories, 
            'discount' => null,
            'active_page' => 'product_discounts',
            'breadcrumbs' => $breadcrumbs,
            'form_action' => $routeParser->urlFor('admin.discount.store'),
        ]);
    }

    /**
     * Store a new discount
     */
    public function store(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        $user = $request->getAttribute('user');
        $actorId = $user ? (int)$user['id'] : null;

        $startDate = null;
        if (!empty($data['start_date_date']) && !empty($data['start_date_time'])) {
            $startDate = $data['start_date_date'] . ' ' . $data['start_date_time'] . ':00';
        }

        $endDate = null;
        if (!empty($data['end_date_date']) && !empty($data['end_date_time'])) {
            $endDate = $data['end_date_date'] . ' ' . $data['end_date_time'] . ':00';
        }

        $productId = null;
        $categoryId = null;
        
        if ($data['discount_scope'] === 'product') {
            $productId = $data['product_id'] ?? null;
        } elseif ($data['discount_scope'] === 'category') {
            $categoryId = $data['category_id'] ?? null;
        }

        $stmt = $this->db->prepare("
            INSERT INTO product_discounts 
            (product_id, category_id, discount_type, discount_value, start_date, end_date, active)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $productId,
            $categoryId,
            $data['discount_type'],
            $data['discount_value'],
            $startDate,
            $endDate,
            isset($data['active']) ? 1 : 0
        ]);

        $newId = (int)$this->db->lastInsertId();
        $newDiscount = $this->findDiscountById($newId);

        $this->logEntityChange($actorId, 'create', 'product_discount', $newId, null, $newDiscount);

        return $response->withHeader('Location', $routeParser->urlFor('admin.discounts'))->withStatus(302);
    }

    /**
     * Show form to edit an existing discount
     */
    public function edit(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $view = $this->viewFromRequest($request);
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();

        $discount = $this->findDiscountById($id);

        if (!$discount) {
            return $response->withStatus(404);
        }

        if ($discount['start_date']) {
            $discount['start_date_date'] = date('Y-m-d', strtotime($discount['start_date']));
            $discount['start_date_time'] = date('H:i', strtotime($discount['start_date']));
        }
        if ($discount['end_date']) {
            $discount['end_date_date'] = date('Y-m-d', strtotime($discount['end_date']));
            $discount['end_date_time'] = date('H:i', strtotime($discount['end_date']));
        }

        $products = $this->db->query("
            SELECT id, name, sku, image FROM products ORDER BY name ASC
        ")->fetchAll();

        $categories = $this->db->query("
            SELECT id, name, slug FROM categories ORDER BY name ASC
        ")->fetchAll();

        $breadcrumbs = $this->breadcrumbs($request, [
            ['name' => 'Product Discounts', 'url' => 'admin.discounts'],
            ['name' => $discount['id'], 'url' => null],
        ]);

        return $view->render($response, 'Admin/product-discount-form.twig', [
            'title' => 'Edit Discount',
            'discount' => $discount,
            'products' => $products,
            'categories' => $categories, 
            'active_page' => 'product_discounts',
            'breadcrumbs' => $breadcrumbs,
            'form_action' => $routeParser->urlFor('admin.discount.update', ['id' => $id]),
        ]);
    }


    /**
     * Update an existing discount
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $data = $request->getParsedBody();
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        $user = $request->getAttribute('user');
        $actorId = $user ? (int)$user['id'] : null;

        $oldDiscount = $this->findDiscountById($id);

        $startDate = null;
        if (!empty($data['start_date_date']) && !empty($data['start_date_time'])) {
            $startDate = $data['start_date_date'] . ' ' . $data['start_date_time'] . ':00';
        }
        $endDate = null;
        if (!empty($data['end_date_date']) && !empty($data['end_date_time'])) {
            $endDate = $data['end_date_date'] . ' ' . $data['end_date_time'] . ':00';
        }

        $productId = null;
        $categoryId = null;
        
        if ($data['discount_scope'] === 'product') {
            $productId = $data['product_id'] ?? null;
        } elseif ($data['discount_scope'] === 'category') {
            $categoryId = $data['category_id'] ?? null;
        }

        $stmt = $this->db->prepare("
            UPDATE product_discounts
            SET product_id = ?, category_id = ?, discount_type = ?, discount_value = ?, 
                start_date = ?, end_date = ?, active = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $productId,
            $categoryId,
            $data['discount_type'],
            $data['discount_value'],
            $startDate,
            $endDate,
            isset($data['active']) ? 1 : 0,
            $id
        ]);

        $newDiscount = $this->findDiscountById($id);
        $this->logEntityChange($actorId, 'update', 'product_discount', $id, $oldDiscount, $newDiscount);

        return $response->withHeader('Location', $routeParser->urlFor('admin.discounts'))->withStatus(302);
    }
    /**
     * Delete a discount
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();

        $user = $request->getAttribute('user');
        $actorId = $user ? (int)$user['id'] : null;

        $discountToDelete = $this->findDiscountById($id);
        if (!$discountToDelete) {
            return $response->withHeader('Location', $routeParser->urlFor('admin.discounts'))->withStatus(302);
        }

        $stmt = $this->db->prepare("DELETE FROM product_discounts WHERE id = ?");
        $stmt->execute([$id]);

        $this->logEntityChange(
            $actorId,
            'delete',
            'product_discount',
            $id,
            $discountToDelete,
            null
        );

        return $response->withHeader('Location', $routeParser->urlFor('admin.discounts'))->withStatus(302);
    }

    /**
     * Helper: Get discount by ID
     */
    private function findDiscountById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM product_discounts WHERE id = ?");
        $stmt->execute([$id]);
        $discount = $stmt->fetch();
        return $discount ?: null;
    }
}
