<?php

declare(strict_types=1);

namespace Api\Controllers;

use Api\Constants\Progress;
use Api\Constants\Status;
use Exception;
use DateTime;
use Api\Models\Visit;
use Api\Models\User;
use Api\Models\Patient;
use Api\Constants\Message;

class VisitController extends BaseController {
    /**
     * Create a new visit
     */
    public function create(): array {
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
            $currentTime = new DateTime();

            if ($endTime <= $startTime) {
                return $this->respondWithError('End time cannot be before start time', 400);
            }

            // Create visit within transaction
            return $this->withTransaction(function() use ($data, $startTime, $endTime, $currentTime, $currentUserId) {
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

                // Calculate time difference in minutes
                $minutesBeforeStart = ($startTime->getTimestamp() - $currentTime->getTimestamp()) / 60;
                $minutesAfterStart = ($currentTime->getTimestamp() - $startTime->getTimestamp()) / 60;

                // Determine progress status based on creation time relative to start time
                if ($minutesBeforeStart > 15) {
                    // Created more than 15 minutes before start time -> Scheduled
                    $visit->progress = Progress::SCHEDULED;
                    $visit->scheduled_by = $currentUserId;
                } else if ($minutesBeforeStart <= 15 && $minutesAfterStart <= 15) {
                    // Created within 15 minutes of start time (before or after) -> Check-in
                    $visit->progress = Progress::IN_PROGRESS;
                    $visit->scheduled_by = $currentUserId;
                    $visit->checkin_by = $currentUserId;
                } else {
                    // Created more than 15 minutes after start time -> Completed
                    $visit->progress = Progress::COMPLETED;
                    $visit->scheduled_by = $currentUserId;
                    $visit->checkin_by = $currentUserId;
                    $visit->checkout_by = $currentUserId;
                }

                // Override progress if explicitly provided (for admins/managers only)
                if (isset($data[Visit::PROGRESS]) && $this->isManagerOrHigher()) {
                    $progress = (int)$data[Visit::PROGRESS];
                    if (!in_array($progress, [
                        Progress::CANCELED,
                        Progress::SCHEDULED,
                        Progress::IN_PROGRESS,
                        Progress::COMPLETED,
                        Progress::PAID
                    ])) {
                        return $this->respondWithError(Message::STATUS_INVALID, 400);
                    }
                    $visit->progress = $progress;

                    // Set corresponding tracking fields based on provided progress
                    if ($progress === Progress::CANCELED) {
                        $visit->canceled_by = $currentUserId;
                    } else if ($progress === Progress::PAID) {
                        $visit->approved_by = $currentUserId;
                    }
                }

                // Always create with active status
                $visit->status = Status::ACTIVE;

                if (!$visit->save()) {
                    return $this->respondWithError($visit->getMessages(), 422);
                }

                return $this->respondWithSuccess([
                    'message' => 'Visit created successfully',
                    'visit_id' => $visit->id,
                    'progress' => $visit->progress,
                    'duration_minutes' => $visit->getDurationMinutes()
                ], 201);
            });

        } catch (Exception $e) {
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Get a specific visit by ID
     */
    public function getById(int $id): array {
        try {
            // Get current user ID
            $currentUserId = $this->getCurrentUserId();

            // Find visit
            $visit = Visit::findFirst($id);
            if (!$visit) {
                return $this->respondWithError('Visit not found', 404);
            }

            // Authorization check: can only view own visits or as manager/admin
            if ($visit->user_id !== $currentUserId && !$this->isManagerOrHigher()) {
                return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 403);
            }

            // Get related data
            $visitData = $visit->toArray();
            $visitData['user'] = $visit->user ? $visit->user->toArray() : null;
            $visitData['patient'] = $visit->patient ? $visit->patient->toArray() : null;
            $visitData['duration_minutes'] = $visit->getDurationMinutes();
            $visitData['progress_description'] = $visit->getProgressDescription();

            return $this->respondWithSuccess($visitData);

        } catch (Exception $e) {
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Get visits with optional filtering
     */
    public function getVisits(): array {
        try {
            // Get query parameters for filtering
            $userId = $this->request->getQuery('user_id', 'int', null);
            $patientId = $this->request->getQuery('patient_id', 'int', null);
            $progress = $this->request->getQuery('progress', 'int', null);
            $status = $this->request->getQuery('status', 'int', Status::ACTIVE);
            $startDate = $this->request->getQuery('start_date', 'string', null);
            $endDate = $this->request->getQuery('end_date', 'string', null);
            $page = $this->request->getQuery('page', 'int', 1);
            $pageSize = $this->request->getQuery('pageSize', 'int', 50);

            // Build query parameters
            $params = [
                'status' => $status,
                'order' => 'DESC'
            ];

            if ($progress !== null) {
                $params['progress'] = $progress;
            }

            if ($startDate && $endDate) {
                $params['start_date'] = $startDate;
                $params['end_date'] = $endDate;
            }

            // Get visits based on parameters
            if ($userId) {
                // Check authorization for accessing user-specific visits
                $currentUserId = $this->getCurrentUserId();
                if ($userId !== $currentUserId && !$this->isManagerOrHigher()) {
                    return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 403);
                }
                $visits = Visit::findByUser($userId, $params);
            } elseif ($patientId) {
                // Managers and above can access patient visits
                if (!$this->isManagerOrHigher()) {
                    return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 403);
                }
                $visits = Visit::findByPatient($patientId, $params);
            } else {
                // General visit query - managers and above only
                if (!$this->isManagerOrHigher()) {
                    return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 403);
                }
                $visits = Visit::find([
                    'conditions' => 'status = :status:',
                    'bind' => ['status' => $status],
                    'order' => 'start_time DESC'
                ]);
            }

            // Convert to array and add additional data
            $visitArray = [];
            foreach ($visits as $visit) {
                $visitData = $visit->toArray();
                $visitData['duration_minutes'] = $visit->getDurationMinutes();
                $visitData['progress_description'] = $visit->getProgressDescription();
                $visitArray[] = $visitData;
            }

            return $this->respondWithSuccess([
                'data' => $visitArray,
                'count' => count($visitArray)
            ]);

        } catch (Exception $e) {
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Get visits for a specific user
     */
    public function getUserVisits(int $userId): array {
        try {
            // Check authorization
            $currentUserId = $this->getCurrentUserId();
            if ($userId !== $currentUserId && !$this->isManagerOrHigher()) {
                return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 403);
            }

            // Get query parameters
            $progress = $this->request->getQuery('progress', 'int', null);
            $status = $this->request->getQuery('status', 'int', Status::ACTIVE);
            $startDate = $this->request->getQuery('start_date', 'string', null);
            $endDate = $this->request->getQuery('end_date', 'string', null);

            // Build parameters
            $params = [
                'status' => $status,
                'order' => 'DESC'
            ];

            if ($progress !== null) {
                $params['progress'] = $progress;
            }

            if ($startDate && $endDate) {
                $params['start_date'] = $startDate;
                $params['end_date'] = $endDate;
            }

            // Get visits
            $visits = Visit::findByUser($userId, $params);

            // Convert to array and add additional data
            $visitArray = [];
            foreach ($visits as $visit) {
                $visitData = $visit->toArray();
                $visitData['duration_minutes'] = $visit->getDurationMinutes();
                $visitData['progress_description'] = $visit->getProgressDescription();
                $visitData['patient'] = $visit->patient ? $visit->patient->toArray() : null;
                $visitArray[] = $visitData;
            }

            return $this->respondWithSuccess($visitArray);

        } catch (Exception $e) {
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Get visits for a specific patient
     */
    public function getPatientVisits(int $patientId): array {
        try {
            // Verify manager role or higher
            if (!$this->isManagerOrHigher()) {
                return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 403);
            }

            // Get query parameters
            $progress = $this->request->getQuery('progress', 'int', null);
            $status = $this->request->getQuery('status', 'int', Status::ACTIVE);
            $startDate = $this->request->getQuery('start_date', 'string', null);
            $endDate = $this->request->getQuery('end_date', 'string', null);

            // Build parameters
            $params = [
                'status' => $status,
                'order' => 'DESC'
            ];

            if ($progress !== null) {
                $params['progress'] = $progress;
            }

            if ($startDate && $endDate) {
                $params['start_date'] = $startDate;
                $params['end_date'] = $endDate;
            }

            // Get visits
            $visits = Visit::findByPatient($patientId, $params);

            // Convert to array and add additional data
            $visitArray = [];
            foreach ($visits as $visit) {
                $visitData = $visit->toArray();
                $visitData['duration_minutes'] = $visit->getDurationMinutes();
                $visitData['progress_description'] = $visit->getProgressDescription();
                $visitData['user'] = $visit->user ? $visit->user->toArray() : null;
                $visitArray[] = $visitData;
            }

            return $this->respondWithSuccess($visitArray);

        } catch (Exception $e) {
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Get today's visits
     */
    public function getTodaysVisits(): array {
        try {
            // Check if user has permission to view today's visits
            $currentUserId = $this->getCurrentUserId();
            $userId = null;

            // If not manager or higher, only show own visits
            if (!$this->isManagerOrHigher()) {
                $userId = $currentUserId;
            }

            // Get today's visits
            $visits = Visit::findTodaysVisits($userId);

            // Convert to array and add additional data
            $visitArray = [];
            foreach ($visits as $visit) {
                $visitData = $visit->toArray();
                $visitData['duration_minutes'] = $visit->getDurationMinutes();
                $visitData['progress_description'] = $visit->getProgressDescription();
                $visitData['user'] = $visit->user ? $visit->user->toArray() : null;
                $visitData['patient'] = $visit->patient ? $visit->patient->toArray() : null;
                $visitArray[] = $visitData;
            }

            return $this->respondWithSuccess($visitArray);

        } catch (Exception $e) {
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Update an existing visit
     */
    public function update(int $id): array {
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
            return $this->withTransaction(function() use ($visit, $data, $currentUserId) {
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

                // Track if progress is being updated
                $oldProgress = $visit->progress;
                $progressUpdated = false;

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
                                    Progress::CANCELED,
                                    Progress::SCHEDULED,
                                    Progress::IN_PROGRESS,
                                    Progress::COMPLETED,
                                    Progress::PAID
                                ])) {
                                    return $this->respondWithError(Message::STATUS_INVALID, 400);
                                }

                                // Only update progress if it's actually changing
                                if ($progress !== $oldProgress) {
                                    $visit->progress = $progress;
                                    $progressUpdated = true;

                                    // Update the appropriate tracking field based on the new progress
                                    switch ($progress) {
                                        case Progress::CANCELED:
                                            $visit->canceled_by = $currentUserId;
                                            break;
                                        case Progress::SCHEDULED:
                                            // If going back to scheduled, we're basically rescheduling
                                            if (!$visit->scheduled_by) {
                                                $visit->scheduled_by = $currentUserId;
                                            }
                                            break;
                                        case Progress::IN_PROGRESS:
                                            // Set scheduled_by if not set (in case of direct check-in)
                                            if (!$visit->scheduled_by) {
                                                $visit->scheduled_by = $currentUserId;
                                            }
                                            $visit->checkin_by = $currentUserId;
                                            break;
                                        case Progress::COMPLETED:
                                            // Ensure all previous steps are tracked
                                            if (!$visit->scheduled_by) {
                                                $visit->scheduled_by = $currentUserId;
                                            }
                                            if (!$visit->checkin_by) {
                                                $visit->checkin_by = $currentUserId;
                                            }
                                            $visit->checkout_by = $currentUserId;
                                            break;
                                        case Progress::PAID:
                                            // Ensure all previous steps are tracked
                                            if (!$visit->scheduled_by) {
                                                $visit->scheduled_by = $currentUserId;
                                            }
                                            if (!$visit->checkin_by) {
                                                $visit->checkin_by = $currentUserId;
                                            }
                                            if (!$visit->checkout_by) {
                                                $visit->checkout_by = $currentUserId;
                                            }
                                            $visit->approved_by = $currentUserId;
                                            break;
                                    }
                                }
                                break;

                            case Visit::STATUS:
                                $status = (int)$data[$field];
                                if (!in_array($status, [
                                    Status::ACTIVE,
                                    Status::ARCHIVED,
                                    Status::SOFT_DELETED
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

                // Allow direct setting of tracking fields for admins only
                if ($this->isAdmin()) {
                    $trackingFields = [
                        'scheduled_by',
                        'checkin_by',
                        'checkout_by',
                        'canceled_by',
                        'approved_by'
                    ];

                    foreach ($trackingFields as $field) {
                        if (isset($data[$field])) {
                            // Verify the user exists
                            $trackingUserId = (int)$data[$field];
                            if ($trackingUserId > 0) {
                                $trackingUser = User::findFirst($trackingUserId);
                                if (!$trackingUser) {
                                    return $this->respondWithError("User ID $trackingUserId for $field not found", 404);
                                }
                                $visit->$field = $trackingUserId;
                            } else {
                                // Allow setting to null to clear tracking
                                $visit->$field = null;
                            }
                        }
                    }
                }

                if (!$visit->save()) {
                    return $this->respondWithError($visit->getMessages(), 422);
                }

                return $this->respondWithSuccess([
                    'message' => 'Visit updated successfully',
                    'visit_id' => $visit->id,
                    'progress' => $visit->progress,
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
            if ($visit->status === Status::SOFT_DELETED) {
                return $this->respondWithError('Visit is already deleted', 400);
            }

            // Soft delete visit within transaction
            return $this->withTransaction(function() use ($visit) {
                // Set status to deleted
                $visit->status = Status::SOFT_DELETED;

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
                Progress::CANCELED,
                Progress::SCHEDULED,
                Progress::IN_PROGRESS,
                Progress::COMPLETED,
                Progress::PAID
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
    public function checkIn(int $id): array {
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
            if ($visit->progress !== Progress::SCHEDULED) {
                return $this->respondWithError('Can only check in to scheduled visits', 400);
            }

            // Update to in-progress within transaction
            return $this->withTransaction(function() use ($visit) {
                $visit->progress = Progress::IN_PROGRESS;

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
    public function checkOut(int $id): array {
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
            if ($visit->progress !== Progress::IN_PROGRESS) {
                return $this->respondWithError('Can only check out from in-progress visits', 400);
            }

            $data = $this->getRequestBody();

            // Update to completed within transaction
            return $this->withTransaction(function() use ($visit, $data) {
                $visit->progress = Progress::COMPLETED;

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
    public function cancel(int $id): array{
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
            if ($visit->progress === Progress::COMPLETED || $visit->progress === Progress::PAID) {
                return $this->respondWithError('Cannot cancel a completed or paid visit', 400);
            }

            if ($visit->progress === Progress::CANCELED) {
                return $this->respondWithError('Visit is already canceled', 400);
            }

            $data = $this->getRequestBody();

            // Cancel visit within transaction
            return $this->withTransaction(function() use ($visit, $data) {
                $visit->progress = Progress::CANCELED;

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