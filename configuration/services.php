<?php

declare(strict_types=1);

use Api\Services\TokenService;
use Phalcon\Di\FactoryDefault;
use Phalcon\Db\Adapter\Pdo\Mysql;

// Determine environment
$appEnv = getenv('APP_ENV') ?: 'dev';

// Load environment-specific configuration
$configFile = require_once __DIR__ . "/configuration/environment/{$appEnv}.php";
if (file_exists($configFile)) {
    $config = require $configFile;
} else {
    throw new \Exception("Configuration file for environment '{$appEnv}' not found");
}

// Create DI container
if (isset($container)) {
    // Register config in the DI container
    $container->set(
        'config',
        function () use ($config) {
            return $config;
        },
        true // shared service
    );

    // Database connection service
    $container->set(
        'db',
        function () use ($container) {

            // Access the config service using get()
            $config = $container->get('config');
            $dbConfig = $config->database;

            return new Mysql([
                'host' => $dbConfig['host'],
                'username' => $dbConfig['username'],
                'password' => $dbConfig['password'],
                'dbname' => $dbConfig['dbname'],
                'charset' => $dbConfig['charset'] ?? 'utf8mb4',
                'options' => [
                    // Set error mode to exceptions
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    // Convert empty strings to null
                    PDO::ATTR_EMULATE_PREPARES => false,
                    // Use native prepared statements
                    PDO::ATTR_STRINGIFY_FETCHES => false,
                ]
            ]);
        },
        true // shared service
    );

    // In your services.php file:
    $container->set(
        'tokenService',
        function () {
            return new TokenService();
        },
        true
    );
}

// Return the final configuration
return $configFile;