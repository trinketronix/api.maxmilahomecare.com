<?php

declare(strict_types=1);

namespace Api\Models;

use Api\Constants\Message;
use Phalcon\Filter\Validation;
use Phalcon\Filter\Validation\Validator\PresenceOf;
use Phalcon\Filter\Validation\Validator\InclusionIn;
use Phalcon\Mvc\Model;
use Phalcon\Mvc\Model\Behavior\Timestampable;
use DateTime;

class Visit extends Model
{
    // Progress constants
    public const PROGRESS_CANCELED = -1;
    public const PROGRESS_SCHEDULED = 0;
    public const PROGRESS_IN_PROGRESS = 1;
    public const PROGRESS_COMPLETED = 2;
    public const PROGRESS_PAID = 3;

    // Status constants
    public const STATUS_ACTIVE = 1;
    public const STATUS_ARCHIVED = 2;
    public const STATUS_DELETED = 3;

    // Column constants
    public const ID = 'id';
    public const USER_ID = 'user_id';
    public const PATIENT_ID = 'patient_id';
    public const START_TIME = 'start_time';
    public const END_TIME = 'end_time';
    public const NOTE = 'note';
    public const PROGRESS = 'progress';
    public const STATUS = 'status';
    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = 'updated_at';

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
    public int $progress = self::PROGRESS_SCHEDULED;

    // Record status
    public int $status = self::STATUS_ACTIVE;

    // Timestamps
    public string $created_at;
    public string $updated_at;

    /**
     * Initialize model relationships and behaviors
     */
    public function initialize(): void
    {
        $this->setSource('visit');

        // Define relationships
        $this->belongsTo(
            'user_id',
            User::class,
            'id',
            [
                'alias' => 'user',
                'reusable' => true
            ]
        );

        $this->belongsTo(
            'patient_id',
            Patient::class,
            'id',
            [
                'alias' => 'patient',
                'reusable' => true
            ]
        );

        // Add automatic timestamp behavior
        $this->addBehavior(
            new Timestampable([
                'beforeCreate' => [
                    'field' => 'created_at',
                    'format' => 'Y-m-d H:i:s'
                ],
                'beforeUpdate' => [
                    'field' => 'updated_at',
                    'format' => 'Y-m-d H:i:s'
                ]
            ])
        );
    }

    /**
     * Model validation
     */
    public function validation(): bool
    {
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
                    self::PROGRESS_CANCELED,
                    self::PROGRESS_SCHEDULED,
                    self::PROGRESS_IN_PROGRESS,
                    self::PROGRESS_COMPLETED,
                    self::PROGRESS_PAID
                ],
                'message' => 'Invalid progress value'
            ])
        );

        // Status validation
        $validator->add(
            self::STATUS,
            new InclusionIn([
                'domain' => [
                    self::STATUS_ACTIVE,
                    self::STATUS_ARCHIVED,
                    self::STATUS_DELETED
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
                        'End time cannot be before end time',
                        self::END_TIME
                    )
                );
                return false;
            }
        }

        return $this->validate($validator);
    }

    /**
     * Format datetime for display
     */
    private function formatDateTime(string $datetime, string $format = 'Y-m-d H:i:s'): string
    {
        $date = new DateTime($datetime);
        return $date->format($format);
    }

    /**
     * Get formatted start time
     */
    public function getFormattedStartTime(string $format = 'Y-m-d h:i A'): string
    {
        return $this->formatDateTime($this->start_time, $format);
    }

    /**
     * Get formatted end time
     */
    public function getFormattedEndTime(string $format = 'Y-m-d h:i A'): string
    {
        return $this->formatDateTime($this->end_time, $format);
    }

    /**
     * Get visit duration in minutes
     */
    public function getDurationMinutes(): int
    {
        $startTime = new DateTime($this->start_time);
        $endTime = new DateTime($this->end_time);

        $interval = $startTime->diff($endTime);
        return ($interval->days * 24 * 60) + ($interval->h * 60) + $interval->i;
    }

    /**
     * Get formatted visit duration (e.g., "2 hours 30 minutes")
     */
    public function getFormattedDuration(): string
    {
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
    public function getProgressDescription(): string
    {
        $descriptions = [
            self::PROGRESS_CANCELED => 'Canceled',
            self::PROGRESS_SCHEDULED => 'Scheduled',
            self::PROGRESS_IN_PROGRESS => 'In Progress',
            self::PROGRESS_COMPLETED => 'Completed',
            self::PROGRESS_PAID => 'Paid'
        ];

        return $descriptions[$this->progress] ?? 'Unknown';
    }

    /**
     * Check if visit is canceled
     */
    public function isCanceled(): bool
    {
        return $this->progress === self::PROGRESS_CANCELED;
    }

    /**
     * Check if visit is scheduled
     */
    public function isScheduled(): bool
    {
        return $this->progress === self::PROGRESS_SCHEDULED;
    }

    /**
     * Check if visit is in progress
     */
    public function isInProgress(): bool
    {
        return $this->progress === self::PROGRESS_IN_PROGRESS;
    }

    /**
     * Check if visit is completed
     */
    public function isCompleted(): bool
    {
        return $this->progress === self::PROGRESS_COMPLETED;
    }

    /**
     * Check if visit is paid
     */
    public function isPaid(): bool
    {
        return $this->progress === self::PROGRESS_PAID;
    }

    /**
     * Check if visit is active
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Find visits for a specific user (caregiver)
     */
    public static function findByUser(int $userId, array $params = []): \Phalcon\Mvc\Model\ResultsetInterface
    {
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
            $bind['status'] = self::STATUS_ACTIVE;
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
    public static function findByPatient(int $patientId, array $params = []): \Phalcon\Mvc\Model\ResultsetInterface
    {
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
            $bind['status'] = self::STATUS_ACTIVE;
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
    public static function findTodaysVisits(int $userId = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        $today = date('Y-m-d');
        $conditions = ['DATE(start_time) = :today: AND progress = :progress: AND status = :status:'];
        $bind = [
            'today' => $today,
            'progress' => self::PROGRESS_SCHEDULED,
            'status' => self::STATUS_ACTIVE
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
    public static function findByDateRange(string $startDate, string $endDate, array $params = []): \Phalcon\Mvc\Model\ResultsetInterface
    {
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
            $bind['status'] = self::STATUS_ACTIVE;
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