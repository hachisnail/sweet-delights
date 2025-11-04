<?php
// FILE: src/Controllers/Admin/SettingsAdminController.php

namespace SweetDelights\Mayie\Controllers\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteContext;

class SettingsAdminController extends BaseAdminController
{
    private $configPath;

    public function __construct()
    {
        $this->configPath = __DIR__ . '/../../Data/config.php';
    }

    private function getConfig(): array
    {
        return file_exists($this->configPath) ? require $this->configPath : [];
    }

    /**
     * Show the settings form.
     */
    public function show(Request $request, Response $response): Response
    {
        $view = $this->viewFromRequest($request);
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        $params = $request->getQueryParams();

        return $view->render($response, 'Admin/settings.twig', [
            'title' => 'Site Settings',
            'config' => $this->getConfig(),
            'form_action' => $routeParser->urlFor('app.settings.update'),
            'breadcrumbs' => $this->breadcrumbs($request, [
                ['name' => 'Settings', 'url' => null]
            ]),
            'active_page' => 'settings',
            'app_url' => $_ENV['APP_URL'] ?? '',
            'success' => $params['success'] ?? null
        ]);
    }

    /**
     * Update the settings file.
     */
    public function update(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        
        $newConfig = [
            // Cast to float to ensure they are stored as numbers, not strings
            'tax_rate' => (float)($data['tax_rate'] ?? 0.12),
            'shipping_fee' => (float)($data['shipping_fee'] ?? 50.00),
        ];

        // Use the NEW config-safe save method
        $this->saveConfigData($this->configPath, $newConfig); 

        $url = $routeParser->urlFor('app.settings') . '?success=true';
        return $response->withHeader('Location', $url)->withStatus(302);
    }
}