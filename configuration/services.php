<?php

declare(strict_types=1);

use Api\Services\TokenService;
use Phalcon\Db\Adapter\Pdo\Mysql;

// Create DI container
if (isset($container)) {

    // Database connection service
    $container->set(
        'db',
        function (){
            return new Mysql([
                'host' => getenv('DB_HOSTPATH') ?: 'localhost',
                'username' => getenv('DB_USERNAME') ?: 'root',
                'password' => getenv('DB_PASSWORD') ?: '',
                'dbname' => getenv('DB_DATABASE') ?: '',
                'charset' => 'utf8mb4',
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