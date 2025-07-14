<?php

use Api\Controllers\VisitController;

if (isset($app)) {
    $visit = new VisitController();

    // POST Routes - Create
    $app->post('/visit', [$visit, 'schedule']);

    // GET Routes - Read
    $app->get('/visit/{id}', [$visit, 'getById']);
    $app->get('/visits', [$visit, 'getVisits']);
    $app->get('/visits/today', [$visit, 'getTodaysVisits']);
    $app->get('/user/visits/{userId}', [$visit, 'getUserVisits']);
    $app->get('/patient/visits/{patientId}', [$visit, 'getPatientVisits']);

    // PUT Routes - Update
    $app->put('/visit/{id}', [$visit, 'update']);
    $app->put('/visit/{id}/progress', [$visit, 'updateProgress']);
    $app->put('/visit/{id}/checkin', [$visit, 'checkIn']);
    $app->put('/visit/{id}/checkout', [$visit, 'checkOut']);
    $app->put('/visit/{id}/cancel', [$visit, 'cancel']);

    // DELETE Routes - Delete
    $app->delete('/visit/{id}', [$visit, 'delete']);
}