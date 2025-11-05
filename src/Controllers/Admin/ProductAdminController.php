<?php
namespace SweetDelights\Mayie\Controllers\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Slim\Routing\RouteContext;
use Slim\Psr7\UploadedFile;
use \PDO; // <-- Use PHP's built-in database object

// INHERIT from BaseAdminController
class ProductAdminController extends BaseAdminController
{
    private $uploadDir; // Physical server path
    private $publicUploadPath; // Public-facing URL path

    public function __construct()
    {
        // Call parent to set up $this->db
        parent::__construct(); 
        
        // Define paths specific to this controller
        $this->uploadDir = __DIR__ . '/../../../public/Assets/Products/';
        $this->publicUploadPath = '/Assets/Products/';
    }

    // --- ✅ START: REFACTORED DATA HELPERS ---

    /**
     * Finds a single product and its sizes from the database.
     */
    private function findProductById(int $id): ?array
    {
        // 1. Fetch the product
        // --- FIX: Cast is_listed to boolean ---
        $stmt = $this->db->prepare("SELECT *, (is_listed = 1) as is_listed FROM products WHERE id = ?");
        $stmt->execute([$id]);
        $product = $stmt->fetch();

        if (!$product) {
            return null;
        }

        // 2. Fetch its sizes
        $sizeStmt = $this->db->prepare("SELECT * FROM product_sizes WHERE product_id = ?");
        $sizeStmt->execute([$id]);
        $sizes = $sizeStmt->fetchAll();

        // 3. Hydrate the product
        $product['sizes'] = $sizes;
        
        // 4. Calculate total stock (if there are sizes)
        if (!empty($sizes)) {
             $product['stock'] = array_sum(array_column($sizes, 'stock'));
        }
        // Note: The 'stock' on the product itself is now virtual, 
        // calculated from its sizes.
        
        return $product;
    }

    // --- ✅ END: DATA HELPERS ---

    // ---  Recursive Helper for Indented Category List (Unchanged) ---
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
        $basename = bin2hex(random_bytes(8)); // create a unique random name
        $filename = sprintf('%s.%0.8s', $basename, $extension);
        
        $uploadedFile->moveTo($directory . DIRECTORY_SEPARATOR . $filename);
        return $filename;
    }
    
    // --- Data Processing Helper (Unchanged) ---
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
                    $finalImagePath = $existingImagePath; // Default to old path

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
            // No sizes defined, use the main stock/price fields
            $totalStock = (int)($data['stock'] ?? 0); // This is for products *without* sizes
            $data['price'] = (float)($data['price'] ?? 0);
        } else {
            // Has sizes, set base price to the minimum size price
            $data['price'] = !empty($prices) ? min($prices) : 0;
        }
        
        $data['sizes'] = $sizes;
        // This 'stock' is only used if the product *has no sizes*
        $data['stock_no_sizes'] = $totalStock; 
        
        return $data;
    }

    // --- CRUD Methods ---

    public function index(Request $request, Response $response): Response
    {
        $view = $this->viewFromRequest($request);
        
        $params = $request->getQueryParams();
        $searchTerm = $params['search'] ?? null;
        $categoryFilter = $params['category'] ?? null;

        // --- REFACTORED: Use SQL query with joins and where clauses ---
        $categories = $this->getCategories(); // <-- Uses inherited method
        
        // --- FIX: Select is_listed ---
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
        
        // --- Hydrate products with sizes and stock (from BaseAdminController) ---
        $products = $this->hydrateProductsWithSizes($products);
        // --- END REFACTOR ---
        
        $indentedCategories = $this->getIndentedCategories($categories);

        $breadcrumbs = $this->breadcrumbs($request, [
            ['name' => 'Products', 'url' => null]
        ]);

        return $view->render($response, 'Admin/products.twig', [
            'title' => 'Manage Products',
            'products' => $products,
            'all_categories' => $indentedCategories,
            'search_term' => $searchTerm,
            'category_filter' => (int)$categoryFilter, // Cast for template
            'app_url' => $_ENV['APP_URL'] ?? '',
            'breadcrumbs' => $breadcrumbs,
            'active_page' => 'products',
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $view = $this->viewFromRequest($request);
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        
        $rawCategories = $this->getCategories(); // <-- Uses inherited method
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
        
        // --- 1. GET ACTOR ---
        $user = $request->getAttribute('user');
        $actorId = $user ? (int)$user['id'] : null;

        $sizeImageFiles = $files['sizes_image'] ?? [];
        // Process form data, calculate base price and sizes
        $data = $this->processProductData($data, $sizeImageFiles); 

        $imagePath = null;
        try {
            $imageFile = $files['image'] ?? null;
            $imagePath = $this->handleUpload($imageFile);
        } catch (\Exception $e) {
            error_log("File upload error: " . $e->getMessage());
        }

        // --- REFACTORED: Database Transaction ---
        $newId = null; // Initialize newId
        $this->db->beginTransaction();
        try {
            // 1. Create the base product to get an ID
            // --- FIX: Add 'image' and 'is_listed' columns to this query ---
            $stmt = $this->db->prepare("
                INSERT INTO products (name, price, image, description, category_id, is_listed, sku) 
                VALUES (?, ?, ?, ?, ?, ?, '')
            ");
            $stmt->execute([
                $data['name'],
                (float)$data['price'],
                $imagePath ?? '/Assets/Products/placeholder.png', // <-- Main image added here
                $data['description'] ?? '',
                (int)($data['category_id'] ?? 0) ?: null,
                isset($data['is_listed']) ? 1 : 0 // <-- is_listed added here
            ]);
            
            $newId = $this->db->lastInsertId();

            // 2. Generate the SKU using the new ID
            $allCategories = $this->getCategories();
            $sku = $this->generateSku($data['name'], $newId, (int)($data['category_id'] ?? 0), $allCategories);
            
            // 3. Update the product with its new SKU
            $skuStmt = $this->db->prepare("UPDATE products SET sku = ? WHERE id = ?");
            $skuStmt->execute([$sku, $newId]);

            // 4. Save sizes (if any)
            if (!empty($data['sizes'])) {
                // --- FIX: This query is now correct because of the ALTER TABLE ---
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
                        $size['image'] // This is the per-variant image
                    ]);
                }
            } else {
                // No sizes, insert one "default" size entry to hold the stock
                // --- FIX: This query is also correct ---
                $sizeStmt = $this->db->prepare("
                    INSERT INTO product_sizes (product_id, name, stock, price, image) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                $sizeStmt->execute([
                    $newId,
                    'Default', // Use a default name
                    (int)$data['stock_no_sizes'],
                    (float)$data['price'],
                    $imagePath ?? '/Assets/Products/placeholder.png' // <-- Use main image
                ]);
            }

            // --- 5. LOG ACTIVITY ---
            // We fetch the *full* product data *after* all inserts/updates
            $newProductData = $this->findProductById($newId);
            $this->logEntityChange(
                $actorId,
                'create',
                'product',
                $newId,
                null,
                $newProductData
            );
            // --- END LOG ---

            // 6. Commit
            $this->db->commit();

        } catch (\Exception $e) {
            $this->db->rollBack();
            error_log("Product store error: " . $e->getMessage());
            // You would ideally redirect with an error message here
        }
        // --- END REFACTOR ---

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
        
        // If product has no sizes, move its single stock value to the 'stock' key
        // for the form to display correctly.
        if (count($product['sizes']) === 1 && $product['sizes'][0]['name'] === 'Default') {
            $product['stock'] = $product['sizes'][0]['stock'];
            
            // --- FIX: Use the 'Default' size's image as the main 'product.image' for the form ---
            // BUT, keep the 'products.image' as the fallback
            $product['image'] = $product['sizes'][0]['image'] ?? $product['image']; 
            
            $product['sizes'] = []; // Clear sizes so the "sizes" form doesn't show
        }
        // If it *has* sizes, product.image (the main one) will be used correctly.
        
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

        // --- 1. GET ACTOR ---
        $user = $request->getAttribute('user');
        $actorId = $user ? (int)$user['id'] : null;

        // --- 2. GET "BEFORE" STATE ---
        $oldProduct = $this->findProductById($id);
        if (!$oldProduct) {
            return $response->withHeader('Location', $routeParser->urlFor('app.products.index'))->withStatus(302);
        }

        // --- Find the old "Default" image if it exists ---
        $oldDefaultImage = null;
        if (count($oldProduct['sizes']) === 1 && $oldProduct['sizes'][0]['name'] === 'Default') {
            $oldDefaultImage = $oldProduct['sizes'][0]['image'];
        }

        $sizeImageFiles = $files['sizes_image'] ?? [];
        $data = $this->processProductData($data, $sizeImageFiles);
        
        // --- FIX: Use the *product's* main image as fallback, not the default size image ---
        $imagePath = $oldProduct['image']; // Start with the main image from DB
        try {
            $imageFile = $files['image'] ?? null;
            $newImagePath = $this->handleUpload($imageFile);
            if ($newImagePath) {
                $imagePath = $newImagePath; // A new main image was uploaded, use it
            }
        } catch (\Exception $e) {
            error_log("File upload error: " . $e->getMessage());
        }

        // --- ✅ NEW LOGIC: Check if we are converting a "Default" product to a "Variant" product ---
        $wasDefaultOnly = (count($oldProduct['sizes']) === 1 && $oldProduct['sizes'][0]['name'] === 'Default');
        $isNowVariant = !empty($data['sizes']);

        if ($wasDefaultOnly && $isNowVariant) {
            // This is the scenario: The user just added their first "real" variant
            // to a product that was previously non-variant.
            // We must re-add the original "Default" size to the beginning of the
            // $data['sizes'] array so it gets preserved.
            
            // Note: The main 'imagePath' is now stored on the 'products' table.
            // We need to make sure the 'Default' size row uses it.
            $oldProduct['sizes'][0]['image'] = $imagePath ?? $oldDefaultImage;
            
            array_unshift($data['sizes'], $oldProduct['sizes'][0]);
        }
        // --- END NEW LOGIC ---

        // --- REFACTORED: Database Transaction ---
        $this->db->beginTransaction();
        try {
            // 1. Regenerate SKU
            $allCategories = $this->getCategories();
            $newCategoryId = (int)($data['category_id'] ?? $oldProduct['category_id'] ?? 0);
            $newName = $data['name'] ?? $oldProduct['name'];
            $sku = $this->generateSku($newName, $id, $newCategoryId, $allCategories);

            // 2. Update the main product
            // --- FIX: Add 'image' and 'is_listed' columns to this query ---
            $stmt = $this->db->prepare("
                UPDATE products 
                SET sku = ?, name = ?, price = ?, image = ?, description = ?, category_id = ?, is_listed = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $sku,
                $newName,
                (float)$data['price'],
                $imagePath, // <-- Main image added here
                $data['description'] ?? $oldProduct['description'] ?? '',
                $newCategoryId ?: null,
                isset($data['is_listed']) ? 1 : 0, // <-- is_listed added here
                $id
            ]);

            // 3. Delete all old sizes for this product
            $delStmt = $this->db->prepare("DELETE FROM product_sizes WHERE product_id = ?");
            $delStmt->execute([$id]);

            // 4. Save new sizes (if any)
            if (!empty($data['sizes'])) {
                // --- FIX: This query is now correct ---
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
                 // No sizes, insert one "default" size entry to hold the stock
                 // --- FIX: This query is also correct ---
                $sizeStmt = $this->db->prepare("
                    INSERT INTO product_sizes (product_id, name, stock, price, image) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                // --- FIX: Use $imagePath, but if it's null, use the old default image ---
                $finalDefaultImage = $imagePath ?? $oldDefaultImage ?? '/Assets/Products/placeholder.png';
                $sizeStmt->execute([
                    $id,
                    'Default',
                    (int)$data['stock_no_sizes'],
                    (float)$data['price'],
                    $finalDefaultImage // <-- Use main image
                ]);
            }

            // --- 5. LOG ACTIVITY ---
            $newProductData = $this->findProductById($id);
            $this->logEntityChange(
                $actorId,
                'update',
                'product',
                $id,
                $oldProduct,
                $newProductData
            );
            // --- END LOG ---

            // 6. Commit
            $this->db->commit();

        } catch (\Exception $e) {
            $this->db->rollBack();
            error_log("Product update error: " . $e->getMessage());
            // You would ideally redirect with an error message here
        }
        // --- END REFACTOR ---

        return $response->withHeader('Location', $routeParser->urlFor('app.products.index'))->withStatus(302);
    }
    
    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        
        // --- 1. GET ACTOR ---
        $user = $request->getAttribute('user');
        $actorId = $user ? (int)$user['id'] : null;

        // --- 2. GET "BEFORE" STATE ---
        $productToDelete = $this->findProductById($id);

        if ($productToDelete) {
            // 3. Delete from database
            // ON DELETE CASCADE will handle product_sizes
            $stmt = $this->db->prepare("DELETE FROM products WHERE id = ?");
            $stmt->execute([$id]);

            // --- 4. LOG ACTIVITY ---
            $this->logEntityChange(
                $actorId,
                'delete',
                'product',
                $id,
                $productToDelete,
                null
            );
            // --- END LOG ---

            // 5. Delete main image file from filesystem
            if (!empty($productToDelete['image'])) {
                $imagePath = $this->uploadDir . basename($productToDelete['image']);
                if (file_exists($imagePath) && !str_contains($imagePath, 'placeholder.png')) {
                    unlink($imagePath);
                }
            }

            // 6. Delete size image files
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