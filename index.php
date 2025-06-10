<?php

declare(strict_types=1);

use Phalcon\Mvc\Micro;
use Phalcon\Di\FactoryDefault;
use Phalcon\Events\Manager as EventsManager;

define('APP_ENV', getenv('APP_ENV') ?: 'dev');
define('API_BASE_URL', getenv('API_BASE_URL') ?: 'https://failsafe.maxmmilahomecare.com');
define('APP_BASE_URL', getenv('APP_BASE_URL') ?: 'https://failsafe.maxmmilahomecare.com');
define('DB_DATABASE', getenv('DB_DATABASE') ?: 'failsafe');
const BASE_PATH = __DIR__;

require_once BASE_PATH . '/configuration/loader.php';

try {
    $container = new FactoryDefault();
    require_once BASE_PATH . '/configuration/services.php';
    $app = new Micro($container);
    $eventsManager = new EventsManager();
    $app->setEventsManager($eventsManager);
    require_once BASE_PATH . '/configuration/middleware.php';
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
        'userpatient',
        'visit'
    ];
    foreach ($routeFiles as $routeFile) {
        require_once BASE_PATH . "/routes/{$routeFile}.php";
    }
    $app->notFound(function () {
        return [
            'status' => 'error',
            'code' => 404,
            'message' => 'Endpoint not found',
            'path' => $_SERVER['REQUEST_URI'] ?? 'unknown'
        ];
    });
    $uri = $_SERVER["REQUEST_URI"];
    $app->handle($uri);
} catch (\Throwable $e) {
    $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
    error_log('Critical Application Exception: ' . $message);
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