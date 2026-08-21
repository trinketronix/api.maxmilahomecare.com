<?php

declare(strict_types=1);

namespace Api\Controllers;

use Api\Constants\PersonType;
use Api\Constants\Progress;
use Api\Constants\Status;
use Api\Models\Address;
use Api\Models\OrderedVisits;
use Api\Models\Patient;
use Api\Models\User;
use Api\Models\UserPatient;
use Api\Models\Visit;
use Api\Constants\Message;
use Api\Services\InvalidQueryException;
use DateTime;
use Exception;

class VisitController extends BaseController {
    /**
     * Schedule a new visit
     * Required: user_id, patient_id, address_id, visit_date, total_hours
     * Optional: start_time, note, extra_minutes
     */
    public function schedule(): array {
        try {
            $currentUserId = $this->getCurrentUserId();
            $data = $this->getRequestBody();

            // Validate required fields
            $requiredFields = [
                Visit::USER_ID => 'User ID is required',
                Visit::PATIENT_ID => 'Patient ID is required',
                Visit::VISIT_DATE => 'Visit date is required'
            ];

            foreach ($requiredFields as $field => $message) {
                if (empty($data[$field])) {
                    return $this->respondWithError($message, 400);
                }
            }
            // Fields where 0 IS a valid value — check for "actually absent" instead of falsy.
            // address_id: 0 = Community.
            // total_hours: 0 = minutes-only visit (e.g., 15-min med pass).
            $presenceRequired = [
                Visit::ADDRESS_ID   => 'Address ID is required',
                Visit::TOTAL_HOURS  => 'Total hours is required',
            ];
            foreach ($presenceRequired as $field => $message) {
                if (!isset($data[$field]) || $data[$field] === '' || $data[$field] === null) {
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

            // Validate address belongs to patient (skip for Community address)
            $addressId = (int)$data[Visit::ADDRESS_ID];
            if ($addressId !== 0) {
                $address = $this->validatePatientAddress($addressId, $patientId);
                if (!$address) {
                    return $this->respondWithError('Invalid address for this patient', 400);
                }
            }

            // Validate user is assigned to patient (optional business rule)
            if (!$this->isUserAssignedToPatient($userId, $patientId) && !$this->isManagerOrHigher()) {
                return $this->respondWithError('User is not assigned to this patient', 403);
            }

            // Validate total hours
            $totalHours = (int)$data[Visit::TOTAL_HOURS];
            if ($totalHours < 0 || $totalHours > 24) {
                return $this->respondWithError('Total hours must be between 0 and 24', 400);
            }

            // Validate extra minutes (optional, defaults to 0)
            $extraMinutes = isset($data[Visit::EXTRA_MINUTES]) ? (int)$data[Visit::EXTRA_MINUTES] : 0;
            if ($totalHours === 0 && $extraMinutes === 0) {
                return $this->respondWithError('Visit duration must be greater than zero', 400);
            }
            if (!in_array($extraMinutes, [0, 15, 30, 45], true)) {
                return $this->respondWithError('Extra minutes must be 0, 15, 30, or 45', 400);
            }

            // Duplicate guard: reject if identical visit was created in the last 60 seconds
            $duplicateConditions = 'user_id = :uid: AND patient_id = :pid: AND address_id = :aid: AND visit_date = :vdate: AND total_hours = :th: AND extra_minutes = :em: AND created_at >= :since:';
            $duplicateBind = [
                'uid'   => $userId,
                'pid'   => $patientId,
                'aid'   => $addressId,
                'vdate' => $data[Visit::VISIT_DATE],
                'th'    => $totalHours,
                'em'    => $extraMinutes,
                'since' => date('Y-m-d H:i:s', time() - 60),
            ];
            $existing = Visit::findFirst([
                'conditions' => $duplicateConditions,
                'bind'       => $duplicateBind,
            ]);
            if ($existing) {
                // Return the already-created visit instead of making a duplicate
                return $this->respondWithSuccess([
                    'message' => 'Visit scheduled successfully',
                    'visit'   => $this->formatVisitData($existing)
                ], 201, 'Visit scheduled successfully');
            }

            // Create visit within transaction
            return $this->withTransaction(function() use ($data, $userId, $patientId, $addressId, $totalHours, $extraMinutes) {
                $visit = new Visit();

                // Set required fields
                $visit->user_id = $userId;
                $visit->patient_id = $patientId;
                $visit->address_id = $addressId;
                $visit->visit_date = $data[Visit::VISIT_DATE];
                $visit->total_hours = $totalHours;
                $visit->extra_minutes = $extraMinutes;

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
     * All visits (managers/admins).
     * Query: page, per_page, user_id, patient_id, progress (-1..3), status (1|2|3, default: any),
     *        start_date, end_date (YYYY-MM-DD, inclusive, on visit_date).
     */
    public function getAllVisits(): array {
        if (!$this->isManagerOrHigher()) {
            return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 403);
        }
        return $this->listVisits([], null, ['user_id', 'patient_id']);
    }

    /**
     * Visits of one caregiver (self, or managers/admins).
     * Query: page, per_page, patient_id, progress, status (default 1 = active), start_date, end_date.
     */
    public function getUserVisits(int $userId): array {
        $currentUserId = $this->getCurrentUserId();

        // Authorization: can only view own visits or as manager/admin
        if ($userId !== $currentUserId && !$this->isManagerOrHigher()) {
            return $this->respondWithError(Message::UNAUTHORIZED_ACCESS, 403);
        }

        return $this->listVisits(['user_id' => $userId], Status::ACTIVE, ['patient_id']);
    }

    /**
     * Visits of one patient (managers/admins).
     * Query: page, per_page, user_id, progress, status (default 1 = active), start_date, end_date.
     */
    public function getPatientVisits(int $patientId): array {
        if (!$this->isManagerOrHigher()) {
            return $this->respondWithError('Only managers and administrators can view patient visits', 403);
        }

        $patient = Patient::findFirstById($patientId);
        if (!$patient) {
            return $this->respondWithError(Message::PATIENT_NOT_FOUND, 404);
        }

        return $this->listVisits(['patient_id' => $patientId], Status::ACTIVE, ['user_id'], ['patient' => $patient->toArray()]);
    }

    /**
     * Shared implementation of the visit list endpoints.
     *
     * @param array      $fixed         Column => value constraints imposed by the route (user_id / patient_id)
     * @param int|null   $defaultStatus Status applied when the caller does not pass ?status=
     * @param string[]   $idFilters     Which of user_id / patient_id may be given as query filters
     * @param array      $extra         Extra keys prepended to the payload (e.g. the patient)
     */
    private function listVisits(array $fixed, ?int $defaultStatus, array $idFilters, array $extra = []): array {
        try {
            $paging = $this->getPaging();

            $conditions = []; $bind = []; $bindTypes = [];
            $add = static function (string $column, string $op, int|string $value, int $type) use (&$conditions, &$bind, &$bindTypes): void {
                $conditions[] = "$column $op :$column:";
                $bind[$column] = $value;
                $bindTypes[$column] = $type;
            };

            foreach ($fixed as $column => $value) {
                $add($column, '=', $value, \PDO::PARAM_INT);
            }
            foreach ($idFilters as $column) {
                $value = $this->queryInt($column, null, 1);
                if ($value !== null) {
                    $add($column, '=', $value, \PDO::PARAM_INT);
                }
            }

            $progress = $this->queryInt('progress', [Progress::CANCELED, Progress::SCHEDULED, Progress::IN_PROGRESS, Progress::COMPLETED, Progress::PAID]);
            if ($progress !== null) {
                $add('progress', '=', $progress, \PDO::PARAM_INT);
            }

            $status = $this->queryInt('status', [Status::ACTIVE, Status::ARCHIVED, Status::SOFT_DELETED]) ?? $defaultStatus;
            if ($status !== null) {
                $add('status', '=', $status, \PDO::PARAM_INT);
            }

            $startDate = $this->queryDate('start_date');
            $endDate = $this->queryDate('end_date');
            if ($startDate !== null && $endDate !== null && $startDate > $endDate) {
                throw new InvalidQueryException('start_date must not be after end_date');
            }
            if ($startDate !== null) {
                $conditions[] = 'visit_date >= :start_date:'; $bind['start_date'] = $startDate; $bindTypes['start_date'] = \PDO::PARAM_STR;
            }
            if ($endDate !== null) {
                $conditions[] = 'visit_date <= :end_date:'; $bind['end_date'] = $endDate; $bindTypes['end_date'] = \PDO::PARAM_STR;
            }

            $where = $conditions ? ['conditions' => implode(' AND ', $conditions), 'bind' => $bind, 'bindTypes' => $bindTypes] : [];
            $total = (int)OrderedVisits::count($where);

            // Explicit ORDER BY: the view's own ordering is not guaranteed to survive LIMIT/OFFSET
            $visits = OrderedVisits::find($this->applyPaging($where + ['order' => 'sort_order, visit_date DESC, start_time DESC'], $paging));

            return $this->respondWithList('visits', $this->formatVisits($visits), $paging, $total, $extra);

        } catch (InvalidQueryException $e) {
            return $this->respondWithError($e->getMessage(), 400);
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
     * Update a visit's schedule details (PUT /visit/{id})
     * Owner: only while the visit is still scheduled. Manager/Admin: any active visit that is
     * not approved or canceled.
     * Updatable: visit_date, start_time, total_hours, extra_minutes, note, address_id
     */
    public function updateVisit(int $visitId): array {
        try {
            $currentUserId = $this->getCurrentUserId();
            $data = $this->getRequestBody();

            $visit = Visit::findFirstById($visitId);
            if (!$visit) {
                return $this->respondWithError('Visit not found', 404);
            }

            if ($visit->user_id !== $currentUserId && !$this->isManagerOrHigher()) {
                return $this->respondWithError('You can only update your own visits', 403);
            }
            if (!$visit->isVisible()) {
                return $this->respondWithError('Only active visits can be updated', 400);
            }
            if ($visit->isApproved() || $visit->isCanceled()) {
                return $this->respondWithError('Approved or canceled visits cannot be updated. Current status: ' . $visit->getProgressDescription(), 400);
            }
            if (!$visit->isScheduled() && !$this->isManagerOrHigher()) {
                return $this->respondWithError('Visits can only be edited before check-in', 400);
            }

            $updates = [];

            if (array_key_exists(Visit::VISIT_DATE, $data)) {
                if (empty($data[Visit::VISIT_DATE])) {
                    return $this->respondWithError('Visit date cannot be empty', 400);
                }
                $updates[Visit::VISIT_DATE] = (string)$data[Visit::VISIT_DATE];
            }

            if (array_key_exists(Visit::START_TIME, $data)) {
                $updates[Visit::START_TIME] = !empty($data[Visit::START_TIME]) ? (string)$data[Visit::START_TIME] : null;
            }

            $totalHours = $visit->total_hours;
            $extraMinutes = $visit->extra_minutes;

            if (isset($data[Visit::TOTAL_HOURS]) && $data[Visit::TOTAL_HOURS] !== '') {
                $totalHours = (int)$data[Visit::TOTAL_HOURS];
                if ($totalHours < 0 || $totalHours > 24) {
                    return $this->respondWithError('Total hours must be between 0 and 24', 400);
                }
                $updates[Visit::TOTAL_HOURS] = $totalHours;
            }

            if (isset($data[Visit::EXTRA_MINUTES]) && $data[Visit::EXTRA_MINUTES] !== '') {
                $extraMinutes = (int)$data[Visit::EXTRA_MINUTES];
                if (!in_array($extraMinutes, [0, 15, 30, 45], true)) {
                    return $this->respondWithError('Extra minutes must be 0, 15, 30, or 45', 400);
                }
                $updates[Visit::EXTRA_MINUTES] = $extraMinutes;
            }

            if ($totalHours === 0 && $extraMinutes === 0) {
                return $this->respondWithError('Visit duration must be greater than zero', 400);
            }

            if (array_key_exists(Visit::NOTE, $data)) {
                $updates[Visit::NOTE] = !empty($data[Visit::NOTE]) ? (string)$data[Visit::NOTE] : null;
            }

            if (isset($data[Visit::ADDRESS_ID]) && $data[Visit::ADDRESS_ID] !== '') {
                $addressId = (int)$data[Visit::ADDRESS_ID];
                // 0 = Community (no fixed address); anything else must belong to the visit's patient
                if ($addressId !== 0 && !$this->validatePatientAddress($addressId, $visit->patient_id)) {
                    return $this->respondWithError('Invalid address for this patient', 400);
                }
                $updates[Visit::ADDRESS_ID] = $addressId;
            }

            if (empty($updates)) {
                return $this->respondWithError('No updatable fields provided', 400);
            }

            return $this->withTransaction(function() use ($visit, $updates) {
                foreach ($updates as $field => $value) {
                    $visit->$field = $value;
                }

                // end_time always follows start_time + duration
                if (empty($visit->start_time)) {
                    $visit->end_time = null;
                } else {
                    $visit->calculateEndTime();
                }

                if (!$visit->save()) {
                    return $this->respondWithError($this->getFirstErrorMessage($visit), 422);
                }

                return $this->respondWithSuccess([
                    'message' => 'Visit updated successfully',
                    'visit' => $this->formatVisitData($visit)
                ], 200, 'Visit updated successfully');
            });

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
     * Format many visits with three batched lookups (users, patients, addresses) instead of three per visit.
     */
    private function formatVisits(iterable $visits): array {
        $list = is_array($visits) ? $visits : iterator_to_array($visits, false);
        if (!$list) {
            return [];
        }

        $ids = static fn (string $field) => array_values(array_unique(array_map(static fn (Visit $v) => (int)$v->$field, $list)));
        $byId = static function (iterable $rows): array {
            $map = [];
            foreach ($rows as $row) {
                $map[(int)$row->id] = $row->toArray();
            }
            return $map;
        };

        $maps = [
            'user'    => $byId(User::find(['conditions' => 'id IN ({ids:array})', 'bind' => ['ids' => $ids('user_id')]])),
            'patient' => $byId(Patient::find(['conditions' => 'id IN ({ids:array})', 'bind' => ['ids' => $ids('patient_id')]])),
            'address' => $byId(Address::find(['conditions' => 'id IN ({ids:array})', 'bind' => ['ids' => $ids('address_id')]])),
        ];

        return array_map(fn (Visit $v) => $this->formatVisitData($v, $maps), $list);
    }

    /**
     * Helper method to format visit data for response.
     * $maps (from formatVisits) avoids per-visit lookups; without it the model getters are used.
     */
    private function formatVisitData(Visit $visit, ?array $maps = null): array {
        $data = $visit->toArray();

        // Add calculated fields
        $data['duration_minutes'] = $visit->getDurationMinutes();
        $data['progress_description'] = $visit->getProgressDescription();

        // Add related data
        if ($maps !== null) {
            $data['user'] = $maps['user'][(int)$visit->user_id] ?? [];
            $data['patient'] = $maps['patient'][(int)$visit->patient_id] ?? [];
            $data['address'] = $maps['address'][(int)$visit->address_id] ?? [];
        } else {
            $data['user'] = $visit->getUserData();
            $data['patient'] = $visit->getPatientData();
            $data['address'] = $visit->getAddressData();
        }

        // Add status descriptions
        $today = date('Y-m-d');
        $data['is_today'] = $visit->visit_date === $today;
        $data['is_future'] = $visit->visit_date > $today;
        $data['is_past'] = $visit->visit_date < $today;

        return $data;
    }

}
