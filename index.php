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
define('BASE_URL', getenv('BASE_URL') ?: 'https://failsafe.maxmmilahomecare.com');
define('DB_DATABASE', getenv('DB_DATABASE') ?: 'failsafe');
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

    // Register middleware (this handles CORS, auth, response formatting, etc.)
    require_once BASE_PATH . '/configuration/middleware.php';

    // Load routes by group
    $routeFiles = [
        'account',
        'address',
        'auth',
        'bulk',
        'default',
        'email',
        'patient',
        'tool',
        'user',
        'visit'
    ];

    foreach ($routeFiles as $routeFile) {
        require_once BASE_PATH . "/routes/{$routeFile}.php";
    }

    // Simple Not-Found handler (middleware will handle response formatting)
    $app->notFound(function () {
        // Return a simple array that middleware will format properly
        return [
            'status' => 'error',
            'code' => 404,
            'message' => 'Endpoint not found',
            'path' => $_SERVER['REQUEST_URI'] ?? 'unknown'
        ];
    });

    // Handle request - Let Phalcon handle URI parsing automatically
    $app->handle($_SERVER['REQUEST_URI']);

} catch (\Throwable $e) {
    // Log the error
    $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
    error_log('Application Exception: ' . $message);

    // Simple fallback response (only if middleware fails to load)
    if (!headers_sent()) {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        http_response_code(500);

        echo json_encode([
            'status' => 'error',
            'code' => 500,
            'message' => 'Application initialization failed',
            'debug' => APP_ENV === 'dev' ? $e->getMessage() : null
        ]);
    }
}