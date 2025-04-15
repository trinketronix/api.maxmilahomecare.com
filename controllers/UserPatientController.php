<?php

declare(strict_types=1);

namespace Api\Controllers;

use Api\Constants\Status;
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
    public function create(): array {
        try {
            // Verify manager role or higher
            if (!$this->isManagerOrHigher()) {
                return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 403);
            }

            $data = $this->getRequestBody();

            // Validate required fields
            $requiredFields = [
                UserPatient::USER_ID => 'User ID is required',
                UserPatient::PATIENT_ID => 'Patient ID is required'
            ];

            foreach ($requiredFields as $field => $message) {
                if (empty($data[$field])) {
                    return $this->respondWithError($message, 400);
                }
            }

            $userId = (int)$data[UserPatient::USER_ID];
            $patientId = (int)$data[UserPatient::PATIENT_ID];

            // Check if user exists
            $user = User::findFirst($userId);
            if (!$user) {
                return $this->respondWithError(Message::USER_NOT_FOUND, 404);
            }

            // Check if patient exists
            $patient = Patient::findFirst($patientId);
            if (!$patient) {
                return $this->respondWithError(Message::PATIENT_NOT_FOUND, 404);
            }

            // Check if assignment already exists
            $existingAssignment = UserPatient::findAssignment($userId, $patientId);
            if ($existingAssignment) {
                // If it exists but is inactive, reactivate it
                if ($existingAssignment->status === Status::INACTIVE) {
                    return $this->withTransaction(function() use ($existingAssignment) {
                        $existingAssignment->status = Status::ACTIVE;
                        $existingAssignment->assigned_by = $this->getCurrentUserId();
                        $existingAssignment->assigned_at = date('Y-m-d H:i:s');

                        if (isset($data[UserPatient::NOTES])) {
                            $existingAssignment->notes = $data[UserPatient::NOTES];
                        }

                        if (!$existingAssignment->save()) {
                            return $this->respondWithError($existingAssignment->getMessages(), 422);
                        }

                        return $this->respondWithSuccess([
                            'message' => 'User-patient assignment reactivated successfully',
                            'assignment' => $existingAssignment->toArray()
                        ]);
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

                if (isset($data[UserPatient::NOTES])) {
                    $assignment->notes = $data[UserPatient::NOTES];
                }

                if (!$assignment->save()) {
                    return $this->respondWithError($assignment->getMessages(), 422);
                }

                return $this->respondWithSuccess([
                    'message' => 'User-patient assignment created successfully',
                    'assignment' => $assignment->toArray()
                ], 201);
            });

        } catch (Exception $e) {
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
            if (!$this->isManagerOrHigher()) {
                return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 403);
            }

            // Find the assignment
            $assignment = UserPatient::findAssignment($userId, $patientId);

            if (!$assignment) {
                return $this->respondWithError('Assignment not found', 404);
            }

            if ($assignment->status === Status::INACTIVE) {
                return $this->respondWithError('Assignment is already inactive', 400);
            }

            // Deactivate assignment within transaction
            return $this->withTransaction(function() use ($assignment) {
                $assignment->status = Status::INACTIVE;

                if (!$assignment->save()) {
                    return $this->respondWithError($assignment->getMessages(), 422);
                }

                return $this->respondWithSuccess([
                    'message' => 'Assignment deactivated successfully',
                    'assignment' => $assignment->toArray()
                ]);
            });

        } catch (Exception $e) {
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Get all patients assigned to a user
     *
     * @param int $userId User ID
     * @return array Response data
     */
    public function getUserPatients(int $userId): array {
        try {
            // Check if user exists
            $user = User::findFirst($userId);
            if (!$user) {
                return $this->respondWithError(Message::USER_NOT_FOUND, 404);
            }

            // Check authorization (users can only see their own assignments unless manager+)
            $currentUserId = $this->getCurrentUserId();
            if ($currentUserId !== $userId && !$this->isManagerOrHigher()) {
                return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 403);
            }

            // Get all active patients for this user
            $patients = $user->patients;

            if (count($patients) === 0) {
                return $this->respondWithSuccess([
                    'count' => 0,
                    'patients' => []
                ]);
            }

            return $this->respondWithSuccess([
                'count' => count($patients),
                'patients' => $patients->toArray()
            ]);

        } catch (Exception $e) {
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Get all users assigned to a patient
     *
     * @param int $patientId Patient ID
     * @return array Response data
     */
    public function getPatientUsers(int $patientId): array {
        try {
            // Verify manager role or higher
            if (!$this->isManagerOrHigher()) {
                return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 403);
            }

            // Check if patient exists
            $patient = Patient::findFirst($patientId);
            if (!$patient) {
                return $this->respondWithError(Message::PATIENT_NOT_FOUND, 404);
            }

            // Get all active users for this patient
            $users = $patient->users;

            if (count($users) === 0) {
                return $this->respondWithSuccess([
                    'count' => 0,
                    'users' => []
                ]);
            }

            return $this->respondWithSuccess([
                'count' => count($users),
                'users' => $users->toArray()
            ]);

        } catch (Exception $e) {
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }
}