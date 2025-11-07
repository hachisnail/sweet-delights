<?php
require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;
use Dotenv\Dotenv;
use SweetDelights\Mayie\Middleware\AuthMiddleware;


$envPath = __DIR__ . '/../';
if (file_exists($envPath . '.env')) {
    $dotenv = Dotenv::createImmutable($envPath);
    $dotenv->load(); 
} else {
    // error_log('.env file not found.');
}

$appEnv = $_ENV['ENV'] ?? 'production';



if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$app = AppFactory::create();
$app->addBodyParsingMiddleware();

$twig = Twig::create(__DIR__ . '/../src/Views', [
    'cache' => false,
]);

$twig->getEnvironment()->addGlobal('env', $_ENV);

$app->add(TwigMiddleware::create($app, $twig));
$app->add(new AuthMiddleware($twig));
$app->addRoutingMiddleware();

$displayErrorDetails = ($appEnv === 'development');
$errorMiddleware = $app->addErrorMiddleware($displayErrorDetails, true, true);

(require __DIR__ . '/../src/Routes/web.php')($app);

$app->run();