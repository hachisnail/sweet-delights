<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy; 

use SweetDelights\Mayie\Controllers\Public\HomeController;
use SweetDelights\Mayie\Controllers\Public\AboutController;
use SweetDelights\Mayie\Controllers\Public\ProductsController;
use SweetDelights\Mayie\Controllers\Public\SearchController;

use SweetDelights\Mayie\Controllers\Customer\AccountController;
use SweetDelights\Mayie\Controllers\Customer\CheckoutController; 
use SweetDelights\Mayie\Controllers\UnderConstructionController;

use SweetDelights\Mayie\Controllers\Api\ApiAuthController; 
use SweetDelights\Mayie\Controllers\Api\ApiCartController;
use SweetDelights\Mayie\Controllers\Api\ApiFavouritesController;
use SweetDelights\Mayie\Controllers\Api\ApiSearchController; 
use SweetDelights\Mayie\Middleware\ApiAuthMiddleware;

use SweetDelights\Mayie\Controllers\Admin\DashboardAdminController;
use SweetDelights\Mayie\Controllers\Admin\SystemAdminController; 
use SweetDelights\Mayie\Controllers\Admin\UserAdminController;
use SweetDelights\Mayie\Controllers\Admin\OrderAdminController;   
use SweetDelights\Mayie\Controllers\Admin\ReportsAdminController;
use SweetDelights\Mayie\Controllers\Admin\SettingsAdminController;

use SweetDelights\Mayie\Controllers\Admin\CategoryAdminController; 
use SweetDelights\Mayie\Controllers\Admin\ProductAdminController; 

use SweetDelights\Mayie\Middleware\RoleAuthMiddleware;

return function (App $app) {

    // (Public and Auth routes)
    $app->get('/', [HomeController::class, 'index'])->setName('home');
    $app->get('/about', [AboutController::class, 'index']);
    $app->get('/products', [ProductsController::class, 'index'])->setName('products.index');
    
    $app->get('/search', [SearchController::class, 'index'])->setName('search.index');

    $app->get('/products/{sku}', [ProductsController::class, 'show'])->setName('products.show');

    $app->get('/register', [ApiAuthController::class, 'showRegister']);
    $app->post('/register', [ApiAuthController::class, 'register']);
    $app->get('/verify-message', [ApiAuthController::class, 'showVerificationMessage']);
    $app->get('/verify-email', [ApiAuthController::class, 'verifyEmail']);
    
    $app->get('/forgot-password', [ApiAuthController::class, 'showForgotPassword']);
    $app->post('/forgot-password', [ApiAuthController::class, 'handleForgotPassword']);
    $app->get('/reset-password', [ApiAuthController::class, 'showResetPassword']);
    $app->post('/reset-password', [ApiAuthController::class, 'handleResetPassword']);
    
    $app->get('/login', [ApiAuthController::class, 'showLogin']);
    $app->post('/login', [ApiAuthController::class, 'login']);
    $app->get('/logout', [ApiAuthController::class, 'logout']);


    // (API routes)
    $app->group('/api', function (RouteCollectorProxy $group) {
        
        $group->get('/search', ApiSearchController::class);

        // --- Authenticated API routes ---
        $group->group('', function (RouteCollectorProxy $authGroup) {
            $authGroup->post('/cart/sync', [ApiCartController::class, 'sync']);
            $authGroup->post('/favourites/sync', [ApiFavouritesController::class, 'sync']);
        })->add(new ApiAuthMiddleware());

    });


    // (Account routes)
    $app->group('/account', function (RouteCollectorProxy $group) {
        $group->get('/settings', [AccountController::class, 'showSettings']);
        $group->get('/orders', [AccountController::class, 'showOrders'])->setName('account.orders');
        $group->get('/orders/{id:[0-9]+}', [AccountController::class, 'showOrderDetails'])->setName('account.orders.show');
        $group->post('/orders/{id:[0-9]+}/confirm-delivery', [AccountController::class, 'confirmDelivery'])->setName('account.orders.confirm');
        $group->post('/settings/update', [AccountController::class, 'updateProfile']);
        $group->post('/settings/password', [AccountController::class, 'updatePassword']);
    })->add(new RoleAuthMiddleware(['customer', 'admin', 'superadmin']));

    // --- NEW CHECKOUT ROUTES ---
    // This group handles the checkout flow and must be authenticated.
    $app->group('/checkout', function (RouteCollectorProxy $group) {
        $group->get('', [CheckoutController::class, 'showCheckout'])->setName('checkout.show');
        $group->post('/process', [CheckoutController::class, 'processCheckout'])->setName('checkout.process');
        $group->get('/success', [CheckoutController::class, 'showSuccess'])->setName('checkout.success');
    })->add(new RoleAuthMiddleware(['customer', 'admin', 'superadmin']));


    // --- Admin Routes ---
    $app->group('/app', function (RouteCollectorProxy $group) {
        
        // Dashboard
        $group->get('', [DashboardAdminController::class, 'dashboard'])->setName('app.dashboard');
        $group->get('/', [DashboardAdminController::class, 'dashboard'])->setName('app.dashboard.slash');
        $group->get('/dashboard', [DashboardAdminController::class, 'dashboard'])->setName('app.dashboard.main');
        
        // Users
        $group->get('/users', [UserAdminController::class, 'index'])->setName('app.users.index'); // <-- CHANGED
        $group->get('/users/new', [UserAdminController::class, 'create'])->setName('app.users.create'); // <-- NEW
        $group->post('/users', [UserAdminController::class, 'store'])->setName('app.users.store'); // <-- NEW
        $group->get('/users/{id:[0-9]+}/edit', [UserAdminController::class, 'edit'])->setName('app.users.edit'); // <-- NEW
        $group->post('/users/{id:[0-9]+}', [UserAdminController::class, 'update'])->setName('app.users.update'); // <-- NEW
        
        // Placeholder
        $group->get('/orders', [OrderAdminController::class, 'index'])->setName('app.orders.index'); // Replaces placeholder
        $group->get('/orders/{id:[0-9]+}', [OrderAdminController::class, 'show'])->setName('app.orders.show');
        $group->post('/orders/{id:[0-9]+}/update-status', [OrderAdminController::class, 'updateStatus'])->setName('app.orders.update');

        $group->get('/reports', [ReportsAdminController::class, 'index'])->setName('app.reports.index');
        $group->get('/reports/export', [ReportsAdminController::class, 'export'])->setName('app.reports.export');

        $group->get('/settings', [SettingsAdminController::class, 'show'])->setName('app.settings');
        $group->post('/settings', [SettingsAdminController::class, 'update'])->setName('app.settings.update');

        // ---  CATEGORY CRUD ROUTES ---
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
              
        // ---  PRODUCT CRUD ROUTES ---
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
       $group->get('/users', [SystemAdminController::class, 'manageUsers'])->setName('system.users.index');
       $group->get('/users/new', [SystemAdminController::class, 'createUser'])->setName('system.users.create');
       $group->post('/users', [SystemAdminController::class, 'storeUser'])->setName('system.users.store');
       $group->get('/users/{id:[0-9]+}/edit', [SystemAdminController::class, 'editUser'])->setName('system.users.edit');
       $group->post('/users/{id:[0-9]+}', [SystemAdminController::class, 'updateUser'])->setName('system.users.update');
    })->add(new RoleAuthMiddleware(['superadmin']));

};