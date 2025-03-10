<?php

use Api\Controllers\ToolController;

if (isset($app)) {
    $auth = new ToolController();
    /* POST Requests */
    $app->post('/tool', [$auth, 'create']);
    $app->get('/tool/{id}', [$auth, 'getById']);
    $app->get('/tools', [$auth, 'getAll']);
}