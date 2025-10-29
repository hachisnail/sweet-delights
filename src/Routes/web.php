<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy; // <-- ADD THIS

// (Import all your controllers)
use SweetDelights\Mayie\Controllers\HomeController;
use SweetDelights\Mayie\Controllers\AboutController;
use SweetDelights\Mayie\Controllers\ProductsController;
use SweetDelights\Mayie\Controllers\DashboardController;
use SweetDelights\Mayie\Controllers\SystemController; 
use SweetDelights\Mayie\Controllers\UserController;
use SweetDelights\Mayie\Controllers\AccountController;
use SweetDelights\Mayie\Controllers\UnderConstructionController;

use SweetDelights\Mayie\Controllers\Api\AuthController; 
use SweetDelights\Mayie\Controllers\Api\CartController;
use SweetDelights\Mayie\Controllers\Api\FavouritesController;
use SweetDelights\Mayie\Middleware\ApiAuthMiddleware;


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


    // Protected by our new JSON-returning middleware
    $app->group('/api', function (RouteCollectorProxy $group) {
        
        $group->post('/cart/sync', [CartController::class, 'sync']);
        $group->post('/favourites/sync', [FavouritesController::class, 'sync']);

    })->add(new ApiAuthMiddleware()); // <-- Use the new middleware!


    // --- Account Routes ---
    // (Unchanged)
    $app->group('/account', function ($group) {
        $group->get('/settings', [AccountController::class, 'showSettings']);
        $group->get('/orders', [AccountController::class, 'showOrders']);
        $group->post('/settings/update', [AccountController::class, 'updateProfile']);
        $group->post('/settings/password', [AccountController::class, 'updatePassword']);

    })->add(new RoleAuthMiddleware(['customer', 'admin', 'superadmin']));


    // --- Admin Routes ---
    // (Unchanged)
    $app->group('/app', function ($group) {
        $group->get('', [DashboardController::class, 'dashboard']);
        $group->get('/', [DashboardController::class, 'dashboard']);
        $group->get('/dashboard', [DashboardController::class, 'dashboard']);
        $group->get('/users', [UserController::class, 'index']);
        $group->get('/products', [UnderConstructionController::class, 'show']);
        $group->get('/orders', [UnderConstructionController::class, 'show']);
    })->add(new RoleAuthMiddleware(['admin', 'superadmin']));


    // --- Super Admin Routes ---
    // (Unchanged)
    $app->group('/system', function ($group) {
       $group->get('/logs', [SystemController::class, 'viewLogs']);
       $group->get('/users', [UnderConstructionController::class, 'show']);
    })->add(new RoleAuthMiddleware(['superadmin']));

};