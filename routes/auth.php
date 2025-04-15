<?php

use Api\Controllers\AuthController;

if (isset($app)) {
$auth = new AuthController();
    /* POST Requests */
    $app->post('/auth/register', [$auth, 'register']);
    $app->post('/auth/login', [$auth, 'login']);
    /* PUT Requests */
    $app->put('/auth/activate/account', [$auth, 'activateAccount']);
    $app->put('/auth/renew/token', [$auth, 'renewToken']);
    $app->put('/auth/change/role', [$auth, 'changeRole']);
    $app->put('/auth/change/password', [$auth, 'changePassword']);
}