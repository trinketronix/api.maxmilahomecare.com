<?php
use Api\Controllers\UserPatientController;

if (isset($app)) {
    // User-patient assignment
    // JSON Body example { "user_id": 2, "patient_id": 12}
    $userPatient = new UserPatientController();

    // Post to assign patients
    // before '/user/assign/patient'
    $app->post('/assign/patient', [$userPatient, 'assignPatient']);

    // Post to assign many patients
    // sample body payload { "user_id": 12, "patient_ids": [1,2,3,4,5,6,7], "" }
    $app->post('/assign/patients', [$userPatient, 'assignPatients']);

    // Post to assign many patients
    // sample body payload { "user_id": 12, "patient_ids": [1,2,3,4,5,6,7], "" }
    $app->post('/unassign/patients', [$userPatient, 'unassignPatients']);


    // Get assigned patients by user ID
    // before `/user/{userId}/patients`
    $app->get('/assigned/patients/{userId}', [$userPatient, 'getUserAssignedPatients']);
    // Get patients NOT assigned to user ID
    $app->get('/unassigned/patients/{userId}', [$userPatient, 'getUserUnassignedPatients']);

    // Get assigned patients by user ID
    // before `/user/{userId}/patients`
    $app->get('/assigned/patients/addresses/{userId}', [$userPatient, 'getUserAssignedPatientsWithAddresses']);
    // Get patients NOT assigned to user ID
    $app->get('/unassigned/patients/addresses/{userId}', [$userPatient, 'getUserUnassignedPatientsWithAddresses']);


    // Get assigned users by patient ID
    $app->get('/assigned/users/{patientId}', [$userPatient, 'getPatientAssignedUsers']);
    // Get users NOT assigned to patient ID
    $app->get('/unassigned/users/{patientId}', [$userPatient, 'getPatientUnassignedUsers']);
}