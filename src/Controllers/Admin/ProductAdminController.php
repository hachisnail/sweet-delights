<?php
namespace SweetDelights\Mayie\Controllers\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Slim\Routing\RouteContext;
use Slim\Psr7\UploadedFile;

//  INHERIT from BaseAdminController
class ProductAdminController extends BaseAdminController
{
    private $productsPath;
    private $categoriesPath;
    private $uploadDir; // Physical server path
    private $publicUploadPath; // Public-facing URL path

    public function __construct()
    {
        $this->productsPath = __DIR__ . '/../../data/products.php';
        $this->categoriesPath = __DIR__ . '/../../data/categories.php';
        $this->uploadDir = __DIR__ . '/../../../public/Assets/Products/';
        $this->publicUploadPath = '/Assets/Products/';
    }

    private function getProducts(): array
    {
        if (!file_exists($this->productsPath)) return [];
        return require $this->productsPath;
    }

    private function getCategories(): array
    {
        if (!file_exists($this->categoriesPath)) return [];
        return require $this->categoriesPath;
    }

    private function saveProducts(array $products)
    {
        $indexedProducts = array_values($products);
        $phpCode = '<?php' . PHP_EOL . 'return ' . var_export($indexedProducts, true) . ';';
        file_put_contents($this->productsPath, $phpCode);
    }

    private function findProduct(string $id): ?array
    {
        foreach ($this->getProducts() as $product) {
            if ($product['id'] === $id) {
                return $product;
            }
        }
        return null;
    }

    // ---  NEW: Recursive Helper for Indented Category List ---
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


    // ---  ROBUST File Upload Helpers ---
    /**
     * @throws \Exception
     */
    private function handleUpload(UploadedFile $uploadedFile): ?string
    {
        if ($uploadedFile->getError() !== UPLOAD_ERR_OK) {
            switch ($uploadedFile->getError()) {
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    throw new \Exception('File is too large.');
                case UPLOAD_ERR_NO_FILE:
                    throw new \Exception('No file was uploaded.');
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
    
    // ---  UPDATED Data Processing Helper ---
    private function processProductData(array $data): array
    {
        $sizes = [];
        $totalStock = 0;
        $prices = []; 

        if (isset($data['sizes_name']) && is_array($data['sizes_name'])) {
            foreach ($data['sizes_name'] as $index => $name) {
                $stock = $data['sizes_stock'][$index] ?? 0;
                $price = $data['sizes_price'][$index] ?? 0;
                
                if (!empty($name)) {
                    $sizeData = [
                        'name' => $name, 
                        'stock' => (int)$stock,
                        'price' => (float)$price
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

    /**
     * GET /app/products
     */
    public function index(Request $request, Response $response): Response
    {
        $view = $this->viewFromRequest($request);
        
        $params = $request->getQueryParams();
        $searchTerm = $params['search'] ?? null;
        $categoryFilter = $params['category'] ?? null;

        $products = $this->getProducts();
        $categories = $this->getCategories();
        
        if ($searchTerm) {
            $products = array_filter($products, function($product) use ($searchTerm) {
                return stripos($product['name'], $searchTerm) !== false;
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

    /**
     * GET /app/products/new
     */
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

    /**
     * POST /app/products
     */
    public function store(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $files = $request->getUploadedFiles();
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        
        $products = $this->getProducts();
        
        $data = $this->processProductData($data); 

        $imagePath = null;
        try {
            if (isset($files['image']) && $files['image']->getError() === UPLOAD_ERR_OK) {
                $imagePath = $this->handleUpload($files['image']);
            }
        } catch (\Exception $e) {
            error_log("File upload error: " . $e->getMessage());
        }

        $newId = 1;
        if (count($products) > 0) {
            $ids = array_map('intval', array_column($products, 'id'));
            $newId = max($ids) + 1;
        }

        $newProduct = [
            'id' => (string)$newId,
            'name' => $data['name'],
            'price' => (float)$data['price'],
            'image' => $imagePath ?? '/Assets/Products/placeholder.png',
            'stock' => $data['stock'],
            'category_id' => (int)$data['category_id'],
            'sizes' => $data['sizes'],
            'description' => $data['description'] ?? '',
        ];

        $products[] = $newProduct;
        $this->saveProducts($products);

        return $response->withHeader('Location', $routeParser->urlFor('app.products.index'))->withStatus(302);
    }


    /**
     * GET /app/products/{id}/edit
     */
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

    /**
     * POST /app/products/{id}
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $data = $request->getParsedBody();
        $files = $request->getUploadedFiles();
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();

        $products = $this->getProducts();
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

        $data = $this->processProductData($data);
        
        $imagePath = $oldProduct['image']; 
        try {
            if (isset($files['image']) && $files['image']->getError() === UPLOAD_ERR_OK) {
                $imagePath = $this->handleUpload($files['image']);
            }
        } catch (\Exception $e) {
            error_log("File upload error: " . $e->getMessage());
            $imagePath = $oldProduct['image'];
        }

        $updatedProduct = [
            'id' => $id,
            'name' => $data['name'] ?? $oldProduct['name'],
            'price' => (float)$data['price'],
            'image' => $imagePath,
            'stock' => $data['stock'],
            'category_id' => (int)($data['category_id'] ?? $oldProduct['category_id']),
            'sizes' => $data['sizes'],
            'description' => $data['description'] ?? $oldProduct['description'] ?? '',
        ];

        $products[$productIndex] = $updatedProduct;
        $this->saveProducts($products);

        return $response->withHeader('Location', $routeParser->urlFor('app.products.index'))->withStatus(302);
    }
    
    /**
     * POST /app/products/{id}/delete
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $products = $this->getProducts();
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();

        $productToDelete = $this->findProduct($id);

        if ($productToDelete) {
            $products = array_filter($products, fn($p) => $p['id'] !== $id);
            $this->saveProducts($products);
        }

        return $response->withHeader('Location', $routeParser->urlFor('app.products.index'))->withStatus(302);
    }
}

