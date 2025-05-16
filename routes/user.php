<?php

use Api\Controllers\UserController;
use Api\Controllers\UserPatientController;

if (isset($app)) {
    $user = new UserController();
    $app->put('/user', [$user, 'updateUser']);
    $app->put('/user/update/photo', [$user, 'updatePhoto']);
    $app->post('/user/upload/photo', [$user, 'uploadPhoto']);

    // Add the new route for user-patient assignment
    $userPatient = new UserPatientController();
    $app->post('/user/assign/patient', [$userPatient, 'create']);

    // New route to get patients by user ID
    $app->get('/user/{userId}/patients', [$userPatient, 'getUserPatients']);
}