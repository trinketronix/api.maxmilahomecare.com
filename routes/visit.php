<?php

use Api\Controllers\VisitController;

if (isset($app)) {
    $visit = new VisitController();

    // POST Routes - Create or Schedule a new visit
    $app->post('/visit/schedule', [$visit, 'schedule']);

    // GET Routes - Read visits
    $app->get('/visits', [$visit, 'getAllVisits']); // Manager/Admin only
    $app->get('/visit/{visitId:[0-9]+}', [$visit, 'getVisitById']);
    $app->get('/user/visits/{userId:[0-9]+}', [$visit, 'getUserVisits']);
    $app->get('/patient/visits/{patientId:[0-9]+}', [$visit, 'getPatientVisits']);

    // PUT Routes - Update
    $app->put('/visit/{visitId:[0-9]+}', [$visit, 'updateVisit']);
    $app->put('/visit/{visitId:[0-9]+}/checkin', [$visit, 'checkin']);
    $app->put('/visit/{visitId:[0-9]+}/checkout', [$visit, 'checkout']);
    $app->put('/visit/{visitId:[0-9]+}/approve', [$visit, 'approve']); // Manager/Admin only
    $app->put('/visit/{visitId:[0-9]+}/cancel', [$visit, 'cancel']);

    // PUT Routes - Status Changes (Manager/Admin only)
    $app->put('/visit/{visitId:[0-9]+}/status/visible', [$visit, 'changeStatusVisible']);
    $app->put('/visit/{visitId:[0-9]+}/status/archived', [$visit, 'changeStatusArchived']);
    $app->put('/visit/{visitId:[0-9]+}/status/deleted', [$visit, 'changeStatusSoftDeleted']);

}