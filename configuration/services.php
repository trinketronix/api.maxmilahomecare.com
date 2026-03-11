<?php

declare(strict_types=1);

use Api\Services\TokenService;

// Create DI container
if (isset($container)) {

    // Database connection service
    $container->set('db', function () {

        // PHP already knows the correct offset for America/Detroit
        // including daylight saving transitions
        $now = new DateTimeImmutable('now', new DateTimeZone('America/Detroit'));
        $offsetSeconds = $now->getOffset();           // e.g. -14400 (EDT) or -18000 (EST)
        $offsetHours   = intdiv($offsetSeconds, 3600);
        $offsetMinutes = abs(intdiv($offsetSeconds % 3600, 60));
        $mysqlOffset   = sprintf('%+03d:%02d', $offsetHours, $offsetMinutes); // "-04:00" or "-05:00"

        $connection = new Phalcon\Db\Adapter\Pdo\Mysql([
            'host'     => getenv('DB_HOSTPATH') ?: 'localhost',
            'username' => getenv('DB_USERNAME') ?: 'root',
            'password' => getenv('DB_PASSWORD') ?: '********',
            'dbname'   => getenv('DB_DATABASE') ?: 'failsafe',
            'charset'  => 'utf8mb4',
            'options'  => [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]
        ]);

        // Set the MySQL session timezone to match Detroit
        $connection->execute("SET time_zone = '{$mysqlOffset}'");

        return $connection;

    }, true); // true = shared service

    // Token service registration with className
    $container->set(
        'tokenService',
        [
            'className' => TokenService::class,
            'shared'    => true
        ]
    );
}
