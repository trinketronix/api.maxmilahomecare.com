<?php

use Api\Controllers\VisitController;

if (isset($app)) {
    $visit = new VisitController();
    $app->post('/visit', [$visit, 'create']);
    $app->get('/visits', [$visit, 'getVisits']);
    $app->put('/visit/{id}', [$visit, 'updateById']);
}