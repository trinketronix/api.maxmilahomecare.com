<?php

use Api\Controllers\UserController;

if (isset($app)) {
    $user = new UserController();
    $app->put('/user/{userId:[0-9]*}', [$user, 'updateUser']);
    $app->put('/user/update/photo', [$user, 'updatePhoto']);
    $app->post('/user/upload/photo', [$user, 'uploadPhoto']);
}