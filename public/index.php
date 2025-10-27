<?php
require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;
use Dotenv\Dotenv;

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

$app = AppFactory::create();

$twig = Twig::create(__DIR__ . '/../src/Templates', [
    'cache' => false,
]);

$twig->getEnvironment()->addGlobal('env', $_ENV);

// Add Twig middleware to Slim
$app->add(TwigMiddleware::create($app, $twig));

// Load routes
(require __DIR__ . '/../src/Routes/web.php')($app);

$app->run();
