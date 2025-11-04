<?php
namespace SweetDelights\Mayie\Controllers\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Slim\Routing\RouteContext;
use Slim\Psr7\UploadedFile;

// INHERIT from BaseAdminController
class ProductAdminController extends BaseAdminController
{
    // private $productsPath; <-- Removed
    // private $categoriesPath; <-- Removed
    private $uploadDir; // Physical server path
    private $publicUploadPath; // Public-facing URL path

    public function __construct()
    {
        // Call parent to set up $productsPath, $categoriesPath, etc.
        parent::__construct(); 
        
        // Define paths specific to this controller
        $this->uploadDir = __DIR__ . '/../../../public/Assets/Products/';
        $this->publicUploadPath = '/Assets/Products/';
    }

    // --- ✅ START: DATA HELPERS ---

    // private function getProducts(): array <-- Removed, now in parent
    
    // private function getCategories(): array <-- Removed, now in parent

    // private function saveProducts(array $products) <-- Removed, now in parent

    private function findProduct(string $id): ?array
    {
        foreach ($this->getProducts() as $product) { // <-- Uses inherited method
            if ($product['id'] === $id) {
                return $product;
            }
        }
        return null;
    }

    // --- ✅ END: DATA HELPERS ---

    // ---  NEW: Recursive Helper for Indented Category List ---
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


    // ---  ROBUST File Upload Helpers (Specific to Product Controller) ---
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
    
    // --- Data Processing Helper ---
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
                    
                    // --- START: IMAGE LOGIC ---
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
                    // --- END: IMAGE LOGIC ---

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
        $data['stock'] = $totalStock;
        
        return $data;
    }

    // --- CRUD Methods ---

    public function index(Request $request, Response $response): Response
    {
        $view = $this->viewFromRequest($request);
        
        $params = $request->getQueryParams();
        $searchTerm = $params['search'] ?? null;
        $categoryFilter = $params['category'] ?? null;

        $products = $this->getProducts(); // <-- Uses inherited method
        $categories = $this->getCategories(); // <-- Uses inherited method
        
        if ($searchTerm) {
            $products = array_filter($products, function($product) use ($searchTerm) {
                // Search by name or SKU
                return stripos($product['name'], $searchTerm) !== false || (isset($product['sku']) && stripos($product['sku'], $searchTerm) !== false);
            });
        }
        
        if ($categoryFilter && !empty($categoryFilter)) {
            $products = array_filter($products, function($product) use ($categoryFilter) {
                return $product['category_id'] == $categoryFilter;
            });
        }
        
        $categoryMap = [];
        foreach ($categories as $cat) {
            $categoryMap[$cat['id']] = $cat['name'];
        }

        foreach ($products as &$product) {
            $product['category_name'] = $categoryMap[$product['category_id']] ?? 'N/A';
        }
        unset($product);
        
        $indentedCategories = $this->getIndentedCategories($categories);

        $breadcrumbs = $this->breadcrumbs($request, [
            ['name' => 'Products', 'url' => null]
        ]);

        return $view->render($response, 'Admin/products.twig', [
            'title' => 'Manage Products',
            'products' => $products,
            'all_categories' => $indentedCategories,
            'search_term' => $searchTerm,
            'category_filter' => $categoryFilter,
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
        
        $products = $this->getProducts(); // <-- Uses inherited method
        
        $sizeImageFiles = $files['sizes_image'] ?? [];
        $data = $this->processProductData($data, $sizeImageFiles); 

        $imagePath = null;
        try {
            $imageFile = $files['image'] ?? null;
            $imagePath = $this->handleUpload($imageFile);
        } catch (\Exception $e) {
            error_log("File upload error: " . $e->getMessage());
        }

        $newId = 1;
        if (count($products) > 0) {
            $ids = array_map('intval', array_column($products, 'id'));
            $newId = max($ids) + 1;
        }
        $newIdStr = (string)$newId;

        // --- ✅ GENERATE SKU (Uses inherited method) ---
        $allCategories = $this->getCategories(); // <-- Uses inherited method
        $sku = $this->generateSku($data['name'], $newIdStr, (int)($data['category_id'] ?? 0), $allCategories);

        $newProduct = [
            'id' => $newIdStr,
            'sku' => $sku, 
            'name' => $data['name'],
            'price' => (float)$data['price'],
            'image' => $imagePath ?? '/Assets/Products/placeholder.png',
            'stock' => $data['stock'],
            'category_id' => (int)($data['category_id'] ?? 0),
            'sizes' => $data['sizes'],
            'description' => $data['description'] ?? '',
        ];

        $products[] = $newProduct;
        $this->saveProducts($products); // <-- Uses inherited method

        return $response->withHeader('Location', $routeParser->urlFor('app.products.index'))->withStatus(302);
    }


    public function edit(Request $request, Response $response, array $args): Response
    {
        $view = $this->viewFromRequest($request);
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        $id = $args['id'];
        $product = $this->findProduct($id);

        if (!$product) {
            return $response->withHeader('Location', $routeParser->urlFor('app.products.index'))->withStatus(302);
        }
        
        if (is_string($product['sizes'])) {
             $product['sizes'] = json_decode($product['sizes'], true) ?? [];
        }

        $rawCategories = $this->getCategories(); // <-- Uses inherited method
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
        $id = $args['id'];
        $data = $request->getParsedBody();
        $files = $request->getUploadedFiles();
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();

        $products = $this->getProducts(); // <-- Uses inherited method
        $productIndex = null;
        $oldProduct = null;

        foreach ($products as $index => $p) {
            if ($p['id'] === $id) {
                $productIndex = $index;
                $oldProduct = $p;
                break;
            }
        }

        if (is_null($productIndex)) {
            return $response->withHeader('Location', $routeParser->urlFor('app.products.index'))->withStatus(302);
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

        // --- ✅ RE-GENERATE SKU (Uses inherited method) ---
        $allCategories = $this->getCategories(); // <-- Uses inherited method
        $newCategoryId = (int)($data['category_id'] ?? $oldProduct['category_id'] ?? 0);
        $newName = $data['name'] ?? $oldProduct['name'];
        $sku = $this->generateSku($newName, $id, $newCategoryId, $allCategories);

        $updatedProduct = [
            'id' => $id,
            'sku' => $sku, 
            'name' => $data['name'] ?? $oldProduct['name'],
            'price' => (float)$data['price'],
            'image' => $imagePath,
            'stock' => $data['stock'],
            'category_id' => $newCategoryId,
            'sizes' => $data['sizes'],
            'description' => $data['description'] ?? $oldProduct['description'] ?? '',
        ];

        $products[$productIndex] = $updatedProduct;
        $this->saveProducts($products); // <-- Uses inherited method

        return $response->withHeader('Location', $routeParser->urlFor('app.products.index'))->withStatus(302);
    }
    
    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $products = $this->getProducts(); // <-- Uses inherited method
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();

        $productToDelete = $this->findProduct($id);

        if ($productToDelete) {
            $products = array_filter($products, fn($p) => $p['id'] !== $id);
            $this->saveProducts($products); // <-- Uses inherited method
        }

        return $response->withHeader('Location', $routeParser->urlFor('app.products.index'))->withStatus(302);
    }
}