<?php

use Api\Controllers\UserController;

if (isset($app)) {
    $user = new UserController();

    // Update user data
    $app->put('/user/{userId:[0-9]*}', [$user, 'updateUser']);

    // Get user photo
    $app->get('/user/photo/{userId:[0-9]*}', [$user, 'getPhoto']);

    // Upload photo routes
    // For uploading own photo (backward compatibility)
    $app->post('/user/upload/photo', [$user, 'uploadPhoto']);

    // For uploading photo for a specific user (admin/manager)
    $app->post('/user/{userId:[0-9]+}/upload/photo', [$user, 'uploadPhoto']);

    // Update photo routes
    // For updating own photo (backward compatibility)
    $app->post('/user/update/photo', [$user, 'updatePhoto']);

    // For updating photo for a specific user (admin/manager)
    $app->post('/user/{userId:[0-9]+}/update/photo', [$user, 'updatePhoto']);
}