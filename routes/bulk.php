<?php

use Api\Controllers\BulkController;

if (isset($app)) {
    $bulk = new BulkController();
    $app->post('/bulk/auths', [$bulk, 'auths']);
    $app->put('/bulk/users', [$bulk, 'users']);
    $app->post('/bulk/patients', [$bulk, 'patients']);
    $app->post('/bulk/addresses', [$bulk, 'addresses']);
    $app->post('/bulk/user-patient', [$bulk, 'userPatient']);
    $app->post('/bulk/visits', [$bulk, 'visits']);
}