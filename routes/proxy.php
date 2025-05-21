<?php

// Add this to your routes file (e.g., routes/default.php or create a new routes/proxy.php)

if (isset($app)) {
    // Route all requests with the /api-proxy prefix to the proxy controller
    $app->any('/proxy/{params}', [
        'controller' => 'ApiProxy',
        'action' => 'forward',
        'params' => 1
    ])->setName('apiProxy');

    // Allow for nested paths with multiple parts
    $app->any('/proxy/{params:.*}', [
        'controller' => 'ApiProxy',
        'action' => 'forward',
        'params' => 1
    ]);
}