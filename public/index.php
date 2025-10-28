<?php
require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;
use Dotenv\Dotenv;
use SweetDelights\Mayie\Middleware\AuthMiddleware; // 1. Import AuthMiddleware

// 2. Start session (this MUST be at the top)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 3. Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

// 4. Create App
$app = AppFactory::create();

// 5. Create Twig
$twig = Twig::create(__DIR__ . '/../src/Templates', [
    'cache' => false,
]);
// Add global env variables to Twig
$twig->getEnvironment()->addGlobal('env', $_ENV);

// 6. Add Middleware (This order is LIFO - Last-In, First-Out)
// This means the ErrorMiddleware runs first, then Routing, then Auth, then Twig.

// Add TwigMiddleware (runs 4th)
$app->add(TwigMiddleware::create($app, $twig));

// Add AuthMiddleware (runs 3rd)
$app->add(new AuthMiddleware($twig)); 

// === THIS IS THE FIX ===
// Add Slim's RoutingMiddleware (runs 2nd)
// This finds the route and MUST come before your custom middleware.
$app->addRoutingMiddleware();
// ========================

// Add Error Handling Middleware (runs 1st)
// This should be the "outermost" middleware to catch all errors.
$errorMiddleware = $app->addErrorMiddleware(true, true, true);

// 7. Load routes
// Routes are defined *after* all middleware has been added.
(require __DIR__ . '/../src/Routes/web.php')($app);

// 8. Run the App
$app->run();