<?php

declare(strict_types=1);

namespace Api\Controllers;

use Exception;
use DateTime;
use Api\Models\Visit;
use Api\Models\User;
use Api\Models\Patient;
use Api\Constants\Message;

class VisitController extends BaseController
{
    /**
     * Create a new visit
     */
    public function create(): array
    {
        try {
            // Get current user ID
            $currentUserId = $this->getCurrentUserId();

            $data = $this->getRequestBody();

            // Validate required fields
            $requiredFields = [
                Visit::USER_ID => 'User ID is required',
                Visit::PATIENT_ID => 'Patient ID is required',
                Visit::START_TIME => 'Start time is required',
                Visit::END_TIME => 'End time is required'
            ];

            foreach ($requiredFields as $field => $message) {
                if (empty($data[$field])) {
                    return $this->respondWithError($message, 400);
                }
            }

            // Verify user exists
            $userId = (int)$data[Visit::USER_ID];
            $user = User::findFirst($userId);
            if (!$user) {
                return $this->respondWithError(Message::USER_NOT_FOUND, 404);
            }

            // Verify patient exists
            $patientId = (int)$data[Visit::PATIENT_ID];
            $patient = Patient::findFirst($patientId);
            if (!$patient) {
                return $this->respondWithError(Message::PATIENT_NOT_FOUND, 404);
            }

            // Verify patient is active
            if (!$patient->isActive()) {
                return $this->respondWithError('Cannot create visit for inactive patient', 400);
            }

            // Authorization check: can only create visits for self or as manager/admin
            if ($userId !== $currentUserId && !$this->isManagerOrHigher()) {
                return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 403);
            }

            // Validate time fields
            $startTime = new DateTime($data[Visit::START_TIME]);
            $endTime = new DateTime($data[Visit::END_TIME]);

            if ($endTime <= $startTime) {
                return $this->respondWithError('End time cannot be before start time', 400);
            }

            // Create visit within transaction
            return $this->withTransaction(function() use ($data, $startTime, $endTime) {
                $visit = new Visit();

                // Set required fields
                $visit->user_id = (int)$data[Visit::USER_ID];
                $visit->patient_id = (int)$data[Visit::PATIENT_ID];
                $visit->start_time = $startTime->format('Y-m-d H:i:s');
                $visit->end_time = $endTime->format('Y-m-d H:i:s');

                // Set optional fields if provided
                if (isset($data[Visit::NOTE])) {
                    $visit->note = $data[Visit::NOTE];
                }

                if (isset($data[Visit::PROGRESS])) {
                    $progress = (int)$data[Visit::PROGRESS];
                    if (!in_array($progress, [
                        Visit::PROGRESS_CANCELED,
                        Visit::PROGRESS_SCHEDULED,
                        Visit::PROGRESS_IN_PROGRESS,
                        Visit::PROGRESS_COMPLETED,
                        Visit::PROGRESS_PAID
                    ])) {
                        return $this->respondWithError(Message::STATUS_INVALID, 400);
                    }
                    $visit->progress = $progress;
                } else {
                    $visit->progress = Visit::PROGRESS_SCHEDULED; // Default status
                }

                // Always create with active status
                $visit->status = Visit::STATUS_ACTIVE;

                if (!$visit->save()) {
                    return $this->respondWithError($visit->getMessages(), 422);
                }

                return $this->respondWithSuccess([
                    'message' => 'Visit created successfully',
                    'visit_id' => $visit->id,
                    'duration_minutes' => $visit->getDurationMinutes()
                ], 201);
            });

        } catch (Exception $e) {
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Update an existing visit
     */
    public function update(int $id): array
    {
        try {
            // Get current user ID
            $currentUserId = $this->getCurrentUserId();

            // Find visit
            $visit = Visit::findFirst($id);
            if (!$visit) {
                return $this->respondWithError('Visit not found', 404);
            }

            // Authorization check: can only update own visits or as manager/admin
            if ($visit->user_id !== $currentUserId && !$this->isManagerOrHigher()) {
                return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 403);
            }

            // Check if visit is deleted
            if (!$visit->isActive()) {
                return $this->respondWithError('Cannot update an inactive visit', 400);
            }

            $data = $this->getRequestBody();

            // Update visit within transaction
            return $this->withTransaction(function() use ($visit, $data) {
                // Update fields if provided
                $updateableFields = [
                    Visit::USER_ID,
                    Visit::PATIENT_ID,
                    Visit::START_TIME,
                    Visit::END_TIME,
                    Visit::NOTE,
                    Visit::PROGRESS,
                    Visit::STATUS
                ];

                // Track if time fields are being updated
                $timeFieldsUpdated = false;
                $startTimeUpdated = false;
                $endTimeUpdated = false;
                $startTime = null;
                $endTime = null;

                foreach ($updateableFields as $field) {
                    if (isset($data[$field])) {
                        switch ($field) {
                            case Visit::USER_ID:
                                $userId = (int)$data[$field];
                                $user = User::findFirst($userId);
                                if (!$user) {
                                    return $this->respondWithError(Message::USER_NOT_FOUND, 404);
                                }
                                $visit->user_id = $userId;
                                break;

                            case Visit::PATIENT_ID:
                                $patientId = (int)$data[$field];
                                $patient = Patient::findFirst($patientId);
                                if (!$patient) {
                                    return $this->respondWithError(Message::PATIENT_NOT_FOUND, 404);
                                }
                                if (!$patient->isActive()) {
                                    return $this->respondWithError('Cannot assign visit to inactive patient', 400);
                                }
                                $visit->patient_id = $patientId;
                                break;

                            case Visit::START_TIME:
                                $startTime = new DateTime($data[$field]);
                                $visit->start_time = $startTime->format('Y-m-d H:i:s');
                                $timeFieldsUpdated = true;
                                $startTimeUpdated = true;
                                break;

                            case Visit::END_TIME:
                                $endTime = new DateTime($data[$field]);
                                $visit->end_time = $endTime->format('Y-m-d H:i:s');
                                $timeFieldsUpdated = true;
                                $endTimeUpdated = true;
                                break;

                            case Visit::PROGRESS:
                                $progress = (int)$data[$field];
                                if (!in_array($progress, [
                                    Visit::PROGRESS_CANCELED,
                                    Visit::PROGRESS_SCHEDULED,
                                    Visit::PROGRESS_IN_PROGRESS,
                                    Visit::PROGRESS_COMPLETED,
                                    Visit::PROGRESS_PAID
                                ])) {
                                    return $this->respondWithError(Message::STATUS_INVALID, 400);
                                }
                                $visit->progress = $progress;
                                break;

                            case Visit::STATUS:
                                $status = (int)$data[$field];
                                if (!in_array($status, [
                                    Visit::STATUS_ACTIVE,
                                    Visit::STATUS_ARCHIVED,
                                    Visit::STATUS_DELETED
                                ])) {
                                    return $this->respondWithError(Message::STATUS_INVALID, 400);
                                }
                                $visit->status = $status;
                                break;

                            default:
                                $visit->$field = $data[$field];
                                break;
                        }
                    }
                }

                // Validate time fields if they were updated
                if ($timeFieldsUpdated) {
                    // If only one time field was updated, get the current value of the other
                    if ($startTimeUpdated && !$endTimeUpdated) {
                        $endTime = new DateTime($visit->end_time);
                    } else if (!$startTimeUpdated && $endTimeUpdated) {
                        $startTime = new DateTime($visit->start_time);
                    }

                    // Validate that end time is after start time
                    if ($endTime <= $startTime) {
                        return $this->respondWithError('End time cannot be before start time', 400);
                    }
                }

                if (!$visit->save()) {
                    return $this->respondWithError($visit->getMessages(), 422);
                }

                return $this->respondWithSuccess([
                    'message' => 'Visit updated successfully',
                    'visit_id' => $visit->id,
                    'duration_minutes' => $visit->getDurationMinutes()
                ]);
            });

        } catch (Exception $e) {
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Soft delete a visit (mark as deleted)
     */
    public function delete(int $id): array
    {
        try {
            // Get current user ID
            $currentUserId = $this->getCurrentUserId();

            // Find visit
            $visit = Visit::findFirst($id);
            if (!$visit) {
                return $this->respondWithError('Visit not found', 404);
            }

            // Authorization check: can only delete own visits or as manager/admin
            if ($visit->user_id !== $currentUserId && !$this->isManagerOrHigher()) {
                return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 403);
            }

            // Check if visit is already deleted
            if ($visit->status === Visit::STATUS_DELETED) {
                return $this->respondWithError('Visit is already deleted', 400);
            }

            // Soft delete visit within transaction
            return $this->withTransaction(function() use ($visit) {
                // Set status to deleted
                $visit->status = Visit::STATUS_DELETED;

                if (!$visit->save()) {
                    return $this->respondWithError($visit->getMessages(), 422);
                }

                return $this->respondWithSuccess([
                    'message' => 'Visit deleted successfully',
                    'visit_id' => $visit->id
                ]);
            });

        } catch (Exception $e) {
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Change visit progress status
     */
    public function updateProgress(int $id): array
    {
        try {
            // Get current user ID
            $currentUserId = $this->getCurrentUserId();

            // Find visit
            $visit = Visit::findFirst($id);
            if (!$visit) {
                return $this->respondWithError('Visit not found', 404);
            }

            // Authorization check: can only update own visits or as manager/admin
            if ($visit->user_id !== $currentUserId && !$this->isManagerOrHigher()) {
                return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 403);
            }

            // Check if visit is active
            if (!$visit->isActive()) {
                return $this->respondWithError('Cannot update progress of an inactive visit', 400);
            }

            $data = $this->getRequestBody();

            if (!isset($data['progress'])) {
                return $this->respondWithError('Progress value is required', 400);
            }

            $progress = (int)$data['progress'];

            // Validate progress value
            if (!in_array($progress, [
                Visit::PROGRESS_CANCELED,
                Visit::PROGRESS_SCHEDULED,
                Visit::PROGRESS_IN_PROGRESS,
                Visit::PROGRESS_COMPLETED,
                Visit::PROGRESS_PAID
            ])) {
                return $this->respondWithError(Message::STATUS_INVALID, 400);
            }

            // Update progress within transaction
            return $this->withTransaction(function() use ($visit, $progress) {
                $oldProgress = $visit->progress;
                $visit->progress = $progress;

                if (!$visit->save()) {
                    return $this->respondWithError($visit->getMessages(), 422);
                }

                return $this->respondWithSuccess([
                    'message' => 'Visit progress updated successfully',
                    'visit_id' => $visit->id,
                    'old_progress' => $oldProgress,
                    'new_progress' => $progress,
                    'progress_description' => $visit->getProgressDescription()
                ]);
            });

        } catch (Exception $e) {
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Check in to a visit (start visit)
     */
    public function checkIn(int $id): array
    {
        try {
            // Get current user ID
            $currentUserId = $this->getCurrentUserId();

            // Find visit
            $visit = Visit::findFirst($id);
            if (!$visit) {
                return $this->respondWithError('Visit not found', 404);
            }

            // Authorization check: can only check in to own visits
            if ($visit->user_id !== $currentUserId) {
                return $this->respondWithError('You can only check in to your own visits', 403);
            }

            // Check if visit is active
            if (!$visit->isActive()) {
                return $this->respondWithError('Cannot check in to an inactive visit', 400);
            }

            // Check if visit is in the right state
            if ($visit->progress !== Visit::PROGRESS_SCHEDULED) {
                return $this->respondWithError('Can only check in to scheduled visits', 400);
            }

            // Update to in-progress within transaction
            return $this->withTransaction(function() use ($visit) {
                $visit->progress = Visit::PROGRESS_IN_PROGRESS;

                if (!$visit->save()) {
                    return $this->respondWithError($visit->getMessages(), 422);
                }

                return $this->respondWithSuccess([
                    'message' => 'Successfully checked in to visit',
                    'visit_id' => $visit->id,
                    'progress' => $visit->getProgressDescription()
                ]);
            });

        } catch (Exception $e) {
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Check out from a visit (complete visit)
     */
    public function checkOut(int $id): array
    {
        try {
            // Get current user ID
            $currentUserId = $this->getCurrentUserId();

            // Find visit
            $visit = Visit::findFirst($id);
            if (!$visit) {
                return $this->respondWithError('Visit not found', 404);
            }

            // Authorization check: can only check out from own visits
            if ($visit->user_id !== $currentUserId) {
                return $this->respondWithError('You can only check out from your own visits', 403);
            }

            // Check if visit is active
            if (!$visit->isActive()) {
                return $this->respondWithError('Cannot check out from an inactive visit', 400);
            }

            // Check if visit is in the right state
            if ($visit->progress !== Visit::PROGRESS_IN_PROGRESS) {
                return $this->respondWithError('Can only check out from in-progress visits', 400);
            }

            $data = $this->getRequestBody();

            // Update to completed within transaction
            return $this->withTransaction(function() use ($visit, $data) {
                $visit->progress = Visit::PROGRESS_COMPLETED;

                // Update note if provided
                if (isset($data['note'])) {
                    $visit->note = $data['note'];
                }

                if (!$visit->save()) {
                    return $this->respondWithError($visit->getMessages(), 422);
                }

                return $this->respondWithSuccess([
                    'message' => 'Successfully checked out from visit',
                    'visit_id' => $visit->id,
                    'progress' => $visit->getProgressDescription(),
                    'duration_minutes' => $visit->getDurationMinutes()
                ]);
            });

        } catch (Exception $e) {
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Cancel a visit
     */
    public function cancel(int $id): array
    {
        try {
            // Get current user ID
            $currentUserId = $this->getCurrentUserId();

            // Find visit
            $visit = Visit::findFirst($id);
            if (!$visit) {
                return $this->respondWithError('Visit not found', 404);
            }

            // Authorization check: can only cancel own visits or as manager/admin
            if ($visit->user_id !== $currentUserId && !$this->isManagerOrHigher()) {
                return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 403);
            }

            // Check if visit is active
            if (!$visit->isActive()) {
                return $this->respondWithError('Cannot cancel an inactive visit', 400);
            }

            // Check if visit can be canceled
            if ($visit->progress === Visit::PROGRESS_COMPLETED || $visit->progress === Visit::PROGRESS_PAID) {
                return $this->respondWithError('Cannot cancel a completed or paid visit', 400);
            }

            if ($visit->progress === Visit::PROGRESS_CANCELED) {
                return $this->respondWithError('Visit is already canceled', 400);
            }

            $data = $this->getRequestBody();

            // Cancel visit within transaction
            return $this->withTransaction(function() use ($visit, $data) {
                $visit->progress = Visit::PROGRESS_CANCELED;

                // Update note if provided
                if (isset($data['note'])) {
                    $visit->note = $data['note'];
                }

                if (!$visit->save()) {
                    return $this->respondWithError($visit->getMessages(), 422);
                }

                return $this->respondWithSuccess([
                    'message' => 'Visit canceled successfully',
                    'visit_id' => $visit->id
                ]);
            });

        } catch (Exception $e) {
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }
}