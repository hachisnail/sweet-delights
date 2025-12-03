<?php
namespace SweetDelights\Mayie\Controllers\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Slim\Routing\RouteContext;
use \PDO; 

class CategoryAdminController extends BaseAdminController
{

    private $uploadDir; 

    public function __construct()
    {
        parent::__construct(); 
        
        $this->uploadDir = __DIR__ . '/../../../public/Assets/Categories/'; 
    }

    
    /**
     * File Upload Helper (specific to this controller)
     * This logic remains unchanged as it deals with the filesystem.
     */
    private function uploadCategoryPicture(int $categoryId, Request $request): ?string
    {
        $uploadedFiles = $request->getUploadedFiles();
        $file = $uploadedFiles['image'] ?? null;

        if (!$file || $file->getError() !== \UPLOAD_ERR_OK) {
            return null; 
        }

        $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
        if (!in_array($file->getClientMediaType(), $allowedTypes)) {
            return null; 
        }
        
        $originalFilename = $file->getClientFilename();
        $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);

        $newFilename = $categoryId . '-' . time() . '.' . strtolower($extension);
        $targetPath = $this->uploadDir . $newFilename;

        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0775, true);
        }

        $file->moveTo($targetPath);
        
        return $newFilename; 
    }


    /**
     * NEW: Database-driven finder
     */
    private function findCategoryById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM categories WHERE id = ?");
        $stmt->execute([$id]);
        $category = $stmt->fetch();
        return $category ?: null;
    }

    private function findCategoryName(int $id): string
    {
        $category = $this->findCategoryById($id); 
        if ($category) {
            return "'" . htmlspecialchars($category['name'], ENT_QUOTES) . "'";
        }
        return 'this category';
    }

    /**
     * This function remains unchanged. It builds the tree
     * from the array provided by the (now DB-driven) getCategories().
     */
    private function buildTree(array $elements, $parentId = null): array
    {
        $branch = [];
        foreach ($elements as $element) {
            if ($element['parent_id'] == $parentId) {
                $children = $this->buildTree($elements, $element['id']);
                if ($children) {
                    $element['children'] = $children;
                }
                $branch[] = $element;
            }
        }
        return $branch;
    }

    /**
     * This function also remains unchanged.
     */
    private function getDescendantIds(array $allCategories, int $parentId): array
    {
        $ids = [];
        foreach ($allCategories as $category) {
            if ($category['parent_id'] == $parentId) {
                $ids[] = $category['id'];
                $ids = array_merge($ids, $this->getDescendantIds($allCategories, $category['id']));
            }
        }
        return $ids;
    }

    private function createSlug(string $name): string
    {
        return str_replace(' ', '-', strtolower(trim(preg_replace('/[^A-Za-z0-9 ]/', '', $name))));
    }


    public function index(Request $request, Response $response): Response
    {
        $view = $this->viewFromRequest($request);
        $categories = $this->getCategories(); 
        $nested = $this->buildTree($categories);

        $params = $request->getQueryParams();
        $error = $params['error'] ?? null;
        $errorId = $params['id'] ?? null;
        $errorMessage = null;

        if ($error === 'in_use' && $errorId) {
            $categoryName = $this->findCategoryName((int)$errorId);
            $errorMessage = "Cannot delete $categoryName because it is assigned to one or more products. Please move the products to another category before deleting.";
        } elseif ($error === 'cycle' && $errorId) {
            $categoryName = $this->findCategoryName((int)$errorId);
            $errorMessage = "Cannot update $categoryName. A category cannot be moved under one of its own sub-categories.";
        }

        return $view->render($response, 'Admin/categories.twig', [
            'title' => 'Manage Categories',
            'categories' => $nested,
            'all_categories' => $categories,
            'breadcrumbs' => $this->breadcrumbs($request, [
                ['name' => 'Categories', 'url' => null],
            ]),
            'active_page' => 'categories',
            'upload_url' => '/Assets/Categories/', 
            'app_url' => $_ENV['APP_URL'] ?? '',
            'error_message' => $errorMessage,
        ]);
    }

public function create(Request $request, Response $response): Response
    {
        $view = $this->viewFromRequest($request);
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        
        $template = 'Admin/category-form.twig'; 

        $allCategories = $this->getCategories();
        $nestedCategories = $this->buildTree($allCategories); 
        
        $breadcrumbs = $this->breadcrumbs($request, [
            ['name' => 'Categories', 'url' => 'app.categories.index'],
            ['name' => 'Add New', 'url' => null],
        ]);

        return $view->render($response, $template, [
            'title' => 'Add New Category',
            'form_action' => $routeParser->urlFor('app.categories.store'),
            
            'nested_categories' => $nestedCategories,

            'form_mode' => 'create', 
            'breadcrumbs' => $breadcrumbs,
            'active_page' => 'categories',
            'app_url' => $_ENV['APP_URL'] ?? '',
        ]);
    }



    public function store(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        
        $user = $request->getAttribute('user');
        $actorId = $user ? (int)$user['id'] : null;

        $newParentId = !empty($data['parent_id']) ? (int)$data['parent_id'] : null;
        $subCategoryNames = $data['sub_category_names'] ?? [];
        $newParentName = $data['new_parent_name'] ?? null;
        
        $this->db->beginTransaction();
        try {
            if ($newParentId === -1 && !empty($newParentName)) {
                
                $newParentSlug = $this->createSlug($newParentName);
                
                $stmt = $this->db->prepare("INSERT INTO categories (parent_id, name, slug) VALUES (NULL, ?, ?)");
                $stmt->execute([$newParentName, $newParentSlug]);
                
                $newParentId = (int)$this->db->lastInsertId(); 
                
                $newParentImageFilename = $this->uploadCategoryPicture($newParentId, $request);
                if ($newParentImageFilename) {
                    $imgStmt = $this->db->prepare("UPDATE categories SET image = ? WHERE id = ?");
                    $imgStmt->execute([$newParentImageFilename, $newParentId]);
                }

                $this->logEntityChange(
                    $actorId,
                    'create',                        
                    'category',                       
                    $newParentId,                   
                    null,                           
                    $this->findCategoryById($newParentId) 
                );
            }
            
            if ($newParentId !== null && $newParentId > 0 && !empty($subCategoryNames)) {
                $stmt = $this->db->prepare("INSERT INTO categories (parent_id, name, slug, image) VALUES (?, ?, ?, NULL)");
                foreach ($subCategoryNames as $subName) {
                    if (!empty(trim($subName))) {
                        $slug = $this->createSlug($subName);
                        $stmt->execute([$newParentId, $subName, $slug]);
                        $subId = (int)$this->db->lastInsertId(); 

                        $this->logEntityChange(
                            $actorId,
                            'create',          
                            'category',        
                            $subId,           
                            null,              
                            $this->findCategoryById($subId) 
                        );
                    }
                }
            }
            
            $this->db->commit();

        } catch (\Exception $e) {
            $this->db->rollBack();

            return $response->withHeader('Location', $routeParser->urlFor('app.categories.index') . '?error=unknown')->withStatus(302);
        }

        return $response->withHeader('Location', $routeParser->urlFor('app.categories.index'))->withStatus(302);
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $view = $this->viewFromRequest($request);
        $routeParser = RouteContext::fromRequest($request)->getRouteParser(); 
        $id = (int)$args['id'];
        $category = $this->findCategoryById($id); 

        if (!$category) {
            return $response->withHeader('Location', $routeParser->urlFor('app.categories.index'))->withStatus(302);
        }
        
        $template = 'Admin/category-form.twig';
        
        $allCategories = $this->getCategories(); 
        $nestedCategories = $this->buildTree($allCategories); 
        
        $breadcrumbs = $this->breadcrumbs($request, [
            ['name' => 'Categories', 'url' => 'app.categories.index'],
            ['name' => $category['name'] ?? 'Edit', 'url' => null],
        ]);

        return $view->render($response, $template, [
            'title' => 'Edit Category',
            'category' => $category,
            
            'nested_categories' => $nestedCategories, 

            'form_mode' => 'edit', 
            'form_action' => $routeParser->urlFor('app.categories.update', ['id' => $id]),
            'app_url' => $_ENV['APP_URL'] ?? '',
            'upload_url' => '/Assets/Categories/', 
            'breadcrumbs' => $breadcrumbs,
            'active_page' => 'categories',
        ]);
    }

public function update(Request $request, Response $response, array $args): Response
{
    $id = (int)$args['id'];
    $data = $request->getParsedBody();
    $allCategories = $this->getCategories(); 
    $routeParser = RouteContext::fromRequest($request)->getRouteParser();

    $user = $request->getAttribute('user');
    $actorId = $user ? (int)$user['id'] : null;

    $newParentId = !empty($data['parent_id']) ? (int)$data['parent_id'] : null;

    if ($newParentId !== null) {
        if ($newParentId === $id) { 
            $url = $routeParser->urlFor('app.categories.index') . '?error=cycle&id=' . $id;
            return $response->withHeader('Location', $url)->withStatus(302);
        }

        $descendantIds = $this->getDescendantIds($allCategories, $id);
        if (in_array($newParentId, $descendantIds)) {
            $url = $routeParser->urlFor('app.categories.index') . '?error=cycle&id=' . $id;
            return $response->withHeader('Location', $url)->withStatus(302);
        }
    }

    $slug = $this->createSlug($data['name']);
    $oldCategory = $this->findCategoryById($id);

    $finalImage = $oldCategory['image'] ?? null;

    $uploadedFilename = $this->uploadCategoryPicture($id, $request);
    if ($uploadedFilename !== null) {
        $finalImage = $uploadedFilename;
    } elseif (isset($data['delete_image']) && $data['delete_image'] === '1' && $finalImage) {
        $imagePath = $this->uploadDir . $finalImage;
        if (file_exists($imagePath)) {
            unlink($imagePath);
        }
        $finalImage = null;
    }

    $this->db->beginTransaction();
    try {
        $stmt = $this->db->prepare("UPDATE categories SET name = ?, parent_id = ?, slug = ?, image = ? WHERE id = ?");
        $stmt->execute([$data['name'], $newParentId, $slug, $finalImage, $id]);

        $allCategoryIdsToUpdate = array_merge([$id], $this->getDescendantIds($allCategories, $id));
        if (!empty($allCategoryIdsToUpdate)) {

            $placeholders = implode(',', array_fill(0, count($allCategoryIdsToUpdate), '?'));
            
            $productStmt = $this->db->prepare("SELECT id, name, category_id, sku FROM products WHERE category_id IN ($placeholders)");
            $productStmt->execute($allCategoryIdsToUpdate);
            $allProductsToUpdate = $productStmt->fetchAll();

            $updateProductSkuStmt = $this->db->prepare("UPDATE products SET sku = ? WHERE id = ?");
            $updateAssocStmt1 = $this->db->prepare("UPDATE product_associations SET product_sku_1 = ? WHERE product_sku_1 = ?");
            $updateAssocStmt2 = $this->db->prepare("UPDATE product_associations SET product_sku_2 = ? WHERE product_sku_2 = ?");

            $updatedCategories = $this->getCategories();

            foreach ($allProductsToUpdate as $product) {
                $oldSku = $product['sku'];
                $newSku = $this->generateSku(
                    $product['name'],
                    $product['id'],
                    (int)$product['category_id'],
                    $updatedCategories
                );

                if ($oldSku !== $newSku) {
                    $updateProductSkuStmt->execute([$newSku, $product['id']]);

                    $updateAssocStmt1->execute([$newSku, $oldSku]);
                    $updateAssocStmt2->execute([$newSku, $oldSku]);
                }
            }
        }

        $newCategory = $this->findCategoryById($id);
        $this->logEntityChange(
            $actorId,
            'update',
            'category',
            $id,
            $oldCategory,
            $newCategory
        );

        $this->db->commit();
    } catch (\Exception $e) {
        $this->db->rollBack();
        error_log("Category update error: " . $e->getMessage());
        return $response->withHeader('Location', $routeParser->urlFor('app.categories.index') . '?error=unknown')->withStatus(302);
    }

    return $response->withHeader('Location', $routeParser->urlFor('app.categories.index'))->withStatus(302);
}


public function delete(Request $request, Response $response, array $args): Response
{
    $id = (int)$args['id'];
    $routeParser = RouteContext::fromRequest($request)->getRouteParser();

    $user = $request->getAttribute('user');
    $actorId = $user ? (int)$user['id'] : null;

    $categoryToDelete = $this->findCategoryById($id);

    if (!$categoryToDelete) {
        return $response->withHeader('Location', $routeParser->urlFor('app.categories.index'))->withStatus(302);
    }

    $this->db->beginTransaction();
    try {
        $productStmt = $this->db->prepare("SELECT id, sku FROM products WHERE category_id = ?");
        $productStmt->execute([$id]);
        $products = $productStmt->fetchAll();

        if (!empty($products)) {
            $deleteAssocStmt = $this->db->prepare("DELETE FROM product_associations WHERE product_sku = ?");
            foreach ($products as $product) {
                $deleteAssocStmt->execute([$product['sku']]);
            }
        }

        if (!empty($products)) {
            $deleteProductsStmt = $this->db->prepare("DELETE FROM products WHERE category_id = ?");
            $deleteProductsStmt->execute([$id]);
        }

        $deleteCategoryStmt = $this->db->prepare("DELETE FROM categories WHERE id = ?");
        $deleteCategoryStmt->execute([$id]);

        $this->logEntityChange(
            $actorId,
            'delete',
            'category',
            $id,
            $categoryToDelete,
            null
        );

        if (!empty($categoryToDelete['image'])) {
            $imagePath = $this->uploadDir . $categoryToDelete['image'];
            if (file_exists($imagePath)) {
                unlink($imagePath);
            }
        }

        $this->db->commit();

    } catch (\Exception $e) {
        $this->db->rollBack();
        return $response->withHeader('Location', $routeParser->urlFor('app.categories.index') . '?error=unknown')->withStatus(302);
    }

    return $response->withHeader('Location', $routeParser->urlFor('app.categories.index'))->withStatus(302);
}

}