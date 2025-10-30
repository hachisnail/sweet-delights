<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy; // <-- Ensures correct type hinting

// (Import all your controllers)
use SweetDelights\Mayie\Controllers\HomeController;
use SweetDelights\Mayie\Controllers\AboutController;
use SweetDelights\Mayie\Controllers\ProductsController;


use SweetDelights\Mayie\Controllers\AccountController;
use SweetDelights\Mayie\Controllers\UnderConstructionController;

use SweetDelights\Mayie\Controllers\Api\AuthController; 
use SweetDelights\Mayie\Controllers\Api\CartController;
use SweetDelights\Mayie\Controllers\Api\FavouritesController;
use SweetDelights\Mayie\Middleware\ApiAuthMiddleware;

use SweetDelights\Mayie\Controllers\Admin\DashboardAdminController;
use SweetDelights\Mayie\Controllers\Admin\SystemAdminController; 
use SweetDelights\Mayie\Controllers\Admin\UserAdminController;

use SweetDelights\Mayie\Controllers\Admin\CategoryAdminController; 
use SweetDelights\Mayie\Controllers\Admin\ProductAdminController; 

use SweetDelights\Mayie\Middleware\RoleAuthMiddleware;

return function (App $app) {

    // (Public and Auth routes are unchanged)
    $app->get('/', [HomeController::class, 'index']);
    $app->get('/about', [AboutController::class, 'index']);
    $app->get('/products', [ProductsController::class, 'index']);
    $app->get('/products/{id:[0-9]+}', [ProductsController::class, 'show']); // Allow numeric IDs
    $app->get('/login', [AuthController::class, 'showLogin']);
    $app->post('/login', [AuthController::class, 'login']);
    $app->get('/logout', [AuthController::class, 'logout']);


    // (API routes are unchanged)
    $app->group('/api', function (RouteCollectorProxy $group) {
        $group->post('/cart/sync', [CartController::class, 'sync']);
        $group->post('/favourites/sync', [FavouritesController::class, 'sync']);
    })->add(new ApiAuthMiddleware());


    // (Account routes are unchanged)
    $app->group('/account', function (RouteCollectorProxy $group) {
        $group->get('/settings', [AccountController::class, 'showSettings']);
        $group->get('/orders', [AccountController::class, 'showOrders']);
        $group->post('/settings/update', [AccountController::class, 'updateProfile']);
        $group->post('/settings/password', [AccountController::class, 'updatePassword']);
    })->add(new RoleAuthMiddleware(['customer', 'admin', 'superadmin']));


    // --- Admin Routes ---
    $app->group('/app', function (RouteCollectorProxy $group) {
        
        // Dashboard (Already correct)
        $group->get('', [DashboardAdminController::class, 'dashboard'])->setName('app.dashboard');
        $group->get('/', [DashboardAdminController::class, 'dashboard'])->setName('app.dashboard.slash');
        $group->get('/dashboard', [DashboardAdminController::class, 'dashboard'])->setName('app.dashboard.main');
        
        // Users
        $group->get('/users', [UserAdminController::class, 'index'])->setName('app.users');
        
        // Placeholder
        $group->get('/orders', [UnderConstructionController::class, 'show'])->setName('app.orders');

        // ---  CATEGORY CRUD ROUTES (CHANGED) ---
        $group->get('/categories', [CategoryAdminController::class, 'index'])
              ->setName('app.categories.index');
        $group->get('/categories/new', [CategoryAdminController::class, 'create'])
              ->setName('app.categories.create');
        $group->post('/categories', [CategoryAdminController::class, 'store'])
              ->setName('app.categories.store');
        $group->get('/categories/{id:[0-9]+}/edit', [CategoryAdminController::class, 'edit'])
              ->setName('app.categories.edit');
        $group->post('/categories/{id:[0-9]+}', [CategoryAdminController::class, 'update'])
              ->setName('app.categories.update');
        $group->post('/categories/{id:[0-9]+}/delete', [CategoryAdminController::class, 'delete'])
              ->setName('app.categories.delete');
              
        // ---  NEW: PRODUCT CRUD ROUTES (CHANGED) ---
        $group->get('/products', [ProductAdminController::class, 'index'])
              ->setName('app.products.index');
              
        $group->get('/products/new', [ProductAdminController::class, 'create'])
              ->setName('app.products.create');
              
        $group->post('/products', [ProductAdminController::class, 'store'])
              ->setName('app.products.store');
              
        $group->get('/products/{id}/edit', [ProductAdminController::class, 'edit'])
              ->setName('app.products.edit');
              
        $group->post('/products/{id}', [ProductAdminController::class, 'update'])
              ->setName('app.products.update');
              
        $group->post('/products/{id}/delete', [ProductAdminController::class, 'delete'])
              ->setName('app.products.delete');

    })->add(new RoleAuthMiddleware(['admin', 'superadmin']));


    // (Super Admin routes are unchanged)
    $app->group('/system', function (RouteCollectorProxy $group) {
       $group->get('/logs', [SystemAdminController::class, 'viewLogs']);
       $group->get('/users', [UnderConstructionController::class, 'show']);
    })->add(new RoleAuthMiddleware(['superadmin']));

};
