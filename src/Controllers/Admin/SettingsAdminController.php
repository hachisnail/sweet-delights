<?php
// FILE: src/Controllers/Admin/SettingsAdminController.php

namespace SweetDelights\Mayie\Controllers\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteContext;
// use \PDO; // Not strictly needed as $this->db is already a PDO object

class SettingsAdminController extends BaseAdminController
{
    // private $configPath; <-- Removed, this is handled by parent

    public function __construct()
    {
        // Call parent to set up $this->db
        parent::__construct(); 
    }

    // private function getConfig(): array <-- Removed, this is inherited

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
            'config' => $this->getConfig(), // <-- Uses inherited DB method
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
        
        // --- 1. GET ACTOR ---
        $user = $request->getAttribute('user');
        $actorId = $user ? (int)$user['id'] : null;

        // --- 2. GET "BEFORE" STATE ---
        $settingsBefore = $this->getConfig();

        $newConfig = [
            // Cast to float to ensure they are stored as numbers, not strings
            'tax_rate' => (float)($data['tax_rate'] ?? 0.12),
            'shipping_fee' => (float)($data['shipping_fee'] ?? 50.00),
        ];

        // --- REFACTORED: Use database query ---
        try {
            // This query will INSERT a new setting if it doesn't exist,
            // or UPDATE it if it does.
            $stmt = $this->db->prepare("
                INSERT INTO settings (setting_key, setting_value) 
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ");

            // Loop through the config data and execute the query for each key
            foreach ($newConfig as $key => $value) {
                $stmt->execute([$key, $value]);
            }

            // --- 3. GET "AFTER" STATE ---
            $settingsAfter = $this->getConfig();

            // --- 4. LOG THE CHANGE ---
            $this->logEntityChange(
                $actorId,
                'update',       // actionType
                'settings',     // entityType
                null,           // entityId (no single ID)
                $settingsBefore, // before
                $settingsAfter   // after
            );
            // --- END LOG ---

        } catch (\Exception $e) {
            // Handle or log the error
            // error_log("Settings update error: " . $e->getMessage());
            // For now, just redirect back with an error
            $url = $routeParser->urlFor('app.settings') . '?success=false';
            return $response->withHeader('Location', $url)->withStatus(302);
        }
        // --- END REFACTOR ---

        $url = $routeParser->urlFor('app.settings') . '?success=true';
        return $response->withHeader('Location', $url)->withStatus(302);
    }
}