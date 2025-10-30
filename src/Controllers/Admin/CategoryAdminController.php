<?php
namespace SweetDelights\Mayie\Controllers\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Slim\Routing\RouteContext;

class CategoryAdminController extends BaseAdminController
{
    private $dataPath;

    public function __construct()
    {
        $this->dataPath = __DIR__ . '/../../data/categories.php'; 
    }

    // --- Data Helper Functions (No changes) ---
    private function getCategories(): array
    {
        if (!file_exists($this->dataPath)) {
            return [];
        }
        return require $this->dataPath;
    }

    private function saveCategories(array $categories)
    {
        $indexedCategories = array_values($categories);
        $phpCode = '<?php' . PHP_EOL . 'return ' . var_export($indexedCategories, true) . ';';
        file_put_contents($this->dataPath, $phpCode);
    }

    private function findCategory(int $id): ?array
    {
        foreach ($this->getCategories() as $category) {
            if ($category['id'] === $id) {
                return $category;
            }
        }
        return null;
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

    // --- CRUD Methods ---

    /**
     * GET /app/categories
     */
    public function index(Request $request, Response $response): Response
    {
        $view = $this->viewFromRequest($request);
        $categories = $this->getCategories();
        $nested = $this->buildTree($categories);

        return $view->render($response, 'Admin/categories.twig', [
            'title' => 'Manage Categories',
            'categories' => $nested,
            'all_categories' => $categories,
            'breadcrumbs' => $this->breadcrumbs($request, [
                ['name' => 'Categories', 'url' => null],
            ]),
            'active_page' => 'categories',
            'app_url' => $_ENV['APP_URL'] ?? '', //  Ensure app_url is passed
        ]);
    }

    /**
     * GET /app/categories/new
     */
    public function create(Request $request, Response $response): Response
    {
        $view = $this->viewFromRequest($request);
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        
        //  --- FIXED: ADDED PARTIAL CHECK ---
        $params = $request->getQueryParams();
        $isPartial = isset($params['partial']) && $params['partial'] == 'true';
        $template = $isPartial ? 'Admin/category-form-partial.twig' : 'Admin/category-form.twig';

        $breadcrumbs = $this->breadcrumbs($request, [
            ['name' => 'Categories', 'url' => 'app.categories.index'],
            ['name' => 'Add New', 'url' => null],
        ]);

        return $view->render($response, $template, [ //  Use dynamic template
            'title' => 'Add Category',
            'form_action' => $routeParser->urlFor('app.categories.store'),
            'all_categories' => $this->getCategories(),
            'breadcrumbs' => $breadcrumbs,
            'active_page' => 'categories',
            'app_url' => $_ENV['APP_URL'] ?? '', //  Ensure app_url is passed
        ]);
    }

    /**
     * POST /app/categories
     */
    public function store(Request $request, Response $response): Response
    {
        // (No changes here)
        $data = $request->getParsedBody();
        $categories = $this->getCategories();

        $newId = 1;
        if (count($categories) > 0) {
            $newId = max(array_column($categories, 'id')) + 1;
        }

        $slug = str_replace(' ', '-', strtolower(trim(preg_replace('/[^A-Za-z0-9 ]/', '', $data['name']))));

        $categories[] = [
            'id' => $newId,
            'parent_id' => $data['parent_id'] ? (int)$data['parent_id'] : null,
            'name' => $data['name'],
            'slug' => $slug,
        ];

        $this->saveCategories($categories);

        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        return $response->withHeader('Location', $routeParser->urlFor('app.categories.index'))->withStatus(302);
    }

    /**
     * GET /app/categories/{id}/edit
     */
    public function edit(Request $request, Response $response, array $args): Response
    {
        $view = $this->viewFromRequest($request); //  Use helper
        $routeParser = RouteContext::fromRequest($request)->getRouteParser(); 
        $params = $request->getQueryParams();
        $id = (int)$args['id'];
        $category = $this->findCategory($id);

        if (!$category) {
            return $response->withHeader('Location', $routeParser->urlFor('app.categories.index'))->withStatus(302);
        }
        
        //  --- CHECK FOR PARTIAL (Already correct) ---
        $isPartial = isset($params['partial']) && $params['partial'] == 'true';
        $template = $isPartial ? 'Admin/category-form-partial.twig' : 'Admin/category-form.twig';

        $breadcrumbs = $this->breadcrumbs($request, [
            ['name' => 'Categories', 'url' => 'app.categories.index'],
            ['name' => $category['name'] ?? 'Edit', 'url' => null],
        ]);

        return $view->render($response, $template, [
            'title' => 'Edit Category',
            'category' => $category,
            'all_categories' => $this->getCategories(),
            'form_action' => $routeParser->urlFor('app.categories.update', ['id' => $id]),
            'app_url' => $_ENV['APP_URL'] ?? '',
            'breadcrumbs' => $breadcrumbs, //  Pass breadcrumbs
            'active_page' => 'categories', //  Pass active_page
        ]);
    }

    /**
     * POST /app/categories/{id}
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        // (No changes here)
        $id = (int)$args['id'];
        $data = $request->getParsedBody();
        $categories = $this->getCategories();
        
        $slug = str_replace(' ', '-', strtolower(trim(preg_replace('/[^A-Za-z0-9 ]/', '', $data['name']))));

        foreach ($categories as $index => &$category) {
            if ($category['id'] === $id) {
                $category['name'] = $data['name'];
                $category['parent_id'] = $data['parent_id'] ? (int)$data['parent_id'] : null;
                $category['slug'] = $slug;
                break;
            }
        }
        unset($category); 

        $this->saveCategories($categories);
        
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        return $response->withHeader('Location', $routeParser->urlFor('app.categories.index'))->withStatus(302);
    }

    /**
     * POST /app/categories/{id}/delete
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        // (No changes here)
        $id = (int)$args['id'];
        $categories = $this->getCategories();

        $newCategories = array_filter($categories, fn($cat) => $cat['id'] !== $id);
        
        foreach ($newCategories as &$cat) {
            if ($cat['parent_id'] === $id) {
                $cat['parent_id'] = null; 
            }
        }
        unset($cat);

        $this->saveCategories($newCategories);
        
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        return $response->withHeader('Location', $routeParser->urlFor('app.categories.index'))->withStatus(302);
    }
}

