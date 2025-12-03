<?php

namespace SweetDelights\Mayie\Controllers\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteContext;

class SettingsAdminController extends BaseAdminController
{

    public function __construct()
    {
        parent::__construct(); 
    }


    /**
     * Show the settings form.
     * (This method was already correct as it uses the inherited getConfig())
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
     * Update the settings in the database.
     */
    public function update(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        
        $user = $request->getAttribute('user');
        $actorId = $user ? (int)$user['id'] : null;

        $settingsBefore = $this->getConfig();

        $newConfig = [
            'tax_rate' => (float)($data['tax_rate'] ?? 0.12),
            'shipping_fee' => (float)($data['shipping_fee'] ?? 50.00),
        ];

        try {
            $stmt = $this->db->prepare("
                INSERT INTO settings (setting_key, setting_value) 
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ");

            foreach ($newConfig as $key => $value) {
                $stmt->execute([$key, $value]);
            }

            $settingsAfter = $this->getConfig();

            $this->logEntityChange(
                $actorId,
                'update',       
                'settings',    
                null,         
                $settingsBefore, 
                $settingsAfter   
            );

        } catch (\Exception $e) {
            $url = $routeParser->urlFor('app.settings') . '?success=false';
            return $response->withHeader('Location', $url)->withStatus(302);
        }

        $url = $routeParser->urlFor('app.settings') . '?success=true';
        return $response->withHeader('Location', $url)->withStatus(302);
    }
}