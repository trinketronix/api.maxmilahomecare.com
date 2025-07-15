<?php

declare(strict_types=1);

namespace Api\Controllers;

use Api\Constants\PersonType;
use Api\Constants\Progress;
use Api\Constants\Status;
use Api\Models\Address;
use Api\Models\OrderedVisit;
use Api\Models\Patient;
use Api\Models\User;
use Api\Models\UserPatient;
use Api\Models\Visit;
use Api\Constants\Message;
use DateTime;
use Exception;

class VisitController extends BaseController {
    /**
     * Schedule a new visit
     * Required: user_id, patient_id, address_id, visit_date, total_hours
     * Optional: start_time, note
     */
    public function schedule(): array {
        try {
            $currentUserId = $this->getCurrentUserId();
            $data = $this->getRequestBody();

            // Validate required fields
            $requiredFields = [
                Visit::USER_ID => 'User ID is required',
                Visit::PATIENT_ID => 'Patient ID is required',
                Visit::ADDRESS_ID => 'Address ID is required',
                Visit::VISIT_DATE => 'Visit date is required',
                Visit::TOTAL_HOURS => 'Total hours is required'
            ];

            foreach ($requiredFields as $field => $message) {
                if (empty($data[$field])) {
                    return $this->respondWithError($message, 400);
                }
            }

            $userId = (int)$data[Visit::USER_ID];

            // Authorization: can only schedule for self or as manager/admin
            if ($userId !== $currentUserId && !$this->isManagerOrHigher()) {
                return $this->respondWithError('You can only schedule visits for yourself', 403);
            }

            // Validate user exists
            $user = User::findFirstById($userId);
            if (!$user) {
                return $this->respondWithError(Message::USER_NOT_FOUND, 404);
            }

            // Validate patient exists and is active
            $patientId = (int)$data[Visit::PATIENT_ID];
            $patient = Patient::findFirstById($patientId);
            if (!$patient) {
                return $this->respondWithError(Message::PATIENT_NOT_FOUND, 404);
            }
            if (!$patient->isActive()) {
                return $this->respondWithError('Cannot schedule visit for inactive patient', 400);
            }

            // Validate address belongs to patient
            $addressId = (int)$data[Visit::ADDRESS_ID];
            $address = $this->validatePatientAddress($addressId, $patientId);
            if (!$address) {
                return $this->respondWithError('Invalid address for this patient', 400);
            }

            // Validate user is assigned to patient (optional business rule)
            if (!$this->isUserAssignedToPatient($userId, $patientId) && !$this->isManagerOrHigher()) {
                return $this->respondWithError('User is not assigned to this patient', 403);
            }

            // Validate total hours
            $totalHours = (int)$data[Visit::TOTAL_HOURS];
            if ($totalHours < 1 || $totalHours > 24) {
                return $this->respondWithError('Total hours must be between 1 and 24', 400);
            }

            // Create visit within transaction
            return $this->withTransaction(function() use ($data, $userId, $patientId, $addressId, $totalHours, $currentUserId) {
                $visit = new Visit();

                // Set required fields
                $visit->user_id = $userId;
                $visit->patient_id = $patientId;
                $visit->address_id = $addressId;
                $visit->visit_date = $data[Visit::VISIT_DATE];
                $visit->total_hours = $totalHours;

                // Set defaults
                $visit->scheduled_by = $userId; // Always the user who will perform the visit
                $visit->progress = Progress::SCHEDULED;
                $visit->status = Status::ACTIVE;

                // Set optional fields
                if (!empty($data[Visit::START_TIME])) {
                    $visit->start_time = $data[Visit::START_TIME];
                    // end_time will be calculated automatically in beforeCreate
                }

                if (!empty($data[Visit::NOTE])) {
                    $visit->note = $data[Visit::NOTE];
                }

                if (!$visit->save()) {
                    return $this->respondWithError($this->getFirstErrorMessage($visit), 422);
                }

                return $this->respondWithSuccess([
                    'message' => 'Visit scheduled successfully',
                    'visit' => $this->formatVisitData($visit)
                ], 201, 'Visit scheduled successfully');
            });

        } catch (Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Get all visits (Manager/Admin only)
     */
    public function getAllVisits(): array {
        try {
            // Authorization: Manager/Admin only
            if (!$this->isManagerOrHigher()) {
                return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 403);
            }

            // Use OrderedVisit model for pre-sorted results
            $visits = OrderedVisit::find();

            $visitsData = [];
            foreach ($visits as $visit) {
                $visitsData[] = $this->formatVisitData($visit);
            }

            return $this->respondWithSuccess([
                'count' => count($visitsData),
                'visits' => $visitsData
            ]);

        } catch (Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Get visits for a specific user
     */
    public function getUserVisits(int $userId): array {
        try {
            $currentUserId = $this->getCurrentUserId();

            // Authorization: can only view own visits or as manager/admin
            if ($userId !== $currentUserId && !$this->isManagerOrHigher()) {
                return $this->respondWithError(Message::UNAUTHORIZED_ACCESS, 403);
            }

            // Use OrderedVisit model for pre-sorted results
            $visits = OrderedVisit::find([
                'conditions' => 'user_id = :user_id: AND status = :status:',
                'bind' => [
                    'user_id' => $userId,
                    'status' => Status::ACTIVE
                ],
                'bindTypes' => [
                    'user_id' => \PDO::PARAM_INT,
                    'status' => \PDO::PARAM_INT
                ]
            ]);

            $visitsData = [];
            foreach ($visits as $visit) {
                $visitsData[] = $this->formatVisitData($visit);
            }

            return $this->respondWithSuccess([
                'count' => count($visitsData),
                'visits' => $visitsData
            ]);

        } catch (Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Get visits for a specific patient (Manager/Admin only)
     */
    public function getPatientVisits(int $patientId): array {
        try {
            // Authorization: Manager/Admin only
            if (!$this->isManagerOrHigher()) {
                return $this->respondWithError('Only managers and administrators can view patient visits', 403);
            }

            // Validate patient exists
            $patient = Patient::findFirstById($patientId);
            if (!$patient) {
                return $this->respondWithError(Message::PATIENT_NOT_FOUND, 404);
            }

            // Use OrderedVisit model for pre-sorted results
            $visits = OrderedVisit::find([
                'conditions' => 'patient_id = :patient_id: AND status = :status:',
                'bind' => [
                    'patient_id' => $patientId,
                    'status' => Status::ACTIVE
                ],
                'bindTypes' => [
                    'patient_id' => \PDO::PARAM_INT,
                    'status' => \PDO::PARAM_INT
                ]
            ]);

            $visitsData = [];
            foreach ($visits as $visit) {
                $visitsData[] = $this->formatVisitData($visit);
            }

            // Include patient info in response
            $patientData = $patient->toArray();

            return $this->respondWithSuccess([
                'patient' => $patientData,
                'count' => count($visitsData),
                'visits' => $visitsData
            ]);

        } catch (Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Get a specific visit by ID
     */
    public function getVisitById(int $visitId): array {
        try {
            $currentUserId = $this->getCurrentUserId();

            $visit = Visit::findFirstById($visitId);
            if (!$visit) {
                return $this->respondWithError('Visit not found', 404);
            }

            // Authorization: can only view own visits or as manager/admin
            if ($visit->user_id !== $currentUserId && !$this->isManagerOrHigher()) {
                return $this->respondWithError(Message::UNAUTHORIZED_ACCESS, 403);
            }

            return $this->respondWithSuccess($this->formatVisitData($visit));

        } catch (Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Check in to a visit
     */
    public function checkin(int $visitId): array {
        try {
            $currentUserId = $this->getCurrentUserId();

            $visit = Visit::findFirstById($visitId);
            if (!$visit) {
                return $this->respondWithError('Visit not found', 404);
            }

            // Authorization: owner or manager/admin
            if ($visit->user_id !== $currentUserId && !$this->isManagerOrHigher()) {
                return $this->respondWithError('You can only check in to your own visits', 403);
            }

            // Validate visit can be checked in
            if (!$visit->canCheckIn()) {
                return $this->respondWithError('Visit cannot be checked in. Current status: ' . $visit->getProgressDescription(), 400);
            }

            return $this->withTransaction(function() use ($visit) {
                $visit->progress = Progress::IN_PROGRESS;
                $visit->checkin_by = $visit->user_id; // Always the assigned user, not current user

                if (!$visit->save()) {
                    return $this->respondWithError($this->getFirstErrorMessage($visit), 422);
                }

                return $this->respondWithSuccess([
                    'message' => 'Successfully checked in',
                    'visit' => $this->formatVisitData($visit)
                ], 200, 'Successfully checked in');
            });

        } catch (Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Check out from a visit
     */
    public function checkout(int $visitId): array {
        try {
            $currentUserId = $this->getCurrentUserId();

            $visit = Visit::findFirstById($visitId);
            if (!$visit) {
                return $this->respondWithError('Visit not found', 404);
            }

            // Authorization: owner or manager/admin
            if ($visit->user_id !== $currentUserId && !$this->isManagerOrHigher()) {
                return $this->respondWithError('You can only check out from your own visits', 403);
            }

            // Validate visit can be checked out
            if (!$visit->canCheckOut()) {
                return $this->respondWithError('Visit cannot be checked out. Current status: ' . $visit->getProgressDescription(), 400);
            }

            return $this->withTransaction(function() use ($visit) {
                $visit->progress = Progress::COMPLETED;
                $visit->checkout_by = $visit->user_id; // Always the assigned user, not current user

                if (!$visit->save()) {
                    return $this->respondWithError($this->getFirstErrorMessage($visit), 422);
                }

                return $this->respondWithSuccess([
                    'message' => 'Successfully checked out',
                    'visit' => $this->formatVisitData($visit)
                ], 200, 'Successfully checked out');
            });

        } catch (Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Approve a visit (Manager/Admin only)
     */
    public function approve(int $visitId): array {
        try {
            // Authorization: Manager/Admin only
            if (!$this->isManagerOrHigher()) {
                return $this->respondWithError('Only managers and administrators can approve visits', 403);
            }

            $currentUserId = $this->getCurrentUserId();

            $visit = Visit::findFirstById($visitId);
            if (!$visit) {
                return $this->respondWithError('Visit not found', 404);
            }

            // Validate visit can be approved
            if (!$visit->canApprove()) {
                return $this->respondWithError('Visit cannot be approved. Current status: ' . $visit->getProgressDescription(), 400);
            }

            return $this->withTransaction(function() use ($visit, $currentUserId) {
                $visit->progress = Progress::PAID;
                $visit->approved_by = $currentUserId; // The manager/admin who approved

                if (!$visit->save()) {
                    return $this->respondWithError($this->getFirstErrorMessage($visit), 422);
                }

                return $this->respondWithSuccess([
                    'message' => 'Visit approved successfully',
                    'visit' => $this->formatVisitData($visit)
                ], 200, 'Visit approved successfully');
            });

        } catch (Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Cancel a visit
     */
    public function cancel(int $visitId): array {
        try {
            $currentUserId = $this->getCurrentUserId();
            $data = $this->getRequestBody();

            // Validate required note
            if (empty($data[Visit::NOTE])) {
                return $this->respondWithError('Cancellation reason (note) is required', 400);
            }

            $visit = Visit::findFirstById($visitId);
            if (!$visit) {
                return $this->respondWithError('Visit not found', 404);
            }

            // Authorization: owner or manager/admin
            if ($visit->user_id !== $currentUserId && !$this->isManagerOrHigher()) {
                return $this->respondWithError('You can only cancel your own visits', 403);
            }

            // Validate visit can be canceled
            if (!$visit->canCancel()) {
                return $this->respondWithError('Visit cannot be canceled. Current status: ' . $visit->getProgressDescription(), 400);
            }

            return $this->withTransaction(function() use ($visit, $currentUserId, $data) {
                $visit->progress = Progress::CANCELED;
                $visit->canceled_by = $currentUserId; // The user who canceled (self or manager/admin)
                $visit->note = $data[Visit::NOTE]; // Required cancellation reason

                if (!$visit->save()) {
                    return $this->respondWithError($this->getFirstErrorMessage($visit), 422);
                }

                return $this->respondWithSuccess([
                    'message' => 'Visit canceled successfully',
                    'visit' => $this->formatVisitData($visit)
                ], 200, 'Visit canceled successfully');
            });

        } catch (Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Change visit status to Visible/Active (Manager/Admin only)
     */
    public function changeStatusVisible(int $visitId): array {
        return $this->changeVisitStatus($visitId, Status::ACTIVE, 'visible');
    }

    /**
     * Change visit status to Archived (Manager/Admin only)
     */
    public function changeStatusArchived(int $visitId): array {
        return $this->changeVisitStatus($visitId, Status::ARCHIVED, 'archived');
    }

    /**
     * Change visit status to Soft Deleted (Manager/Admin only)
     */
    public function changeStatusSoftDeleted(int $visitId): array {
        return $this->changeVisitStatus($visitId, Status::SOFT_DELETED, 'soft deleted');
    }

    /**
     * Helper method to change visit status
     */
    private function changeVisitStatus(int $visitId, int $newStatus, string $statusName): array {
        try {
            // Authorization: Manager/Admin only
            if (!$this->isManagerOrHigher()) {
                return $this->respondWithError('Only managers and administrators can change visit status', 403);
            }

            $visit = Visit::findFirstById($visitId);
            if (!$visit) {
                return $this->respondWithError('Visit not found', 404);
            }

            // Don't change if already in the desired status
            if ($visit->status === $newStatus) {
                return $this->respondWithError("Visit is already {$statusName}", 400);
            }

            return $this->withTransaction(function() use ($visit, $newStatus, $statusName) {
                $visit->status = $newStatus;

                if (!$visit->save()) {
                    return $this->respondWithError($this->getFirstErrorMessage($visit), 422);
                }

                return $this->respondWithSuccess([
                    'message' => "Visit status changed to {$statusName}",
                    'visit' => $this->formatVisitData($visit)
                ], 200, "Visit status changed to {$statusName}");
            });

        } catch (Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Helper method to validate patient address
     */
    private function validatePatientAddress(int $addressId, int $patientId): ?Address {
        return Address::findFirst([
            'conditions' => 'id = :address_id: AND person_id = :patient_id: AND person_type = :person_type:',
            'bind' => [
                'address_id' => $addressId,
                'patient_id' => $patientId,
                'person_type' => PersonType::PATIENT
            ],
            'bindTypes' => [
                'address_id' => \PDO::PARAM_INT,
                'patient_id' => \PDO::PARAM_INT,
                'person_type' => \PDO::PARAM_INT
            ]
        ]);
    }

    /**
     * Helper method to check if user is assigned to patient
     */
    private function isUserAssignedToPatient(int $userId, int $patientId): bool {
        return UserPatient::isUserAssignedToPatient($userId, $patientId);
    }

    /**
     * Helper method to format visit data for response
     */
    private function formatVisitData(Visit $visit): array {
        $data = $visit->toArray();

        // Add calculated fields
        $data['duration_minutes'] = $visit->getDurationMinutes();
        $data['progress_description'] = $visit->getProgressDescription();

        // Add related data
        $data['user'] = $visit->getUserData();
        $data['patient'] = $visit->getPatientData();
        $data['address'] = $visit->getAddressData();

        // Add status descriptions
        $data['is_today'] = $visit->visit_date === date('Y-m-d');
        $data['is_future'] = $visit->visit_date > date('Y-m-d');
        $data['is_past'] = $visit->visit_date < date('Y-m-d');

        return $data;
    }

    /**
     * Helper method to get first error message from model
     */
    private function getFirstErrorMessage($model): string {
        $messages = $model->getMessages();
        if (count($messages) > 0) {
            return $messages[0]->getMessage();
        }
        return 'An unknown error occurred';
    }

    /**
     * Helper method to handle exceptions
     */
    private function handleException(Exception $e): array {
        $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
        error_log('VisitController Exception: ' . $message);
        return $this->respondWithError('An error occurred: ' . $e->getMessage(), 500);
    }
}