<?php
namespace SweetDelights\Mayie\Controllers\Admin;

use Slim\Routing\RouteContext;
use Slim\Views\Twig;
use Psr\Http\Message\ServerRequestInterface as Request;

class BaseAdminController
{
    // Note: Removed the __construct() and logging methods as requested.

    protected function viewFromRequest(Request $request): Twig
    {
        return Twig::fromRequest($request);
    }

    /**
     * Helper to build breadcrumbs dynamically.
     */
    protected function breadcrumbs(Request $request, array $trail): array
    {
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();

        // Add the base "Dashboard" link
        $breadcrumbs = [
            ['name' => 'Dashboard', 'url' => $routeParser->urlFor('app.dashboard')]
        ];

        foreach ($trail as $crumb) {
            if (isset($crumb['url']) && $crumb['url'] && !str_starts_with($crumb['url'], '/')) {
                $crumb['url'] = $routeParser->urlFor($crumb['url']);
            }
            $breadcrumbs[] = $crumb;
        }

        return $breadcrumbs;
    }

    // --- REUSABLE DATA PERSISTENCE ---

    /**
     * Saves an indexed array (like users, products) to a file.
     */
    protected function saveData(string $filePath, array $data)
    {
        $indexedData = array_values($data);
        // --- FIX WAS HERE ---
        $phpCode = '<?php' . PHP_EOL . 'return ' . var_export($indexedData, true) . ';';
        // --- END FIX ---
        file_put_contents($filePath, $phpCode, LOCK_EX);
    }

    /**
     * Saves an associative array (like config) to a file.
     */
    protected function saveConfigData(string $filePath, array $data)
    {
        // Do NOT use array_values().
        $phpCode = '<?php' . PHP_EOL . 'return ' . var_export($data, true) . ';';
        file_put_contents($filePath, $phpCode, LOCK_EX);
    }

    // --- (SKU Logic functions are unchanged) ---

    protected function _createSlugAbbreviation(string $slug): string
    {
        if (empty($slug)) {
            return 'uncat';
        }

        if (strpos($slug, '-') !== false) {
            $parts = explode('-', $slug);
            $abbr = '';
            foreach ($parts as $part) {
                if (!empty($part)) {
                    $abbr .= $part[0]; 
                }
            }
            return empty($abbr) ? 'uncat' : $abbr;
        } else {
            return substr($slug, 0, 3);
        }
    }

    protected function _createNameAbbreviation(string $name): string
    {
        if (empty($name)) {
            return 'PROD';
        }
        
        $cleanName = preg_replace('/[^a-zA-Z\s]/', '', $name);
        
        if (strpos(trim($cleanName), ' ') !== false) {
            $parts = explode(' ', $cleanName);
            $abbr = '';
            foreach ($parts as $part) {
                if (!empty($part)) {
                    $abbr .= $part[0];
                }
            }
            return empty($abbr) ? 'PROD' : strtoupper($abbr);
        } else {
            return strtoupper(substr($cleanName, 0, 3));
        }
    }

    protected function generateSku(string $name, string $id, int $categoryId, array $allCategories): string
    {
        $category = null;
        $parentCategory = null;
        $categoryId = (int)$categoryId;

        if ($categoryId === 0) {
             $categoryAbbr = 'uncat';
             $parentAbbr = 'uncat';
        } else {
            foreach ($allCategories as $cat) {
                if ($cat['id'] === $categoryId) {
                    $category = $cat;
                    break;
                }
            }

            if ($category && $category['parent_id'] !== null) {
                foreach ($allCategories as $cat) {
                    if ($cat['id'] === $category['parent_id']) {
                        $parentCategory = $cat;
                        break;
                    }
                }
            }

            $categoryAbbr = 'uncat';
            $parentAbbr = 'uncat';

            if ($category) {
                $categoryAbbr = $this->_createSlugAbbreviation($category['slug']);
                if ($parentCategory) {
                    $parentAbbr = $this->_createSlugAbbreviation($parentCategory['slug']);
                } else {
                    $parentAbbr = $categoryAbbr;
                }
            }
        }
        
        $productCode = $this->_createNameAbbreviation($name);
        $sku = sprintf('%s-%s-%s-%s', $parentAbbr, $categoryAbbr, $productCode, $id);
        $sku = preg_replace('/[^a-zA-Z0-9]+/', '-', $sku);
        return strtolower(trim($sku, '-'));
    }
}