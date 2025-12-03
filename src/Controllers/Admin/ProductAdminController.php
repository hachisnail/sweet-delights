<?php
namespace SweetDelights\Mayie\Controllers\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Slim\Routing\RouteContext;
use Slim\Psr7\UploadedFile;
use \PDO; 

class ProductAdminController extends BaseAdminController
{
    private $uploadDir; 
    private $publicUploadPath;

    public function __construct()
    {

        parent::__construct(); 
        
        $this->uploadDir = __DIR__ . '/../../../public/Assets/Products/';
        $this->publicUploadPath = '/Assets/Products/';
    }


    /**
     * Finds a single product and its sizes from the database.
     */
    private function findProductById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT *, (is_listed = 1) as is_listed FROM products WHERE id = ?");
        $stmt->execute([$id]);
        $product = $stmt->fetch();

        if (!$product) {
            return null;
        }

        $sizeStmt = $this->db->prepare("SELECT * FROM product_sizes WHERE product_id = ?");
        $sizeStmt->execute([$id]);
        $sizes = $sizeStmt->fetchAll();

        $product['sizes'] = $sizes;
        
        if (!empty($sizes)) {
             $product['stock'] = array_sum(array_column($sizes, 'stock'));
        }
        
        return $product;
    }

    private function getIndentedCategories(array $allCategories, $parentId = null, int $level = 0): array
    {
        $indentedList = [];
        $prefix = str_repeat('&nbsp;&nbsp;&nbsp;', $level); 

        foreach ($allCategories as $category) {
            if ($category['parent_id'] == $parentId) {
                $category['indented_name'] = $prefix . $category['name'];
                $indentedList[] = $category;
                
                $children = $this->getIndentedCategories($allCategories, $category['id'], $level + 1);
                $indentedList = array_merge($indentedList, $children);
            }
        }
        return $indentedList;
    }


    // ---  File Upload Helpers (Unchanged) ---
    /**
     * @throws \Exception
     */
    private function handleUpload(?UploadedFile $uploadedFile): ?string
    {
        if (!$uploadedFile) {
            return null;
        }

        if ($uploadedFile->getError() !== UPLOAD_ERR_OK) {
            switch ($uploadedFile->getError()) {
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    throw new \Exception('File is too large.');
                case UPLOAD_ERR_NO_FILE:
                    return null; 
                default:
                    throw new \Exception('An unknown error occurred during file upload.');
            }
        }
        $filename = $this->moveUploadedFile($this->uploadDir, $uploadedFile);
        return $this->publicUploadPath . $filename;
    }
    
    /**
     * @throws \Exception
     */
    function moveUploadedFile(string $directory, UploadedFile $uploadedFile): string
    {
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0775, true)) {
                throw new \Exception("Upload directory does not exist and could not be created: " . $directory);
            }
        }
        if (!is_writable($directory)) {
            throw new \InvalidArgumentException("Upload target path is not writable: " . $directory);
        }

        $extension = pathinfo($uploadedFile->getClientFilename(), PATHINFO_EXTENSION);
        $basename = bin2hex(random_bytes(8));
        $filename = sprintf('%s.%0.8s', $basename, $extension);
        
        $uploadedFile->moveTo($directory . DIRECTORY_SEPARATOR . $filename);
        return $filename;
    }
    
    private function processProductData(array $data, array $sizeImageFiles): array
    {
        $sizes = [];
        $totalStock = 0;
        $prices = []; 

        if (isset($data['sizes_name']) && is_array($data['sizes_name'])) {
            foreach ($data['sizes_name'] as $index => $name) {
                $stock = $data['sizes_stock'][$index] ?? 0;
                $price = $data['sizes_price'][$index] ?? 0;
                
                if (!empty($name)) {
                    
                    $existingImagePath = $data['sizes_existing_image'][$index] ?? null;
                    $newImageFile = $sizeImageFiles[$index] ?? null;
                    $finalImagePath = $existingImagePath;

                    try {
                        if ($newImageFile && $newImageFile->getError() === UPLOAD_ERR_OK) {
                            $finalImagePath = $this->handleUpload($newImageFile);
                        }
                    } catch (\Exception $e) {
                        error_log("Error uploading size image: " . $e->getMessage());
                        $finalImagePath = $existingImagePath;
                    }

                    $sizeData = [
                        'name' => $name, 
                        'stock' => (int)$stock,
                        'price' => (float)$price,
                        'image' => $finalImagePath, 
                    ];
                    
                    $sizes[] = $sizeData;
                    $totalStock += (int)$stock;
                    $prices[] = (float)$price;
                }
            }
        }

        if (empty($sizes)) {
            $totalStock = (int)($data['stock'] ?? 0);
            $data['price'] = (float)($data['price'] ?? 0);
        } else {
            $data['price'] = !empty($prices) ? min($prices) : 0;
        }
        
        $data['sizes'] = $sizes;
        $data['stock_no_sizes'] = $totalStock; 
        
        return $data;
    }


    public function index(Request $request, Response $response): Response
    {
        $view = $this->viewFromRequest($request);
        
        $params = $request->getQueryParams();
        $searchTerm = $params['search'] ?? null;
        $categoryFilter = $params['category'] ?? null;

        $categories = $this->getCategories(); 
        
        $sql = "SELECT p.*, (p.is_listed = 1) as is_listed, c.name as category_name 
                FROM products p 
                LEFT JOIN categories c ON p.category_id = c.id";
        
        $queryParams = [];
        $whereClauses = [];

        if ($searchTerm) {
            $whereClauses[] = "(p.name LIKE ? OR p.sku LIKE ?)";
            $queryParams[] = "%$searchTerm%";
            $queryParams[] = "%$searchTerm%";
        }
        
        if ($categoryFilter && !empty($categoryFilter)) {
            $whereClauses[] = "p.category_id = ?";
            $queryParams[] = $categoryFilter;
        }

        if (!empty($whereClauses)) {
            $sql .= " WHERE " . implode(" AND ", $whereClauses);
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($queryParams);
        $products = $stmt->fetchAll();
        
        $products = $this->hydrateProductsWithSizes($products);
        
        $indentedCategories = $this->getIndentedCategories($categories);

        $breadcrumbs = $this->breadcrumbs($request, [
            ['name' => 'Products', 'url' => null]
        ]);

        return $view->render($response, 'Admin/products.twig', [
            'title' => 'Manage Products',
            'products' => $products,
            'all_categories' => $indentedCategories,
            'search_term' => $searchTerm,
            'category_filter' => (int)$categoryFilter,
            'app_url' => $_ENV['APP_URL'] ?? '',
            'breadcrumbs' => $breadcrumbs,
            'active_page' => 'products',
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $view = $this->viewFromRequest($request);
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        
        $rawCategories = $this->getCategories(); 
        $indentedCategories = $this->getIndentedCategories($rawCategories);

        $breadcrumbs = $this->breadcrumbs($request, [
            ['name' => 'Products', 'url' => 'app.products.index'],
            ['name' => 'Add New', 'url' => null]
        ]);
        
        return $view->render($response, 'Admin/product-form.twig', [
            'title' => 'Add New Product',
            'all_categories' => $indentedCategories, 
            'form_action' => $routeParser->urlFor('app.products.store'),
            'app_url' => $_ENV['APP_URL'] ?? '',
            'breadcrumbs' => $breadcrumbs,
            'active_page' => 'products',
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $files = $request->getUploadedFiles();
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        
        $user = $request->getAttribute('user');
        $actorId = $user ? (int)$user['id'] : null;

        $sizeImageFiles = $files['sizes_image'] ?? [];
        $data = $this->processProductData($data, $sizeImageFiles); 

        $imagePath = null;
        try {
            $imageFile = $files['image'] ?? null;
            $imagePath = $this->handleUpload($imageFile);
        } catch (\Exception $e) {
            error_log("File upload error: " . $e->getMessage());
        }

        $newId = null; 
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("
                INSERT INTO products (name, price, image, description, category_id, is_listed, sku) 
                VALUES (?, ?, ?, ?, ?, ?, '')
            ");
            $stmt->execute([
                $data['name'],
                (float)$data['price'],
                $imagePath ?? '/Assets/Products/placeholder.png', 
                $data['description'] ?? '',
                (int)($data['category_id'] ?? 0) ?: null,
                isset($data['is_listed']) ? 1 : 0 
            ]);
            
            $newId = $this->db->lastInsertId();

            $allCategories = $this->getCategories();
            $sku = $this->generateSku($data['name'], $newId, (int)($data['category_id'] ?? 0), $allCategories);
            
            $skuStmt = $this->db->prepare("UPDATE products SET sku = ? WHERE id = ?");
            $skuStmt->execute([$sku, $newId]);

            if (!empty($data['sizes'])) {
                $sizeStmt = $this->db->prepare("
                    INSERT INTO product_sizes (product_id, name, stock, price, image) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                foreach ($data['sizes'] as $size) {
                    $sizeStmt->execute([
                        $newId,
                        $size['name'],
                        (int)$size['stock'],
                        (float)$size['price'],
                        $size['image'] 
                    ]);
                }
            } else {
                $sizeStmt = $this->db->prepare("
                    INSERT INTO product_sizes (product_id, name, stock, price, image) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                $sizeStmt->execute([
                    $newId,
                    'Default',
                    (int)$data['stock_no_sizes'],
                    (float)$data['price'],
                    $imagePath ?? '/Assets/Products/placeholder.png' 
                ]);
            }

            $newProductData = $this->findProductById($newId);
            $this->logEntityChange(
                $actorId,
                'create',
                'product',
                $newId,
                null,
                $newProductData
            );

            $this->db->commit();

        } catch (\Exception $e) {
            $this->db->rollBack();
            error_log("Product store error: " . $e->getMessage());
        }

        return $response->withHeader('Location', $routeParser->urlFor('app.products.index'))->withStatus(302);
    }


    public function edit(Request $request, Response $response, array $args): Response
    {
        $view = $this->viewFromRequest($request);
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        $id = (int)$args['id'];
        
        $product = $this->findProductById($id);

        if (!$product) {
            return $response->withHeader('Location', $routeParser->urlFor('app.products.index'))->withStatus(302);
        }
        
        if (count($product['sizes']) === 1 && $product['sizes'][0]['name'] === 'Default') {
            $product['stock'] = $product['sizes'][0]['stock'];
            
            $product['image'] = $product['sizes'][0]['image'] ?? $product['image']; 
            
            $product['sizes'] = []; 
        }
        
        $rawCategories = $this->getCategories();
        $indentedCategories = $this->getIndentedCategories($rawCategories);
        
        $breadcrumbs = $this->breadcrumbs($request, [
            ['name' => 'Products', 'url' => 'app.products.index'],
            ['name' => $product['name'] ?? 'Edit', 'url' => null]
        ]);

        return $view->render($response, 'Admin/product-form.twig', [
            'title' => 'Edit Product',
            'product' => $product,
            'all_categories' => $indentedCategories, 
            'form_action' => $routeParser->urlFor('app.products.update', ['id' => $id]),
            'app_url' => $_ENV['APP_URL'] ?? '',
            'breadcrumbs' => $breadcrumbs,
            'active_page' => 'products',
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $data = $request->getParsedBody();
        $files = $request->getUploadedFiles();
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();

        $user = $request->getAttribute('user');
        $actorId = $user ? (int)$user['id'] : null;

        $oldProduct = $this->findProductById($id);
        if (!$oldProduct) {
            return $response->withHeader('Location', $routeParser->urlFor('app.products.index'))->withStatus(302);
        }

        $oldDefaultImage = null;
        if (count($oldProduct['sizes']) === 1 && $oldProduct['sizes'][0]['name'] === 'Default') {
            $oldDefaultImage = $oldProduct['sizes'][0]['image'];
        }

        $sizeImageFiles = $files['sizes_image'] ?? [];
        $data = $this->processProductData($data, $sizeImageFiles);
        
        $imagePath = $oldProduct['image']; 
        try {
            $imageFile = $files['image'] ?? null;
            $newImagePath = $this->handleUpload($imageFile);
            if ($newImagePath) {
                $imagePath = $newImagePath; 
            }
        } catch (\Exception $e) {
            error_log("File upload error: " . $e->getMessage());
        }

        $wasDefaultOnly = (count($oldProduct['sizes']) === 1 && $oldProduct['sizes'][0]['name'] === 'Default');
        $isNowVariant = !empty($data['sizes']);

        if ($wasDefaultOnly && $isNowVariant) {

            $oldProduct['sizes'][0]['image'] = $imagePath ?? $oldDefaultImage;
            
            array_unshift($data['sizes'], $oldProduct['sizes'][0]);
        }

        $this->db->beginTransaction();
        try {
            $allCategories = $this->getCategories();
            $newCategoryId = (int)($data['category_id'] ?? $oldProduct['category_id'] ?? 0);
            $newName = $data['name'] ?? $oldProduct['name'];
            $sku = $this->generateSku($newName, $id, $newCategoryId, $allCategories);

            $stmt = $this->db->prepare("
                UPDATE products 
                SET sku = ?, name = ?, price = ?, image = ?, description = ?, category_id = ?, is_listed = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $sku,
                $newName,
                (float)$data['price'],
                $imagePath, 
                $data['description'] ?? $oldProduct['description'] ?? '',
                $newCategoryId ?: null,
                isset($data['is_listed']) ? 1 : 0,
                $id
            ]);

            $delStmt = $this->db->prepare("DELETE FROM product_sizes WHERE product_id = ?");
            $delStmt->execute([$id]);

            if (!empty($data['sizes'])) {
                $sizeStmt = $this->db->prepare("
                    INSERT INTO product_sizes (product_id, name, stock, price, image) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                foreach ($data['sizes'] as $size) {
                    $sizeStmt->execute([
                        $id,
                        $size['name'],
                        (int)$size['stock'],
                        (float)$size['price'],
                        $size['image']
                    ]);
                }
            } else {
                $sizeStmt = $this->db->prepare("
                    INSERT INTO product_sizes (product_id, name, stock, price, image) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                $finalDefaultImage = $imagePath ?? $oldDefaultImage ?? '/Assets/Products/placeholder.png';
                $sizeStmt->execute([
                    $id,
                    'Default',
                    (int)$data['stock_no_sizes'],
                    (float)$data['price'],
                    $finalDefaultImage 
                ]);
            }

            $newProductData = $this->findProductById($id);
            $this->logEntityChange(
                $actorId,
                'update',
                'product',
                $id,
                $oldProduct,
                $newProductData
            );

            $this->db->commit();

        } catch (\Exception $e) {
            $this->db->rollBack();
            error_log("Product update error: " . $e->getMessage());
        }

        return $response->withHeader('Location', $routeParser->urlFor('app.products.index'))->withStatus(302);
    }
    
    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        
        $user = $request->getAttribute('user');
        $actorId = $user ? (int)$user['id'] : null;

        $productToDelete = $this->findProductById($id);

        if ($productToDelete) {
            $stmt = $this->db->prepare("DELETE FROM products WHERE id = ?");
            $stmt->execute([$id]);

            $this->logEntityChange(
                $actorId,
                'delete',
                'product',
                $id,
                $productToDelete,
                null
            );

            if (!empty($productToDelete['image'])) {
                $imagePath = $this->uploadDir . basename($productToDelete['image']);
                if (file_exists($imagePath) && !str_contains($imagePath, 'placeholder.png')) {
                    unlink($imagePath);
                }
            }

            if (!empty($productToDelete['sizes'])) {
                foreach ($productToDelete['sizes'] as $size) {
                    if (!empty($size['image'])) {
                         $imagePath = $this->uploadDir . basename($size['image']);
                        if (file_exists($imagePath) && !str_contains($imagePath, 'placeholder.png')) {
                            unlink($imagePath);
                        }
                    }
                }
            }
        }

        return $response->withHeader('Location', $routeParser->urlFor('app.products.index'))->withStatus(302);
    }
}