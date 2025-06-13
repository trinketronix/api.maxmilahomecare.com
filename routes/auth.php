<?php

use Api\Controllers\AuthController;

if (isset($app)) {
$auth = new AuthController();
    /* POST Requests */
    $app->post('/auth/register', [$auth, 'register']);
    $app->post('/auth/login', [$auth, 'login']);
    /* PUT Requests */
    $app->put('/auth/activate/account', [$auth, 'activateAccount']);
    $app->put('/auth/inactivate/account', [$auth, 'inactivateAccount']);
    $app->put('/auth/archive/account', [$auth, 'archivateAccount']);
    $app->put('/auth/delete/account', [$auth, 'deleteAccount']);

    $app->put('/auth/renew/token', [$auth, 'renewToken']);
    $app->put('/auth/change/role', [$auth, 'changeRole']);
    $app->put('/auth/change/password', [$auth, 'changePassword']);
    /* GET Requests */
    $app->get('/activation/{edoc}', [$auth, 'emailActivation']);
}