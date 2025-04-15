<?php

declare(strict_types=1);

use Api\Services\TokenService;
use Phalcon\Db\Adapter\Pdo\Mysql;

// Create DI container
if (isset($container)) {

    // Database connection service
    $container->set(
        'db',
        [
            'className' => 'Phalcon\Db\Adapter\Pdo\Mysql',
            'arguments' => [
                [
                    'type' => 'parameter',
                    'value' => [
                        'host' => getenv('DB_HOSTPATH') ?: 'localhost',
                        'username' => getenv('DB_USERNAME') ?: 'root',
                        'password' => getenv('DB_PASSWORD') ?: '',
                        'dbname' => getenv('DB_DATABASE') ?: '',
                        'charset' => 'utf8mb4',
                        'options' => [
                            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                            PDO::ATTR_EMULATE_PREPARES => false,
                            PDO::ATTR_STRINGIFY_FETCHES => false,
                        ]
                    ]
                ]
            ],
            'shared' => true
        ]
    );

    // Token service registration with className
    $container->set(
        'tokenService',
        [
            'className' => TokenService::class,
            'shared' => true
        ]
    );
}