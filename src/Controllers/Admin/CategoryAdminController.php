<?php
namespace SweetDelights\Mayie\Controllers\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Slim\Routing\RouteContext;

class CategoryAdminController extends BaseAdminController
{
    private $dataPath;
    private $productsPath;
    private $uploadDir; 

    public function __construct()
    {
        $this->dataPath = __DIR__ . '/../../Data/categories.php'; 
        $this->productsPath = __DIR__ . '/../../Data/products.php';
        $this->uploadDir = __DIR__ . '/../../../public/Assets/Categories/'; 
    }

    // --- Data Helper Functions ---
    private function getCategories(): array
    {
        if (!file_exists($this->dataPath)) {
            return [];
        }
        return require $this->dataPath;
    }
    
    private function getProducts(): array
    {
        if (!file_exists($this->productsPath)) {
            return [];
        }
        return require $this->productsPath;
    }

    private function saveCategories(array $categories)
    {
        // ✅ Uses inherited saveData method
        $this->saveData($this->dataPath, $categories);
    }
    
    private function saveProducts(array $products)
    {
        // ✅ Uses inherited saveData method
        $this->saveData($this->productsPath, $products);
    }
    
    // 3. ✅ NEW: File Upload Helper (specific to this controller)
    private function uploadCategoryPicture(int $categoryId, Request $request): ?string
    {
        $uploadedFiles = $request->getUploadedFiles();
        $file = $uploadedFiles['image'] ?? null;

        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            return null; // No file uploaded or there was an error
        }

        // Basic validation and security (You may want more robust validation)
        $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
        if (!in_array($file->getClientMediaType(), $allowedTypes)) {
            return null; // Invalid file type
        }
        
        $originalFilename = $file->getClientFilename();
        $extension = pathinfo($originalFilename, PATHINFO_EXTENSION); // Using pathinfo for extension

        // Create a new, unique filename based on the category ID
        $newFilename = $categoryId . '.' . strtolower($extension);
        $targetPath = $this->uploadDir . $newFilename;

        // Ensure the directory exists
        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0777, true);
        }

        // Move the file
        $file->moveTo($targetPath);
        
        return $newFilename;
    }

    // --- Find/Tree Helpers (Remain Local) ---

    private function findCategory(int $id): ?array
    {
        foreach ($this->getCategories() as $category) {
            if ($category['id'] === $id) {
                return $category;
            }
        }
        return null;
    }

    private function findCategoryName(int $id): string
    {
        $category = $this->findCategory($id);
        if ($category) {
            return "'" . htmlspecialchars($category['name'], ENT_QUOTES) . "'";
        }
        return 'this category';
    }

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

    // --- Slug Helper (needed for 'store' method, can remain local for simplicity) ---
    private function createSlug(string $name): string
    {
        return str_replace(' ', '-', strtolower(trim(preg_replace('/[^A-Za-z0-9 ]/', '', $name))));
    }

    // --- CRUD Methods ---

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
        // Filter for top-level categories only (for the dropdown)
        $topLevelCategories = array_filter($allCategories, fn($cat) => $cat['parent_id'] === null);

        $breadcrumbs = $this->breadcrumbs($request, [
            ['name' => 'Categories', 'url' => 'app.categories.index'],
            ['name' => 'Add New', 'url' => null],
        ]);

        return $view->render($response, $template, [
            'title' => 'Add New Category',
            'form_action' => $routeParser->urlFor('app.categories.store'),
            'top_level_categories' => $topLevelCategories, 
            'all_categories' => $allCategories, 
            'form_mode' => 'create', 
            'breadcrumbs' => $breadcrumbs,
            'active_page' => 'categories',
            'app_url' => $_ENV['APP_URL'] ?? '',
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $categories = $this->getCategories();
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();

        $newParentId = $data['parent_id'] ? (int)$data['parent_id'] : null;
        $subCategoryNames = $data['sub_category_names'] ?? [];
        $newId = 1;
        if (count($categories) > 0) {
            $newId = max(array_column($categories, 'id')) + 1;
        }

        $newParentImageFilename = null; 

        // --- LOGIC FOR ADVANCED FORM ---

        if ($newParentId === -1) {
            // --- SCENARIO 1: Create new Top-Level Category + Sub-categories ---
            
            $newParentName = $data['new_parent_name'] ?? 'New Category';
            $newParentSlug = $this->createSlug($newParentName);
            $newParentId = $newId; // This is the ID for the new parent
            
            $newParentImageFilename = $this->uploadCategoryPicture($newParentId, $request);

            // Add the new parent
            $categories[] = [
                'id' => $newParentId,
                'parent_id' => null,
                'name' => $newParentName,
                'slug' => $newParentSlug,
                'image' => $newParentImageFilename, 
            ];
            
            $newId++; // Increment ID for the sub-categories

        } else if ($newParentId !== null) {
            // Note: Image upload is generally handled in the edit form for existing categories.
        }
        
        // --- SCENARIO 2: Add Sub-categories to Existing (or new) Parent ---
        
        foreach ($subCategoryNames as $subName) {
            if (!empty(trim($subName))) {
                $slug = $this->createSlug($subName);
                $categories[] = [
                    'id' => $newId,
                    'parent_id' => $newParentId, 
                    'name' => $subName,
                    'slug' => $slug,
                    'image' => null, // Sub-categories do not get an image from this form
                ];
                $newId++;
            }
        }

        // --- END ADVANCED LOGIC ---

        $this->saveCategories($categories);

        return $response->withHeader('Location', $routeParser->urlFor('app.categories.index'))->withStatus(302);
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $view = $this->viewFromRequest($request);
        $routeParser = RouteContext::fromRequest($request)->getRouteParser(); 
        $id = (int)$args['id'];
        $category = $this->findCategory($id);

        if (!$category) {
            return $response->withHeader('Location', $routeParser->urlFor('app.categories.index'))->withStatus(302);
        }
        
        $template = 'Admin/category-form.twig';
        
        $allCategories = $this->getCategories();
        
        // Filter for top-level categories
        $topLevelCategories = array_filter($allCategories, fn($cat) => $cat['parent_id'] === null);

        $breadcrumbs = $this->breadcrumbs($request, [
            ['name' => 'Categories', 'url' => 'app.categories.index'],
            ['name' => $category['name'] ?? 'Edit', 'url' => null],
        ]);

        return $view->render($response, $template, [
            'title' => 'Edit Category',
            'category' => $category,
            'all_categories' => $allCategories, 
            'top_level_categories' => $topLevelCategories, 
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
        $categories = $this->getCategories();
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        
        $newParentId = $data['parent_id'] ? (int)$data['parent_id'] : null;

        // --- Cycle Check ---
        if ($newParentId !== null) {
            if ($newParentId === $id) {
                $url = $routeParser->urlFor('app.categories.index') . '?error=cycle&id=' . $id;
                return $response->withHeader('Location', $url)->withStatus(302);
            }
            
            $descendantIds = $this->getDescendantIds($categories, $id);
            if (in_array($newParentId, $descendantIds)) {
                $url = $routeParser->urlFor('app.categories.index') . '?error=cycle&id=' . $id;
                return $response->withHeader('Location', $url)->withStatus(302);
            }
        }
        
        $slug = $this->createSlug($data['name']);
        
        // ✅ Upload new image if provided
        $uploadedFilename = $this->uploadCategoryPicture($id, $request);

        // Update the category in the array
        foreach ($categories as $index => &$category) {
            if ($category['id'] === $id) {
                $category['name'] = $data['name'];
                $category['parent_id'] = $newParentId;
                $category['slug'] = $slug;
                
                // ✅ Update the image field if a new file was uploaded
                if ($uploadedFilename !== null) {
                    $category['image'] = $uploadedFilename;
                }
                // ✅ Handle image deletion if requested
                if (isset($data['delete_image']) && $data['delete_image'] === '1' && isset($category['image'])) {
                    // Delete the file from the filesystem
                    $imagePath = $this->uploadDir . $category['image'];
                    if (file_exists($imagePath)) {
                        unlink($imagePath);
                    }
                    $category['image'] = null;
                }
                break;
            }
        }
        unset($category); 

        // Save the updated categories list FIRST
        $this->saveCategories($categories);
        
        // --- START: REGENERATE PRODUCT SKUS ---
        
        $allCategoryIdsToUpdate = array_merge([$id], $this->getDescendantIds($categories, $id));
        $allProducts = $this->getProducts();
        $productsWereUpdated = false;
        
        foreach ($allProducts as &$product) {
            if (isset($product['category_id']) && in_array($product['category_id'], $allCategoryIdsToUpdate)) {
                
                // ✅ Uses inherited generateSku method
                $newSku = $this->generateSku(
                    $product['name'], 
                    $product['id'], 
                    (int)$product['category_id'], 
                    $categories
                );
                
                if (!isset($product['sku']) || $product['sku'] !== $newSku) {
                    $product['sku'] = $newSku;
                    $productsWereUpdated = true;
                }
            }
        }
        unset($product);
        
        if ($productsWereUpdated) {
            $this->saveProducts($allProducts);
        }
        
        // --- END: REGENERATE PRODUCT SKUS ---
        
        return $response->withHeader('Location', $routeParser->urlFor('app.categories.index'))->withStatus(302);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $categories = $this->getCategories();
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        $categoryToDelete = $this->findCategory($id); // Find the category before deletion

        // --- CHECK IF CATEGORY IS IN USE ---
        $isCategoryInUse = false;
        foreach ($this->getProducts() as $product) {
            if (isset($product['category_id']) && $product['category_id'] == $id) {
                $isCategoryInUse = true;
                break;
            }
        }

        if ($isCategoryInUse) {
            $url = $routeParser->urlFor('app.categories.index') . '?error=in_use&id=' . $id;
            return $response->withHeader('Location', $url)->withStatus(302);
        }
        // --- END CHECK ---

        $newCategories = array_filter($categories, fn($cat) => $cat['id'] !== $id);
        
        foreach ($newCategories as &$cat) {
            if ($cat['parent_id'] === $id) {
                $cat['parent_id'] = null; 
            }
        }
        unset($cat);

        $this->saveCategories($newCategories);
        
        // ✅ Delete image file from filesystem after successful deletion
        if ($categoryToDelete && isset($categoryToDelete['image']) && $categoryToDelete['image']) {
            $imagePath = $this->uploadDir . $categoryToDelete['image'];
            if (file_exists($imagePath)) {
                unlink($imagePath);
            }
        }
        
        return $response->withHeader('Location', $routeParser->urlFor('app.categories.index'))->withStatus(302);
    }
}