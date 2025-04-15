<?php

declare(strict_types=1);

/**
 * Entry point of the Maxmila Homecare REST API application.
 * PHP 8.4 implementation with Phalcon 5 framework.
 */

use Phalcon\Mvc\Micro;
use Phalcon\Di\FactoryDefault;
use Phalcon\Events\Manager as EventsManager;

// Define application environment
define('APP_ENV', getenv('APP_ENV') ?: 'dev');
const BASE_PATH = __DIR__;

// Autoloader
require_once BASE_PATH . '/configuration/loader.php';

try {
    // Create DI container
    $container = new FactoryDefault();

    // Configure services
    require_once BASE_PATH . '/configuration/services.php';

    // Create micro application
    $app = new Micro($container);

    // Set up events manager for application
    $eventsManager = new EventsManager();
    $app->setEventsManager($eventsManager);

    // Register middleware
    require_once BASE_PATH . '/configuration/middleware.php';

    // Load routes by group
    $routeFiles = [
        'auth',
//        'user',
//        'address',
//        'account',
//        'patient',
//        'visit',
//        'email',
        'tool',
        'default'
    ];

    foreach ($routeFiles as $routeFile) {
        require_once BASE_PATH . "/routes/{$routeFile}.php";
    }

    // Handle request
    $app->handle($_SERVER["REQUEST_URI"]);

} catch (\Throwable $e) {
    // Global exception handler
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'code' => $e->getCode(),
        'trace' => APP_ENV === 'dev' ? $e->getTraceAsString() : null
    ]);
}