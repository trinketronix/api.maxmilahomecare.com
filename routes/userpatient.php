<?php
use Api\Controllers\UserPatientController;

if (isset($app)) {
    // User-patient assignment
    // JSON Body example { "user_id": 2, "patient_id": 12}
    $userPatient = new UserPatientController();


    $app->post('/user/assign/patient', [$userPatient, 'create']);

    // Get assigned patients by user ID
    $app->get('/user/{userId}/patients', [$userPatient, 'getUserAssignedPatients']);
    // Get patients NOT assigned to user ID
//    $app->get('/unassigned/patients/{userId}', [$userPatient, 'getUserUnassignedPatients']);

//    $app->post('/assign/patient', [$userPatient, 'create']);
//
//    // Get assigned patients by user ID
//    $app->get('/assigned/patients/{userId}', [$userPatient, 'getUserAssignedPatients']);
//    // Get patients NOT assigned to user ID
//    $app->get('/unassigned/patients/{userId}', [$userPatient, 'getUserUnassignedPatients']);


    // Get assigned users by patient ID
    $app->get('/assigned/users/{patientId}', [$userPatient, 'getPatientAssignedUsers']);
    // Get users NOT assigned to patient ID
    $app->get('/unassigned/users/{patientId}', [$userPatient, 'getPatientUnassignedUsers']);
}