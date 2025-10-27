<?php

use Slim\App;
use SweetDelights\Mayie\Controllers\HomeController;
use SweetDelights\Mayie\Controllers\AboutController;
use SweetDelights\Mayie\Controllers\ProductsController;
use SweetDelights\Mayie\Controllers\DashboardController;

return function (App $app) {
    // --- Public Routes ---
    $app->get('/', [HomeController::class, 'index']);
    $app->get('/about', [AboutController::class, 'index']);
    $app->get('/products', [ProductsController::class, 'index']);
    $app->get('/products/{id}', [ProductsController::class, 'show']);

    // --- Admin Routes ---
    $app->group('/admin', function ($group) {
        $group->get('', [DashboardController::class, 'dashboard']);

        $group->get('/', [DashboardController::class, 'dashboard']);
        $group->get('/dashboard', [DashboardController::class, 'dashboard']);
    });

    
};
