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

// Determine environment (default to 'production' if not set)
$appEnv = getenv('APP_ENV') ?: 'production';

// Load .env only in development
if ($appEnv === 'development') {
    $envPath = __DIR__ . '/../';
    if (file_exists($envPath . '.env')) {
        $dotenv = Dotenv::createImmutable($envPath);
        $dotenv->load();
    } else {
        error_log('⚠️  .env file not found in development mode.');
    }
}

$app = AppFactory::create();
$app->addBodyParsingMiddleware();

// Twig setup
$twig = Twig::create(__DIR__ . '/../src/Views', [
    'cache' => false,
]);

// Make environment variables available in templates
$twig->getEnvironment()->addGlobal('env', $_ENV);

// Middleware
$app->add(TwigMiddleware::create($app, $twig));
$app->add(new AuthMiddleware($twig));
$app->addRoutingMiddleware();

// Error display based on environment
$displayErrorDetails = ($appEnv === 'development');
$errorMiddleware = $app->addErrorMiddleware($displayErrorDetails, true, true);

// Routes
(require __DIR__ . '/../src/Routes/web.php')($app);

$app->run();
