<?php
require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;
use Dotenv\Dotenv;
use SweetDelights\Mayie\Middleware\AuthMiddleware; 


if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

$app = AppFactory::create();

// ✅ --- ADD THIS LINE ---
// This middleware will parse JSON, url-encoded, and XML request bodies
// and make them available in $request->getParsedBody()
$app->addBodyParsingMiddleware();
// ✅ --------------------


$twig = Twig::create(__DIR__ . '/../src/Templates', [
    'cache' => false,
]);

// Add global env variables to Twig
$twig->getEnvironment()->addGlobal('env', $_ENV);


$app->add(TwigMiddleware::create($app, $twig));

$app->add(new AuthMiddleware($twig)); 

$app->addRoutingMiddleware();

$errorMiddleware = $app->addErrorMiddleware(true, true, true);

(require __DIR__ . '/../src/Routes/web.php')($app);

$app->run();