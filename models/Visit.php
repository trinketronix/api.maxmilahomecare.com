<?php

declare(strict_types=1);

namespace Api\Models;

use Api\Constants\Message;
use Api\Constants\Progress;
use Api\Constants\Status;
use DateTime;
use Phalcon\Filter\Validation;
use Phalcon\Filter\Validation\Validator\InclusionIn;
use Phalcon\Filter\Validation\Validator\PresenceOf;
use Phalcon\Mvc\Model;

class Visit extends Model {
    // Column constants
    public const string ID = 'id';
    public const string USER_ID = 'user_id';
    public const string PATIENT_ID = 'patient_id';
    public const string ADDRESS_ID = 'address_id';
    public const string START_TIME = 'start_time';
    public const string END_TIME = 'end_time';
    public const string NOTE = 'note';
    public const string PROGRESS = 'progress';
    public const string SCHEDULED_BY = 'scheduled_by';
    public const string CHECKIN_BY = 'checkin_by';
    public const string CHECKOUT_BY = 'checkout_by';
    public const string CANCELED_BY = 'canceled_by';
    public const string APPROVED_BY = 'approved_by';
    public const string STATUS = 'status';
    public const string CREATED_AT = 'created_at';
    public const string UPDATED_AT = 'updated_at';

    // Primary identification
    public ?int $id = null;

    // Related foreign ids
    public int $user_id;
    public int $patient_id;

    // Visit information
    public string $start_time;
    public string $end_time;
    public ?string $note = null;

    // Visit status information
    public int $progress = Progress::SCHEDULED;

    // Progress tracking fields
    public ?int $scheduled_by = null;
    public ?int $checkin_by = null;
    public ?int $checkout_by = null;
    public ?int $canceled_by = null;
    public ?int $approved_by = null;

    // Record status
    public int $status = Status::ACTIVE;

    // Timestamps
    public ?string $created_at = null;
    public ?string $updated_at = null;

    /**
     * Initialize model relationships and behaviors
     */
    public function initialize(): void {
        $this->setSource('visit');
    }

    /**
     * Model validation
     */
    public function validation(): bool {
        $validator = new Validation();

        // Required fields validation
        $requiredFields = [
            self::USER_ID => 'User ID is required',
            self::PATIENT_ID => 'Patient ID is required',
            self::START_TIME => 'Start time is required',
            self::END_TIME => 'End time is required'
        ];

        foreach ($requiredFields as $field => $message) {
            $validator->add(
                $field,
                new PresenceOf([
                    'message' => $message
                ])
            );
        }

        // Progress validation
        $validator->add(
            self::PROGRESS,
            new InclusionIn([
                'domain' => [
                    Progress::CANCELED,
                    Progress::SCHEDULED,
                    Progress::IN_PROGRESS,
                    Progress::COMPLETED,
                    Progress::PAID
                ],
                'message' => 'Invalid progress value'
            ])
        );

        // Status validation
        $validator->add(
            self::STATUS,
            new InclusionIn([
                'domain' => [
                    Status::ACTIVE,
                    Status::ARCHIVED,
                    Status::SOFT_DELETED
                ],
                'message' => Message::STATUS_INVALID
            ])
        );

        // Time validation: end_time should be after start_time
        if (!empty($this->start_time) && !empty($this->end_time)) {
            $startTime = new DateTime($this->start_time);
            $endTime = new DateTime($this->end_time);

            if ($endTime < $startTime) {
                $this->appendMessage(
                    new \Phalcon\Messages\Message(
                        'End time cannot be before start time',
                        self::END_TIME
                    )
                );
                return false;
            }
        }

        // Validate progress state consistency with tracking fields
        if ($this->progress === Progress::CANCELED && !$this->canceled_by) {
            $this->appendMessage(
                new \Phalcon\Messages\Message(
                    'Canceled visits must have canceled_by field set',
                    self::CANCELED_BY
                )
            );
            return false;
        }

        if ($this->progress === Progress::IN_PROGRESS && !$this->checkin_by) {
            $this->appendMessage(
                new \Phalcon\Messages\Message(
                    'In-progress visits must have checkin_by field set',
                    self::CHECKIN_BY
                )
            );
            return false;
        }

        if ($this->progress === Progress::COMPLETED && !$this->checkout_by) {
            $this->appendMessage(
                new \Phalcon\Messages\Message(
                    'Completed visits must have checkout_by field set',
                    self::CHECKOUT_BY
                )
            );
            return false;
        }

        if ($this->progress === Progress::PAID && !$this->approved_by) {
            $this->appendMessage(
                new \Phalcon\Messages\Message(
                    'Paid visits must have approved_by field set',
                    self::APPROVED_BY
                )
            );
            return false;
        }

        return $this->validate($validator);
    }

    /**
     * Actions before create (in addition to Timestampable behavior)
     */
    public function beforeCreate() {
        // Ensure progress and tracking fields are consistent
        $this->ensureProgressConsistency();
        return true;
    }

    /**
     * Actions before update (in addition to Timestampable behavior)
     */
    public function beforeUpdate() {
        // Ensure progress and tracking fields are consistent
        $this->ensureProgressConsistency();
        return true;
    }

    /**
     * Ensure consistency between progress state and tracking fields
     */
    private function ensureProgressConsistency(): void {
        // All visits should have a scheduler
        if (is_null($this->scheduled_by) && $this->progress !== Progress::CANCELED) {
            // If we don't know who scheduled it but it exists, use the assigned user
            $this->scheduled_by = $this->user_id;
        }

        // Make sure checkin_by is set for in-progress, completed, or paid visits
        if (($this->progress >= Progress::IN_PROGRESS) && is_null($this->checkin_by)) {
            $this->checkin_by = $this->user_id;
        }

        // Make sure checkout_by is set for completed or paid visits
        if (($this->progress >= Progress::COMPLETED) && is_null($this->checkout_by)) {
            $this->checkout_by = $this->user_id;
        }
    }

    /**
     * Format datetime for display
     */
    private function formatDateTime(string $datetime, string $format = 'Y-m-d H:i:s'): string {
        $date = new DateTime($datetime);
        return $date->format($format);
    }

    /**
     * Get user
     */
    public function getUser(): Array {
        return User::findFirst($this->user_id);
    }

    /**
     * Get patient
     */
    public function getPatient(): Array {
        return Patient::findFirst($this->patient_id);
    }

    /**
     * Get address
     */
    public function getAddress(): Array {
        return Address::findFirst($this->address_id);
    }

    /**
     * Get formatted start time
     */
    public function getFormattedStartTime(string $format = 'Y-m-d h:i A'): string {
        return $this->formatDateTime($this->start_time, $format);
    }

    /**
     * Get formatted end time
     */
    public function getFormattedEndTime(string $format = 'Y-m-d h:i A'): string {
        return $this->formatDateTime($this->end_time, $format);
    }

    /**
     * Get visit duration in minutes
     */
    public function getDurationMinutes(): int {
        $startTime = new DateTime($this->start_time);
        $endTime = new DateTime($this->end_time);

        $interval = $startTime->diff($endTime);
        return ($interval->days * 24 * 60) + ($interval->h * 60) + $interval->i;
    }

    /**
     * Get formatted visit duration (e.g., "2 hours 30 minutes")
     */
    public function getFormattedDuration(): string {
        $minutes = $this->getDurationMinutes();

        $hours = floor($minutes / 60);
        $remainingMinutes = $minutes % 60;

        $result = '';

        if ($hours > 0) {
            $result .= $hours . ' hour' . ($hours > 1 ? 's' : '');
        }

        if ($remainingMinutes > 0) {
            if ($result) {
                $result .= ' ';
            }
            $result .= $remainingMinutes . ' minute' . ($remainingMinutes > 1 ? 's' : '');
        }

        return $result ?: '0 minutes';
    }

    /**
     * Get progress description
     */
    public function getProgressDescription(): string {
        $descriptions = [
            Progress::CANCELED => 'Canceled',
            Progress::SCHEDULED => 'Scheduled',
            Progress::IN_PROGRESS => 'In Progress',
            Progress::COMPLETED => 'Completed',
            Progress::PAID => 'Paid'
        ];

        return $descriptions[$this->progress] ?? 'Unknown';
    }

    /**
     * Check if visit is canceled
     */
    public function isCanceled(): bool {
        return $this->progress === Progress::CANCELED;
    }

    /**
     * Check if visit is scheduled
     */
    public function isScheduled(): bool {
        return $this->progress === Progress::SCHEDULED;
    }

    /**
     * Check if visit is in progress
     */
    public function isInProgress(): bool {
        return $this->progress === Progress::IN_PROGRESS;
    }

    /**
     * Check if visit is completed
     */
    public function isCompleted(): bool {
        return $this->progress === Progress::COMPLETED;
    }

    /**
     * Check if visit is paid
     */
    public function isPaid(): bool {
        return $this->progress === Progress::PAID;
    }

    /**
     * Check if visit is active
     */
    public function isActive(): bool {
        return $this->status === Status::ACTIVE;
    }

    /**
     * Get scheduler user
     */
    public function getScheduler(): ?User {
        return $this->scheduledByUser ?? null;
    }

    /**
     * Get check-in user
     */
    public function getCheckinUser(): ?User {
        return $this->checkinByUser ?? null;
    }

    /**
     * Get check-out user
     */
    public function getCheckoutUser(): ?User {
        return $this->checkoutByUser ?? null;
    }

    /**
     * Get cancellation user
     */
    public function getCanceledByUser(): ?User {
        return $this->canceledByUser ?? null;
    }

    /**
     * Get approval user
     */
    public function getApprovedByUser(): ?User {
        return $this->approvedByUser ?? null;
    }

    /**
     * Find visits for a specific user (caregiver)
     */
    public static function findByUser(int $userId, array $params = []): \Phalcon\Mvc\Model\ResultsetInterface {
        $conditions = ['user_id = :user_id:'];
        $bind = ['user_id' => $userId];
        $bindTypes = ['user_id' => \PDO::PARAM_INT];

        // Add optional progress filter
        if (isset($params['progress'])) {
            $conditions[] = 'progress = :progress:';
            $bind['progress'] = $params['progress'];
            $bindTypes['progress'] = \PDO::PARAM_INT;
        }

        // Add optional date range filter
        if (isset($params['start_date']) && isset($params['end_date'])) {
            $conditions[] = 'start_time >= :start_date: AND start_time <= :end_date:';
            $bind['start_date'] = $params['start_date'] . ' 00:00:00';
            $bind['end_date'] = $params['end_date'] . ' 23:59:59';
            $bindTypes['start_date'] = \PDO::PARAM_STR;
            $bindTypes['end_date'] = \PDO::PARAM_STR;
        }

        // Add status filter, default to active
        if (isset($params['status'])) {
            $conditions[] = 'status = :status:';
            $bind['status'] = $params['status'];
        } else {
            $conditions[] = 'status = :status:';
            $bind['status'] = Status::ACTIVE;
        }
        $bindTypes['status'] = \PDO::PARAM_INT;

        return self::find([
            'conditions' => implode(' AND ', $conditions),
            'bind' => $bind,
            'bindTypes' => $bindTypes,
            'order' => 'start_time ' . ($params['order'] ?? 'ASC')
        ]);
    }

    /**
     * Find visits by scheduler
     */
    public static function findByScheduler(int $schedulerId, array $params = []): \Phalcon\Mvc\Model\ResultsetInterface {
        $conditions = ['scheduled_by = :scheduler_id:'];
        $bind = ['scheduler_id' => $schedulerId];
        $bindTypes = ['scheduler_id' => \PDO::PARAM_INT];

        // Add status filter, default to active
        if (isset($params['status'])) {
            $conditions[] = 'status = :status:';
            $bind['status'] = $params['status'];
        } else {
            $conditions[] = 'status = :status:';
            $bind['status'] = Status::ACTIVE;
        }
        $bindTypes['status'] = \PDO::PARAM_INT;

        return self::find([
            'conditions' => implode(' AND ', $conditions),
            'bind' => $bind,
            'bindTypes' => $bindTypes,
            'order' => 'start_time ' . ($params['order'] ?? 'ASC')
        ]);
    }

    /**
     * Find visits for a specific patient
     */
    public static function findByPatient(int $patientId, array $params = []): \Phalcon\Mvc\Model\ResultsetInterface {
        $conditions = ['patient_id = :patient_id:'];
        $bind = ['patient_id' => $patientId];
        $bindTypes = ['patient_id' => \PDO::PARAM_INT];

        // Add optional progress filter
        if (isset($params['progress'])) {
            $conditions[] = 'progress = :progress:';
            $bind['progress'] = $params['progress'];
            $bindTypes['progress'] = \PDO::PARAM_INT;
        }

        // Add optional date range filter
        if (isset($params['start_date']) && isset($params['end_date'])) {
            $conditions[] = 'start_time >= :start_date: AND start_time <= :end_date:';
            $bind['start_date'] = $params['start_date'] . ' 00:00:00';
            $bind['end_date'] = $params['end_date'] . ' 23:59:59';
            $bindTypes['start_date'] = \PDO::PARAM_STR;
            $bindTypes['end_date'] = \PDO::PARAM_STR;
        }

        // Add status filter, default to active
        if (isset($params['status'])) {
            $conditions[] = 'status = :status:';
            $bind['status'] = $params['status'];
        } else {
            $conditions[] = 'status = :status:';
            $bind['status'] = Status::ACTIVE;
        }
        $bindTypes['status'] = \PDO::PARAM_INT;

        return self::find([
            'conditions' => implode(' AND ', $conditions),
            'bind' => $bind,
            'bindTypes' => $bindTypes,
            'order' => 'start_time ' . ($params['order'] ?? 'ASC')
        ]);
    }

    /**
     * Find upcoming visits for today
     */
    public static function findTodaysVisits(int $userId = null): \Phalcon\Mvc\Model\ResultsetInterface {
        $today = date('Y-m-d');
        $conditions = ['DATE(start_time) = :today: AND progress = :progress: AND status = :status:'];
        $bind = [
            'today' => $today,
            'progress' => Progress::SCHEDULED,
            'status' => Status::ACTIVE
        ];
        $bindTypes = [
            'today' => \PDO::PARAM_STR,
            'progress' => \PDO::PARAM_INT,
            'status' => \PDO::PARAM_INT
        ];

        if ($userId !== null) {
            $conditions[] = 'user_id = :user_id:';
            $bind['user_id'] = $userId;
            $bindTypes['user_id'] = \PDO::PARAM_INT;
        }

        return self::find([
            'conditions' => implode(' AND ', $conditions),
            'bind' => $bind,
            'bindTypes' => $bindTypes,
            'order' => 'start_time ASC'
        ]);
    }

    /**
     * Find visits by date range
     */
    public static function findByDateRange(string $startDate, string $endDate, array $params = []): \Phalcon\Mvc\Model\ResultsetInterface {
        $conditions = ['start_time >= :start_date: AND start_time <= :end_date:'];
        $bind = [
            'start_date' => $startDate . ' 00:00:00',
            'end_date' => $endDate . ' 23:59:59'
        ];
        $bindTypes = [
            'start_date' => \PDO::PARAM_STR,
            'end_date' => \PDO::PARAM_STR
        ];

        // Add optional user filter
        if (isset($params['user_id'])) {
            $conditions[] = 'user_id = :user_id:';
            $bind['user_id'] = $params['user_id'];
            $bindTypes['user_id'] = \PDO::PARAM_INT;
        }

        // Add optional patient filter
        if (isset($params['patient_id'])) {
            $conditions[] = 'patient_id = :patient_id:';
            $bind['patient_id'] = $params['patient_id'];
            $bindTypes['patient_id'] = \PDO::PARAM_INT;
        }

        // Add optional progress filter
        if (isset($params['progress'])) {
            $conditions[] = 'progress = :progress:';
            $bind['progress'] = $params['progress'];
            $bindTypes['progress'] = \PDO::PARAM_INT;
        }

        // Add status filter, default to active
        if (isset($params['status'])) {
            $conditions[] = 'status = :status:';
            $bind['status'] = $params['status'];
        } else {
            $conditions[] = 'status = :status:';
            $bind['status'] = Status::ACTIVE;
        }
        $bindTypes['status'] = \PDO::PARAM_INT;

        return self::find([
            'conditions' => implode(' AND ', $conditions),
            'bind' => $bind,
            'bindTypes' => $bindTypes,
            'order' => 'start_time ' . ($params['order'] ?? 'ASC')
        ]);
    }
}