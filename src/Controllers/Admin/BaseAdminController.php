<?php
namespace SweetDelights\Mayie\Controllers\Admin;

use Slim\Routing\RouteContext;
use Slim\Views\Twig;
use Psr\Http\Message\ServerRequestInterface as Request;

class BaseAdminController
{
    protected function viewFromRequest(Request $request): Twig
    {
        return Twig::fromRequest($request);
    }

    /**
     * Helper to build breadcrumbs dynamically.
     * * Example:
     * $this->breadcrumbs($request, [
     * ['name' => 'Categories', 'url' => 'app.categories.index'],
     * ['name' => 'Edit Category', 'url' => null]
     * ]);
     */
    protected function breadcrumbs(Request $request, array $trail): array
    {
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();

        $breadcrumbs = [ ];

        foreach ($trail as $crumb) {
            if (isset($crumb['url']) && $crumb['url'] && !str_starts_with($crumb['url'], '/')) {
                $crumb['url'] = $routeParser->urlFor($crumb['url']);
            }
            $breadcrumbs[] = $crumb;
        }

        return $breadcrumbs;
    }
}
