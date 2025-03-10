<?php

declare(strict_types=1);

/**
 * Autoloader Configuration
 *
 * This file configures the Phalcon autoloader for the Maxmila application.
 * The autoloader maps namespaces to directories, enabling automatic loading of classes
 * without requiring manual `include` or `require` statements.
 */

use Phalcon\Autoload\Loader;

// Define base paths
$rootPath = dirname(__DIR__);
$utilsPath = $rootPath . '/utils';

// Create and configure loader
$loader = new Loader();

// Configure namespaces with more organized structure
$loader->setNamespaces([
    // Utility namespaces
    'Api\Encoding'   => $utilsPath . '/encoding/',
    'Api\Email'      => $utilsPath . '/email/',
    'Api\Http'       => $utilsPath . '/http/',

    // Core application namespaces
    'Api\Constants'  => $rootPath . '/constants/',
    'Api\Models'     => $rootPath . '/models/',
    'Api\Controllers'=> $rootPath . '/controllers/',

    // Add middleware namespace
    'Api\Middleware' => $rootPath . '/middleware/',

    // Add service namespace for better organization
    'Api\Services'   => $rootPath . '/services/',

    // Add repository namespace if using repository pattern
    'Api\Repositories' => $rootPath . '/repositories/',
])->register();

// Return the loader for potential use in other files
return $loader;