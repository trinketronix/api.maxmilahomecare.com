<?php

use Api\Controllers\UserController;

if (isset($app)) {
    $user = new UserController();
    $app->put('/user/{userId}', [$user, 'updateUser']);
    $app->put('/update/photo', [$user, 'updatePhoto']);
    $app->post('/upload/photo', [$user, 'uploadPhoto']);
}