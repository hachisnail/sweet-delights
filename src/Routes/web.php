<?php

use Slim\App;
// (Import all your controllers)
use SweetDelights\Mayie\Controllers\HomeController;
use SweetDelights\Mayie\Controllers\AboutController;
use SweetDelights\Mayie\Controllers\ProductsController;
use SweetDelights\Mayie\Controllers\DashboardController;
use SweetDelights\Mayie\Controllers\AuthController; 
use SweetDelights\Mayie\Controllers\SystemController; 
use SweetDelights\Mayie\Controllers\UserController;
use SweetDelights\Mayie\Controllers\AccountController;
use SweetDelights\Mayie\Controllers\UnderConstructionController;

// --- IMPORT YOUR UNIFIED MIDDLEWARE ---
use SweetDelights\Mayie\Middleware\RoleAuthMiddleware;

return function (App $app) {

    // (Public and Auth routes are unchanged)
    $app->get('/', [HomeController::class, 'index']);
    $app->get('/about', [AboutController::class, 'index']);
    $app->get('/products', [ProductsController::class, 'index']);
    $app->get('/products/{id}', [ProductsController::class, 'show']);
    $app->get('/login', [AuthController::class, 'showLogin']);
    $app->post('/login', [AuthController::class, 'login']);
    $app->get('/logout', [AuthController::class, 'logout']);

    // --- Account Routes ---
    // Protected by RoleAuthMiddleware, allowing *all* logged-in roles
    $app->group('/account', function ($group) {
        // GET routes to show pages
        $group->get('/settings', [AccountController::class, 'showSettings']);
        $group->get('/orders', [AccountController::class, 'showOrders']);
        
        // POST routes to handle form submissions
        $group->post('/settings/update', [AccountController::class, 'updateProfile']);
        $group->post('/settings/password', [AccountController::class, 'updatePassword']);

    })->add(new RoleAuthMiddleware(['customer', 'admin', 'superadmin']));


    // --- Admin Routes ---
    $app->group('/app', function ($group) {
        $group->get('', [DashboardController::class, 'dashboard']);
        $group->get('/', [DashboardController::class, 'dashboard']);
        $group->get('/dashboard', [DashboardController::class, 'dashboard']);
        $group->get('/users', [UserController::class, 'index']);
        $group->get('/products', [UnderConstructionController::class, 'show']);
        $group->get('/orders', [UnderConstructionController::class, 'show']);
    })->add(new RoleAuthMiddleware(['admin', 'superadmin']));


    // --- Super Admin Routes ---
    $app->group('/system', function ($group) {
       $group->get('/logs', [SystemController::class, 'viewLogs']);
       $group->get('/users', [UnderConstructionController::class, 'show']);
    })->add(new RoleAuthMiddleware(['superadmin']));

};

