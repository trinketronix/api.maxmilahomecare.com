<?php

use Api\Controllers\PatientController;

if (isset($app)) {
    $patient = new PatientController();

    // POST Routes - Create
    $app->post('/patient/new', [$patient, 'create']);

    // GET Routes - Read
    // TODO: Add getById and getAll methods to controller
    $app->get('/patients', [$patient, 'getAllPatients']);
    $app->get('/patients/addresses', [$patient, 'getAllPatientsWithAddresses']);
    $app->get('/patient/{id}', [$patient, 'getPatientById']);

    // PUT Routes - Update
    $app->put('/patient/{id}', [$patient, 'updatePatient']);

    $app->put('/patient/{id}/activate', [$patient, 'activatePatient']);
    $app->put('/patient/{id}/inactivate', [$patient, 'inactivatePatient']);
    $app->put('/patient/{id}/archivate', [$patient, 'archivatePatient']);
    $app->put('/patient/{id}/delete', [$patient, 'deletePatient']); // Soft delete

    // DELETE Routes - Delete (Full Delete)
    $app->delete('/patient/{id}', [$patient, 'delete']);

    // Upload photo routes
    // For uploading own photo (backward compatibility)
    $app->post('/patient/upload/photo', [$patient, 'uploadPhoto']);

    // For uploading photo for a specific user (admin/manager)
    $app->post('/patient/{userId:[0-9]+}/upload/photo', [$patient, 'uploadPhoto']);

    // Update photo routes
    // For updating own photo (backward compatibility)
    $app->put('/patient/update/photo', [$patient, 'updatePhoto']);

    // For updating photo for a specific user (admin/manager)
    $app->put('/patient/{userId:[0-9]+}/update/photo', [$patient, 'updatePhoto']);

}