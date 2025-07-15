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
    public const string VISIT_DATE = 'visit_date';
    public const string START_TIME = 'start_time';
    public const string END_TIME = 'end_time';
    public const string TOTAL_HOURS = 'total_hours';
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
    public int $address_id;

    // Visit information
    public string $visit_date;
    public ?string $start_time = null;
    public ?string $end_time = null;
    public int $total_hours = 1;
    public ?string $note = null;

    // Visit status information
    public int $progress = Progress::SCHEDULED;

    // Progress tracking fields
    public int $scheduled_by;
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

        // Define relationships
        $this->belongsTo('user_id', User::class, 'id', ['alias' => 'user']);
        $this->belongsTo('patient_id', Patient::class, 'id', ['alias' => 'patient']);
        $this->belongsTo('address_id', Address::class, 'id', ['alias' => 'address']);
        $this->belongsTo('scheduled_by', User::class, 'id', ['alias' => 'scheduler']);
        $this->belongsTo('checkin_by', User::class, 'id', ['alias' => 'checkinUser']);
        $this->belongsTo('checkout_by', User::class, 'id', ['alias' => 'checkoutUser']);
        $this->belongsTo('canceled_by', User::class, 'id', ['alias' => 'cancelerUser']);
        $this->belongsTo('approved_by', User::class, 'id', ['alias' => 'approverUser']);
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
            self::ADDRESS_ID => 'Address ID is required',
            self::VISIT_DATE => 'Visit date is required',
            self::TOTAL_HOURS => 'Total hours is required',
            self::SCHEDULED_BY => 'Scheduled by is required'
        ];

        foreach ($requiredFields as $field => $message) {
            $validator->add(
                $field,
                new PresenceOf(['message' => $message])
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

        // Total hours validation
        $validator->add(
            self::TOTAL_HOURS,
            new \Phalcon\Filter\Validation\Validator\Between([
                'minimum' => 1,
                'maximum' => 24,
                'message' => 'Total hours must be between 1 and 24'
            ])
        );

        // Time validation: if start_time is set, end_time should be calculated
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

        // Validate that canceled visits must have a note
        if ($this->progress === Progress::CANCELED && empty($this->note)) {
            $this->appendMessage(
                new \Phalcon\Messages\Message(
                    'Canceled visits must have a note explaining the cancellation',
                    self::NOTE
                )
            );
            return false;
        }

        return $this->validate($validator);
    }

    /**
     * Actions before create
     */
    public function beforeCreate(): bool {
        // Set scheduled_by to user_id if not set
        if (empty($this->scheduled_by)) {
            $this->scheduled_by = $this->user_id;
        }

        // Calculate end_time if start_time is provided
        if (!empty($this->start_time) && empty($this->end_time)) {
            $this->calculateEndTime();
        }

        return true;
    }

    /**
     * Actions before update
     */
    public function beforeUpdate(): bool {
        // Recalculate end_time if start_time or total_hours changed
        if ($this->hasChanged([self::START_TIME, self::TOTAL_HOURS]) && !empty($this->start_time)) {
            $this->calculateEndTime();
        }

        return true;
    }

    /**
     * Calculate end time based on start time and total hours
     */
    private function calculateEndTime(): void {
        if (!empty($this->start_time) && $this->total_hours > 0) {
            $startTime = new DateTime($this->start_time);
            $startTime->modify("+{$this->total_hours} hours");
            $this->end_time = $startTime->format('Y-m-d H:i:s');
        }
    }

    /**
     * Get duration in minutes
     */
    public function getDurationMinutes(): int {
        if (empty($this->start_time) || empty($this->end_time)) {
            return $this->total_hours * 60;
        }

        $startTime = new DateTime($this->start_time);
        $endTime = new DateTime($this->end_time);

        $interval = $startTime->diff($endTime);
        return ($interval->days * 24 * 60) + ($interval->h * 60) + $interval->i;
    }

    /**
     * Get progress description
     */
    public function getProgressDescription(): string {
        $descriptions = [
            Progress::CANCELED => 'Canceled',
            Progress::SCHEDULED => 'Scheduled',
            Progress::IN_PROGRESS => 'Checked In',
            Progress::COMPLETED => 'Checked Out',
            Progress::PAID => 'Approved'
        ];

        return $descriptions[$this->progress] ?? 'Unknown';
    }

    /**
     * Check if visit can be checked in
     */
    public function canCheckIn(): bool {
        return $this->progress === Progress::SCHEDULED && $this->status === Status::ACTIVE;
    }

    /**
     * Check if visit can be checked out
     */
    public function canCheckOut(): bool {
        return $this->progress === Progress::IN_PROGRESS && $this->status === Status::ACTIVE;
    }

    /**
     * Check if visit can be approved
     */
    public function canApprove(): bool {
        return $this->progress === Progress::COMPLETED && $this->status === Status::ACTIVE;
    }

    /**
     * Check if visit can be canceled
     */
    public function canCancel(): bool {
        return in_array($this->progress, [Progress::SCHEDULED, Progress::IN_PROGRESS])
            && $this->status === Status::ACTIVE;
    }

    /**
     * Helper methods for progress checks
     */
    public function isCanceled(): bool {
        return $this->progress === Progress::CANCELED;
    }

    public function isScheduled(): bool {
        return $this->progress === Progress::SCHEDULED;
    }

    public function isInProgress(): bool {
        return $this->progress === Progress::IN_PROGRESS;
    }

    public function isCompleted(): bool {
        return $this->progress === Progress::COMPLETED;
    }

    public function isApproved(): bool {
        return $this->progress === Progress::PAID;
    }

    /**
     * Helper methods for status checks
     */
    public function isVisible(): bool {
        return $this->status === Status::ACTIVE;
    }

    public function isArchived(): bool {
        return $this->status === Status::ARCHIVED;
    }

    public function isSoftDeleted(): bool {
        return $this->status === Status::SOFT_DELETED;
    }

    /**
     * Get user data
     */
    public function getUserData(): array {
        $user = User::findFirstById($this->user_id);
        return $user ? $user->toArray() : [];
    }

    /**
     * Get patient data
     */
    public function getPatientData(): array {
        $patient = Patient::findFirstById($this->patient_id);
        return $patient ? $patient->toArray() : [];
    }

    /**
     * Get address data
     */
    public function getAddressData(): array {
        $address = Address::findFirstById($this->address_id);
        return $address ? $address->toArray() : [];
    }

    /**
     * Note: For custom ordered results, use OrderedVisit model instead
     * This method remains for backward compatibility and simple queries
     */
    public static function findWithCustomOrder(array $conditions = [], array $bind = [], array $bindTypes = []): \Phalcon\Mvc\Model\ResultsetInterface {
        // Simply order by date and time for basic queries
        return self::find([
            'conditions' => implode(' AND ', $conditions),
            'bind' => $bind,
            'bindTypes' => $bindTypes,
            'order' => 'visit_date DESC, start_time DESC'
        ]);
    }
}