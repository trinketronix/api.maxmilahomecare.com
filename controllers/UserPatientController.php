<?php

declare(strict_types=1);

namespace Api\Controllers;

use Api\Constants\PersonType;
use Api\Constants\Status;
use Api\Models\Address;
use Api\Models\Auth;
use Api\Models\User;
use Api\Models\Patient;
use Api\Models\UserPatient;
use Api\Constants\Message;
use Exception;

class UserPatientController extends BaseController {
    /**
     * Create a user-patient assignment
     *
     * @return array Response data
     */
    public function assignPatient(): array {
        try {
            // Verify manager role or higher
            if (!$this->isManagerOrHigher())
                return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 403);

            $data = $this->getRequestBody();

            // Validate required fields
            $requiredFields = [
                UserPatient::USER_ID => 'User ID is required',
                UserPatient::PATIENT_ID => 'Patient ID is required'
            ];

            foreach ($requiredFields as $field => $message) {
                if (empty($data[$field]))
                    return $this->respondWithError($message, 400);
            }

            $userId = (int)$data[UserPatient::USER_ID];
            $patientId = (int)$data[UserPatient::PATIENT_ID];

            // Check if user exists
            $user = User::findFirst($userId);
            if (!$user)
                return $this->respondWithError(Message::USER_NOT_FOUND, 404);

            // Check if patient exists
            $patient = Patient::findFirst($patientId);
            if (!$patient)
                return $this->respondWithError(Message::PATIENT_NOT_FOUND, 404);

            // Check if assignment already exists
            $existingAssignment = UserPatient::findAssignment($userId, $patientId);
            if ($existingAssignment) {
                // If it exists but is inactive, reactivate it
                if ($existingAssignment->status === Status::INACTIVE) {
                    return $this->withTransaction(function() use ($existingAssignment) {
                        $existingAssignment->status = Status::ACTIVE;
                        $existingAssignment->assigned_by = $this->getCurrentUserId();
                        $existingAssignment->assigned_at = date('Y-m-d H:i:s');

                        if (isset($data[UserPatient::NOTES]))
                            $existingAssignment->notes = $data[UserPatient::NOTES];

                        if (!$existingAssignment->save()) {
                            $messages = $existingAssignment->getMessages(); // This is Phalcon\Messages\MessageInterface[]
                            $msg = "An unknown error occurred."; // Default/fallback

                            if (count($messages) > 0) {
                                // Get the first message object from the array
                                $obj = $messages[0]; // or current($phalconMessages)

                                // Extract the string message from the object
                                // The MessageInterface guarantees the getMessage() method.
                                $msg = $obj->getMessage();
                            }

                            // Pass the extracted string message to your responder
                            return $this->respondWithError($msg, 422);
                        }

                        return $this->respondWithSuccess([
                            'message' => 'User-patient assignment reactivated successfully',
                            'assignment' => $existingAssignment->toArray()
                        ], 201, 'User-patient assignment reactivated successfully');
                    });
                }

                return $this->respondWithError('User is already assigned to this patient', 409);
            }

            // Create assignment within transaction
            return $this->withTransaction(function() use ($userId, $patientId, $data) {
                $assignment = new UserPatient();
                $assignment->user_id = $userId;
                $assignment->patient_id = $patientId;
                $assignment->assigned_by = $this->getCurrentUserId();

                if (isset($data[UserPatient::NOTES]))
                    $assignment->notes = $data[UserPatient::NOTES];

                if (!$assignment->save()) {
                    $messages = $assignment->getMessages(); // This is Phalcon\Messages\MessageInterface[]
                    $msg = "An unknown error occurred."; // Default/fallback

                    if (count($messages) > 0) {
                        // Get the first message object from the array
                        $obj = $messages[0]; // or current($phalconMessages)

                        // Extract the string message from the object
                        // The MessageInterface guarantees the getMessage() method.
                        $msg = $obj->getMessage();
                    }

                    // Pass the extracted string message to your responder
                    return $this->respondWithError($msg, 422);
                }

                return $this->respondWithSuccess([
                    'message' => 'User-patient assignment created successfully',
                    'assignment' => $assignment->toArray()
                ], 201, 'User-patient assignment created successfully');
            });

        } catch (Exception $e) {
            $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
            error_log('Exception: ' . $message);
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Assign multiple patients to a single user
     *
     * @return array Response data
     */
    public function assignPatients(): array
    {
        try {
            // Verify manager role or higher
            if (!$this->isManagerOrHigher())
                return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 403);

            $data = $this->getRequestBody();

            // Validate required fields
            if (empty($data[UserPatient::USER_ID]))
                return $this->respondWithError('User ID is required', 400);

            if (empty($data[UserPatient::PATIENT_IDS]) || !is_array($data[UserPatient::PATIENT_IDS]))
                return $this->respondWithError('Patient IDs array is required', 400);

            if (count($data[UserPatient::PATIENT_IDS]) === 0)
                return $this->respondWithError('At least one patient ID is required', 400);

            $userId = (int)$data[UserPatient::USER_ID];
            $patientIds = array_map('intval', $data[UserPatient::PATIENT_IDS]); // Convert all to integers
            $notes = $data[UserPatient::NOTES] ?? null; // Optional notes for all assignments

            // Check if user exists
            $user = User::findFirst($userId);
            if (!$user)
                return $this->respondWithError(Message::USER_NOT_FOUND, 404);

            // Validate all patient IDs exist before processing
            $validPatients = [];
            $invalidPatients = [];

            foreach ($patientIds as $patientId) {
                $patient = Patient::findFirst($patientId);
                if ($patient) {
                    $validPatients[] = $patient;
                } else {
                    $invalidPatients[] = $patientId;
                }
            }

            // If any patients don't exist, return error with details
            if (!empty($invalidPatients)) {
                return $this->respondWithError([
                    'message' => 'Some patient IDs were not found',
                    'invalid_patient_ids' => $invalidPatients
                ], 404);
            }

            $results = [
                'success' => [],
                'failed' => [],
                'skipped' => []
            ];

            // Process assignments within transaction
            return $this->withTransaction(function () use ($userId, $validPatients, $notes, &$results) {
                $currentUserId = $this->getCurrentUserId();

                foreach ($validPatients as $patient) {
                    try {
                        $patientId = $patient->id;

                        // Check if assignment already exists
                        $existingAssignment = UserPatient::findAssignment($userId, $patientId);

                        if ($existingAssignment) {
                            // If it exists but is inactive, reactivate it
                            if ($existingAssignment->status === Status::INACTIVE) {
                                $existingAssignment->status = Status::ACTIVE;
                                $existingAssignment->assigned_by = $currentUserId;
                                $existingAssignment->assigned_at = date('Y-m-d H:i:s');

                                if ($notes) {
                                    $existingAssignment->notes = $notes;
                                }

                                if (!$existingAssignment->save()) {
                                    $messages = $existingAssignment->getMessages();
                                    $msg = "An unknown error occurred.";

                                    if (count($messages) > 0) {
                                        $obj = $messages[0];
                                        $msg = $obj->getMessage();
                                    }

                                    $results['failed'][] = [
                                        UserPatient::PATIENT_ID => $patientId,
                                        'patient_name' => $patient->getFullName(),
                                        'error' => 'Failed to reactivate assignment: ' . $msg
                                    ];
                                    continue;
                                }

                                $results['success'][] = [
                                    UserPatient::PATIENT_ID => $patientId,
                                    'patient_name' => $patient->getFullName(),
                                    'action' => 'reactivated'
                                ];
                                continue;
                            }

                            // Assignment already exists and is active
                            $results['skipped'][] = [
                                UserPatient::PATIENT_ID => $patientId,
                                'patient_name' => $patient->getFullName(),
                                'reason' => 'already_assigned'
                            ];
                            continue;
                        }

                        // Create new assignment
                        $assignment = new UserPatient();
                        $assignment->user_id = $userId;
                        $assignment->patient_id = $patientId;
                        $assignment->assigned_by = $currentUserId;

                        if ($notes) {
                            $assignment->notes = $notes;
                        }

                        if (!$assignment->save()) {
                            $messages = $assignment->getMessages();
                            $msg = "An unknown error occurred.";

                            if (count($messages) > 0) {
                                $obj = $messages[0];
                                $msg = $obj->getMessage();
                            }

                            $results['failed'][] = [
                                UserPatient::PATIENT_ID => $patientId,
                                'patient_name' => $patient->getFullName(),
                                'error' => 'Failed to create assignment: ' . $msg
                            ];
                            continue;
                        }

                        $results['success'][] = [
                            UserPatient::PATIENT_ID => $patientId,
                            'patient_name' => $patient->getFullName(),
                            'action' => 'assigned'
                        ];

                    } catch (Exception $e) {
                        $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
                        error_log('Exception: ' . $message);
                        $results['failed'][] = [
                            UserPatient::PATIENT_ID => $patient->id,
                            'patient_name' => $patient->getFullName(),
                            'error' => $e->getMessage()
                        ];
                        continue;
                    }
                }

                // Determine response based on results
                $totalRequested = count($validPatients);
                $totalSuccess = count($results['success']);
                $totalFailed = count($results['failed']);
                $totalSkipped = count($results['skipped']);

                // If no assignments were created or reactivated, but some were skipped
                if ($totalSuccess === 0 && $totalSkipped > 0 && $totalFailed === 0) {
                    return $this->respondWithSuccess([
                        'message' => "All {$totalSkipped} patients were already assigned to this user",
                        UserPatient::USER_ID => $userId,
                        'results' => $results
                    ], 200, "All patients were already assigned");
                }

                // If no assignments were successful at all
                if ($totalSuccess === 0) {
                    return $this->respondWithError([
                        'message' => 'No patient assignments were created',
                        UserPatient::USER_ID => $userId,
                        'results' => $results
                    ], 422);
                }

                // Success response with details
                $message = "{$totalSuccess} patient(s) assigned successfully";
                if ($totalSkipped > 0) {
                    $message .= ", {$totalSkipped} already assigned";
                }
                if ($totalFailed > 0) {
                    $message .= ", {$totalFailed} failed";
                }

                return $this->respondWithSuccess([
                    'message' => $message,
                    UserPatient::USER_ID => $userId,
                    'total_requested' => $totalRequested,
                    'total_success' => $totalSuccess,
                    'total_failed' => $totalFailed,
                    'total_skipped' => $totalSkipped,
                    'results' => $results
                ], 201, $message);
            });

        } catch (Exception $e) {
            $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
            error_log('Exception: ' . $message);
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Unassign multiple patients from a single user
     *
     * @return array Response data
     */
    public function unassignPatients(): array
    {
        try {
            // Verify manager role or higher
            if (!$this->isManagerOrHigher())
                return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 403);

            $data = $this->getRequestBody();

            // Validate required fields
            if (empty($data[UserPatient::USER_ID]))
                return $this->respondWithError('User ID is required', 400);

            if (empty($data[UserPatient::PATIENT_IDS]) || !is_array($data[UserPatient::PATIENT_IDS]))
                return $this->respondWithError('Patient IDs array is required', 400);

            if (count($data[UserPatient::PATIENT_IDS]) === 0)
                return $this->respondWithError('At least one patient ID is required', 400);

            $userId = (int)$data[UserPatient::USER_ID];
            $patientIds = array_map('intval', $data[UserPatient::PATIENT_IDS]); // Convert all to integers

            // Check if user exists
            $user = User::findFirst($userId);
            if (!$user)
                return $this->respondWithError(Message::USER_NOT_FOUND, 404);

            // Validate all patient IDs exist before processing
            $validPatients = [];
            $invalidPatients = [];

            foreach ($patientIds as $patientId) {
                $patient = Patient::findFirst($patientId);
                if ($patient) {
                    $validPatients[] = $patient;
                } else {
                    $invalidPatients[] = $patientId;
                }
            }

            // If any patients don't exist, return error with details
            if (!empty($invalidPatients)) {
                return $this->respondWithError([
                    'message' => 'Some patient IDs were not found',
                    'invalid_patient_ids' => $invalidPatients
                ], 404);
            }

            $results = [
                'success' => [],
                'failed' => [],
                'skipped' => []
            ];

            // Process unassignments within transaction
            return $this->withTransaction(function () use ($userId, $validPatients, &$results) {
                foreach ($validPatients as $patient) {
                    try {
                        $patientId = $patient->id;

                        // Check if assignment exists
                        $existingAssignment = UserPatient::findAssignment($userId, $patientId);

                        if (!$existingAssignment) {
                            // Assignment doesn't exist - skip
                            $results['skipped'][] = [
                                UserPatient::PATIENT_ID => $patientId,
                                'patient_name' => $patient->getFullName(),
                                'reason' => 'not_assigned'
                            ];
                            continue;
                        }

                        // Check if assignment is already inactive
                        if ($existingAssignment->status === Status::INACTIVE) {
                            // Already inactive - skip
                            $results['skipped'][] = [
                                UserPatient::PATIENT_ID => $patientId,
                                'patient_name' => $patient->getFullName(),
                                'reason' => 'already_inactive'
                            ];
                            continue;
                        }

                        // Deactivate the assignment (soft delete approach)
                        $existingAssignment->status = Status::INACTIVE;

                        if (!$existingAssignment->save()) {
                            $messages = $existingAssignment->getMessages();
                            $msg = "An unknown error occurred.";

                            if (count($messages) > 0) {
                                $obj = $messages[0];
                                $msg = $obj->getMessage();
                            }

                            $results['failed'][] = [
                                UserPatient::PATIENT_ID => $patientId,
                                'patient_name' => $patient->getFullName(),
                                'error' => 'Failed to deactivate assignment: ' . $msg
                            ];
                            continue;
                        }

                        $results['success'][] = [
                            UserPatient::PATIENT_ID => $patientId,
                            'patient_name' => $patient->getFullName(),
                            'action' => 'unassigned'
                        ];

                    } catch (Exception $e) {
                        $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
                        error_log('Exception: ' . $message);
                        $results['failed'][] = [
                            UserPatient::PATIENT_ID => $patient->id,
                            'patient_name' => $patient->getFullName(),
                            'error' => $e->getMessage()
                        ];
                        continue;
                    }
                }

                // Determine response based on results
                $totalRequested = count($validPatients);
                $totalSuccess = count($results['success']);
                $totalFailed = count($results['failed']);
                $totalSkipped = count($results['skipped']);

                // If no assignments were removed, but some were skipped
                if ($totalSuccess === 0 && $totalSkipped > 0 && $totalFailed === 0) {
                    return $this->respondWithSuccess([
                        'message' => "No patients were unassigned - all {$totalSkipped} were either not assigned or already inactive",
                        UserPatient::USER_ID => $userId,
                        'results' => $results
                    ], 200, "No patients needed to be unassigned");
                }

                // If no unassignments were successful at all
                if ($totalSuccess === 0) {
                    return $this->respondWithError([
                        'message' => 'No patient unassignments were completed',
                        UserPatient::USER_ID => $userId,
                        'results' => $results
                    ], 422);
                }

                // Success response with details
                $message = "{$totalSuccess} patient(s) unassigned successfully";
                if ($totalSkipped > 0) {
                    $message .= ", {$totalSkipped} were not assigned";
                }
                if ($totalFailed > 0) {
                    $message .= ", {$totalFailed} failed";
                }

                return $this->respondWithSuccess([
                    'message' => $message,
                    UserPatient::USER_ID => $userId,
                    'total_requested' => $totalRequested,
                    'total_success' => $totalSuccess,
                    'total_failed' => $totalFailed,
                    'total_skipped' => $totalSkipped,
                    'results' => $results
                ], 201, $message);
            });

        } catch (Exception $e) {
            $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
            error_log('Exception: ' . $message);
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Deactivate a user-patient assignment
     *
     * @param int $userId User ID
     * @param int $patientId Patient ID
     * @return array Response data
     */
    public function deactivate(int $userId, int $patientId): array {
        try {
            // Verify manager role or higher
            if (!$this->isManagerOrHigher())
                return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 403);

            // Find the assignment
            $assignment = UserPatient::findAssignment($userId, $patientId);

            if (!$assignment)
                return $this->respondWithError('Assignment not found', 404);

            if ($assignment->status === Status::INACTIVE)
                return $this->respondWithError('Assignment is already inactive', 400);

            // Deactivate assignment within transaction
            return $this->withTransaction(function() use ($assignment) {
                $assignment->status = Status::INACTIVE;

                if (!$assignment->save()) {
                    $messages = $assignment->getMessages(); // This is Phalcon\Messages\MessageInterface[]
                    $msg = "An unknown error occurred."; // Default/fallback

                    if (count($messages) > 0) {
                        // Get the first message object from the array
                        $obj = $messages[0]; // or current($phalconMessages)

                        // Extract the string message from the object
                        // The MessageInterface guarantees the getMessage() method.
                        $msg = $obj->getMessage();
                    }

                    // Pass the extracted string message to your responder
                    return $this->respondWithError($msg, 422);
                }
                return $this->respondWithSuccess([
                    'message' => 'Assignment deactivated successfully',
                    'assignment' => $assignment->toArray()
                ], 201, 'Assignment deactivated successfully');
            });

        } catch (Exception $e) {
            $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
            error_log('Exception: ' . $message);
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Get all patients assigned to a user
     * Access restricted to:
     * - The user themselves
     * - Managers and Administrators
     *
     * @param int $userId User ID
     * @return array Response data
     */
    public function getUserAssignedPatients(int $userId): array {
        try {
            // Check if user exists
            $user = User::findFirst($userId);
            if (!$user)
                return $this->respondWithError(Message::USER_NOT_FOUND, 404);

            $userData['fullname'] = $user->getFullName();
            $userData['photo'] = $user->getPhotoUrl();

            // Check authorization:
            // - Allow if the current user is requesting their own patients
            // - Allow if the current user is a manager or administrator
            $currentUserId = $this->getCurrentUserId();
            if ($currentUserId !== $userId && !$this->isManagerOrHigher())
                return $this->respondWithError(Message::UNAUTHORIZED_ACCESS, 403);

            // Get all active patient assignments for this user
            $assignments = UserPatient::findActiveByUserId($userId);

            if ($assignments->count() === 0) {
                return $this->respondWithSuccess([
                    'user' => $userData,
                    'count' => 0,
                    'patients' => []
                ]);
            }

            // Get patient data with assignment details
            $patientsData = [];
            foreach ($assignments as $assignment) {
                // Get the patient
                $patient = Patient::findFirst($assignment->patient_id);
                if (!$patient) {
                    continue; // Skip if patient not found
                }
                $patientInfo = $patient->toArray();
                $patientsData[] = $patientInfo;
            }

            return $this->respondWithSuccess([
                'user' => $userData,
                'count' => count($patientsData),
                'patients' => $patientsData
            ]);

        } catch (Exception $e) {
            $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
            error_log('Exception: ' . $message);
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Get all active patients NOT assigned to a user
     * Access restricted to:
     * - The user themselves
     * - Managers and Administrators
     *
     * @param int $userId User ID
     * @return array Response data
     */
    public function getUserUnassignedPatients(int $userId): array {
        try {
            // Check if user exists
            $user = User::findFirst($userId);
            if (!$user)
                return $this->respondWithError(Message::USER_NOT_FOUND, 404);

            $userData['fullname'] = $user->getFullName();
            $userData['photo'] = $user->getPhotoUrl();

            // Check authorization:
            // - Allow if the current user is requesting their own unassigned patients
            // - Allow if the current user is a manager or administrator
            $currentUserId = $this->getCurrentUserId();
            if ($currentUserId !== $userId && !$this->isManagerOrHigher())
                return $this->respondWithError(Message::UNAUTHORIZED_ACCESS, 403);

            // Get all patient IDs that are assigned to this user
            $assignedPatientIds = [];
            $assignments = UserPatient::findActiveByUserId($userId);
            foreach ($assignments as $assignment) {
                $assignedPatientIds[] = $assignment->patient_id;
            }

            // Get all active patients
            $allActivePatients = Patient::findActive();

            if ($allActivePatients->count() === 0) {
                return $this->respondWithSuccess([
                    'user' => $userData,
                    'count' => 0,
                    'patients' => []
                ]);
            }

            // Filter out patients that are assigned to this user
            $unassignedPatientsData = [];
            foreach ($allActivePatients as $patient) {
                // Skip if this patient is assigned to the user
                if (in_array($patient->id, $assignedPatientIds)) {
                    continue;
                }

                $patientInfo = $patient->toArray();
                $unassignedPatientsData[] = $patientInfo;
            }

            return $this->respondWithSuccess([
                'user' => $userData,
                'count' => count($unassignedPatientsData),
                'patients' => $unassignedPatientsData
            ]);

        } catch (Exception $e) {
            $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
            error_log('Exception: ' . $message);
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Get all patients assigned to a user with patient addresses
     * Access restricted to:
     * - The user themselves
     * - Managers and Administrators
     *
     * @param int $userId User ID
     * @return array Response data
     */
    public function getUserAssignedPatientsWithAddresses(int $userId): array {
        try {
            // Check if user exists
            $user = User::findFirst($userId);
            if (!$user)
                return $this->respondWithError(Message::USER_NOT_FOUND, 404);

            // Check authorization:
            // - Allow if the current user is requesting their own patients
            // - Allow if the current user is a manager or administrator
            $currentUserId = $this->getCurrentUserId();
            if ($currentUserId !== $userId && !$this->isManagerOrHigher())
                return $this->respondWithError(Message::UNAUTHORIZED_ACCESS, 403);

            // Get all active patient assignments for this user
            $assignments = UserPatient::findActiveByUserId($userId);

            if ($assignments->count() === 0) {
                return $this->respondWithSuccess([
                    'user' => $user,
                    'count' => 0,
                    'patients' => []
                ]);
            }

            // Get patient data with assignment details
            $patientsData = [];
            foreach ($assignments as $assignment) {
                // Get the patient
                $patient = Patient::findFirst($assignment->patient_id);
                if (!$patient) {
                    continue; // Skip if patient not found
                }

                $patientInfo = $patient->toArray();

                // Get patient's addresses
                $addresses = Address::findByPerson($patient->id, PersonType::PATIENT);
                if ($addresses && $addresses->count() > 0) {
                    $patientInfo['addresses'] = $addresses->toArray();
                } else {
                    $patientInfo['addresses'] = [];
                }

                // Add assignment details
                $patientInfo['assignment'] = [
                    'assigned_at' => $assignment->assigned_at,
                    'assigned_by' => $assignment->assigned_by,
                    'notes' => $assignment->notes,
                    'status' => $assignment->status
                ];

                $patientsData[] = $patientInfo;
            }

            return $this->respondWithSuccess([
                'user' => $user,
                'count' => count($patientsData),
                'patients' => $patientsData
            ]);

        } catch (Exception $e) {
            $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
            error_log('Exception: ' . $message);
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Get all active patients NOT assigned to a user with addresses
     * Access restricted to:
     * - The user themselves
     * - Managers and Administrators
     *
     * @param int $userId User ID
     * @return array Response data
     */
    public function getUserUnassignedPatientsWithAddresses(int $userId): array {
        try {
            // Check if user exists
            $user = User::findFirst($userId);
            if (!$user)
                return $this->respondWithError(Message::USER_NOT_FOUND, 404);

            // Check authorization:
            // - Allow if the current user is requesting their own unassigned patients
            // - Allow if the current user is a manager or administrator
            $currentUserId = $this->getCurrentUserId();
            if ($currentUserId !== $userId && !$this->isManagerOrHigher())
                return $this->respondWithError(Message::UNAUTHORIZED_ACCESS, 403);

            // Get all patient IDs that are assigned to this user
            $assignedPatientIds = [];
            $assignments = UserPatient::findActiveByUserId($userId);
            foreach ($assignments as $assignment) {
                $assignedPatientIds[] = $assignment->patient_id;
            }

            // Get all active patients
            $allActivePatients = Patient::findActive();

            if ($allActivePatients->count() === 0) {
                return $this->respondWithSuccess([
                    'user' => $user,
                    'count' => 0,
                    'patients' => []
                ]);
            }

            // Filter out patients that are assigned to this user
            $unassignedPatientsData = [];
            foreach ($allActivePatients as $patient) {
                // Skip if this patient is assigned to the user
                if (in_array($patient->id, $assignedPatientIds)) {
                    continue;
                }

                $patientInfo = $patient->toArray();

                // Get patient's addresses
                $addresses = Address::findByPerson($patient->id, PersonType::PATIENT);
                if ($addresses && $addresses->count() > 0) {
                    $patientInfo['addresses'] = $addresses->toArray();
                } else {
                    $patientInfo['addresses'] = [];
                }

                // Add assignment details (null since not assigned to this user)
                $patientInfo['assignment'] = null;

                $unassignedPatientsData[] = $patientInfo;
            }

            return $this->respondWithSuccess([
                'user' => $user,
                'count' => count($unassignedPatientsData),
                'patients' => $unassignedPatientsData
            ]);

        } catch (Exception $e) {
            $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
            error_log('Exception: ' . $message);
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Get all users assigned to a patient
     * Access restricted to:
     * - Managers and Administrators only
     *
     * @param int $patientId Patient ID
     * @return array Response data
     */
    public function getPatientAssignedUsers(int $patientId): array {
        try {
            // Verify manager role or higher (only managers/admins can see patient assignments)
            if (!$this->isManagerOrHigher())
                return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 403);

            // Check if patient exists
            $patient = Patient::findFirst($patientId);
            if (!$patient)
                return $this->respondWithError(Message::PATIENT_NOT_FOUND, 404);

            // Get all active user assignments for this patient
            $assignments = UserPatient::findActiveByPatientId($patientId);

            if ($assignments->count() === 0) {
                return $this->respondWithSuccess([
                    'patient' => $patient,
                    'count' => 0,
                    'users' => []
                ]);
            }

            // Get user data with assignment details
            $usersData = [];
            foreach ($assignments as $assignment) {
                // Get the user
                $user = User::findFirst($assignment->user_id);
                if (!$user) {
                    continue; // Skip if user not found
                }

                $userInfo = $user->toArray();

                // Get user's addresses
                $addresses = Address::findByPerson($user->id, PersonType::USER);
                if ($addresses && $addresses->count() > 0) {
                    $userInfo['addresses'] = $addresses->toArray();
                } else {
                    $userInfo['addresses'] = [];
                }

                // Get user's auth information (role, status)
                $auth = Auth::findFirst($user->id);
                if ($auth) {
                    $userInfo['role'] = $auth->role;
                    $userInfo['role_name'] = $auth->getRoleName();
                    $userInfo['status'] = $auth->status;
                    $userInfo['status_name'] = $auth->getStatusName();
                }

                // Add assignment details
                $userInfo['assignment'] = [
                    'assigned_at' => $assignment->assigned_at,
                    'assigned_by' => $assignment->assigned_by,
                    'notes' => $assignment->notes,
                    'status' => $assignment->status
                ];

                $usersData[] = $userInfo;
            }

            return $this->respondWithSuccess([
                'patient' => $patient,
                'count' => count($usersData),
                'users' => $usersData
            ]);

        } catch (Exception $e) {
            $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
            error_log('Exception: ' . $message);
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

}