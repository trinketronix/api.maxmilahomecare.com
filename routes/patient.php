<?php

use Api\Controllers\PatientController;

if (isset($app)) {
    $patient = new PatientController();

    // POST Routes - Create
    $app->post('/patient', [$patient, 'create']);

    // GET Routes - Read
    // TODO: Add getById and getAll methods to controller
    $app->get('/patients', [$patient, 'getAllPatients']);
    $app->get('/patients/addresses', [$patient, 'getAllPatientsWithAddresses']);
    $app->get('/patient/{id}', [$patient, 'getById']);

    // PUT Routes - Update
    $app->put('/patient/{id}', [$patient, 'update']);

    $app->put('/patient/{id}/activate', [$patient, 'activatePatient']);
    $app->put('/patient/{id}/inactivate', [$patient, 'inactivatePatient']);
    $app->put('/patient/{id}/archivate', [$patient, 'archivatePatient']);
    $app->put('/patient/{id}/delete', [$patient, 'deletePatient']);

    // DELETE Routes - Delete (Soft Delete)
    $app->delete('/patient/{id}', [$patient, 'delete']);
}