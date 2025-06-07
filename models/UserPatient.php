<?php

declare(strict_types=1);

namespace Api\Models;

use Api\Constants\Status;
use Phalcon\Mvc\Model;

class UserPatient extends Model {
    // Column constants
    public const string USER_ID = 'user_id';
    public const string USER_IDS = 'user_ids';
    public const string PATIENT_ID = 'patient_id';
    public const string PATIENT_IDS = 'patient_ids';
    public const string ASSIGNED_AT = 'assigned_at';
    public const string ASSIGNED_BY = 'assigned_by';
    public const string NOTES = 'notes';
    public const string STATUS = 'status';
    public const string CREATED_AT = 'created_at';
    public const string UPDATED_AT = 'updated_at';

    // Properties
    public int $user_id;
    public int $patient_id;
    public string $assigned_at;
    public int $assigned_by;
    public ?string $notes = null;
    public int $status = Status::ACTIVE;
    public ?string $created_at = null;
    public ?string $updated_at = null;

    /**
     * Initialize model relationships and behaviors
     */
    public function initialize(): void {
        $this->setSource('user_patient');
    }

    /**
     * Model validation
     */
    public function validation(): bool {
        $validator = new \Phalcon\Filter\Validation();

        // Validate status
        $validator->add(
            self::STATUS,
            new \Phalcon\Filter\Validation\Validator\InclusionIn([
                'domain' => [Status::INACTIVE, Status::ACTIVE],
                'message' => 'Invalid status value'
            ])
        );

        return $this->validate($validator);
    }

    /**
     * Set the assignment timestamp
     */
    public function beforeCreate(): void {
        if (empty($this->assigned_at)) {
            $this->assigned_at = date('Y-m-d H:i:s');
        }
    }

    /**
     * Find active assignments for a user
     */
    public static function findActiveByUserId(int $userId): \Phalcon\Mvc\Model\ResultsetInterface {
        return self::find([
            'conditions' => 'user_id = :user_id: AND status = :status:',
            'bind' => [
                'user_id' => $userId,
                'status' => Status::ACTIVE
            ],
            'bindTypes' => [
                'user_id' => \PDO::PARAM_INT,
                'status' => \PDO::PARAM_INT
            ],
            'order' => 'created_at DESC'
        ]);
    }

    /**
     * Find active assignments for a patient
     */
    public static function findActiveByPatientId(int $patientId): \Phalcon\Mvc\Model\ResultsetInterface {
        return self::find([
            'conditions' => 'patient_id = :patient_id: AND status = :status:',
            'bind' => [
                'patient_id' => $patientId,
                'status' => Status::ACTIVE
            ],
            'bindTypes' => [
                'patient_id' => \PDO::PARAM_INT,
                'status' => \PDO::PARAM_INT
            ],
            'order' => 'created_at DESC'
        ]);
    }

    /**
     * Find a specific user-patient assignment
     */
    public static function findAssignment(int $userId, int $patientId): ?self {
        return self::findFirst([
            'conditions' => 'user_id = :user_id: AND patient_id = :patient_id:',
            'bind' => [
                'user_id' => $userId,
                'patient_id' => $patientId
            ],
            'bindTypes' => [
                'user_id' => \PDO::PARAM_INT,
                'patient_id' => \PDO::PARAM_INT
            ]
        ]);
    }

    /**
     * Check if a user is assigned to a patient
     */
    public static function isUserAssignedToPatient(int $userId, int $patientId): bool {
        $assignment = self::findFirst([
            'conditions' => 'user_id = :user_id: AND patient_id = :patient_id: AND status = :status:',
            'bind' => [
                'user_id' => $userId,
                'patient_id' => $patientId,
                'status' => Status::ACTIVE
            ],
            'bindTypes' => [
                'user_id' => \PDO::PARAM_INT,
                'patient_id' => \PDO::PARAM_INT,
                'status' => \PDO::PARAM_INT
            ]
        ]);

        return $assignment !== null;
    }

    /**
     * Find assignments created by a specific user
     */
    public static function findByAssignedBy(int $assignedBy): \Phalcon\Mvc\Model\ResultsetInterface {
        return self::find([
            'conditions' => 'assigned_by = :assigned_by:',
            'bind' => ['assigned_by' => $assignedBy],
            'bindTypes' => ['assigned_by' => \PDO::PARAM_INT],
            'order' => 'created_at DESC'
        ]);
    }

    /**
     * Get count of active patients for a user
     */
    public static function getPatientCountForUser(int $userId): int {
        $result = self::count([
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

        return (int)$result;
    }

    /**
     * Get count of active users for a patient
     */
    public static function getUserCountForPatient(int $patientId): int {
        $result = self::count([
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

        return (int)$result;
    }
}