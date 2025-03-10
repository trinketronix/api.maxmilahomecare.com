<?php

declare(strict_types=1);

namespace Api\Models;

use Api\Constants\Message;
use Phalcon\Filter\Validation;
use Phalcon\Filter\Validation\Validator\PresenceOf;
use Phalcon\Mvc\Model;
use Phalcon\Mvc\Model\Behavior\Timestampable;

class Patient extends Model
{
    // Status constants
    public const STATUS_ACTIVE = 0;
    public const STATUS_ARCHIVED = 1;
    public const STATUS_DELETED = 2;

    // Column constants
    public const ID = 'id';
    public const PATIENT_ID = 'patient';
    public const ADMISSION = 'admission';
    public const FIRSTNAME = 'firstname';
    public const MIDDLENAME = 'middlename';
    public const LASTNAME = 'lastname';
    public const PHONE = 'phone';
    public const STATUS = 'status';
    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = 'updated_at';

    // Primary identification
    public ?int $id = null;
    public ?string $patient = null;
    public ?string $admission = null;

    // Personal information
    public string $firstname;
    public ?string $middlename = null;
    public string $lastname;

    // Contact information
    public string $phone;

    // Status
    public int $status = self::STATUS_ACTIVE;

    // Timestamps
    public string $created_at;
    public string $updated_at;

    /**
     * Initialize model relationships and behaviors
     */
    public function initialize(): void
    {
        $this->setSource('patient');

        // Define relationships with addresses
        $this->hasMany(
            'id',
            Address::class,
            'person_id',
            [
                'alias' => 'addresses',
                'params' => [
                    'conditions' => 'person_type = ' . Address::PERSON_TYPE_PATIENT
                ],
                'reusable' => true
            ]
        );

        // Define relationships with visits/appointments
        $this->hasMany(
            'id',
            Visit::class,
            'patient_id',
            [
                'alias' => 'visits',
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
            self::FIRSTNAME => 'First name is required',
            self::LASTNAME => 'Last name is required',
            self::PHONE => 'Phone number is required'
        ];

        foreach ($requiredFields as $field => $message) {
            $validator->add(
                $field,
                new PresenceOf([
                    'message' => $message
                ])
            );
        }

        // Status validation
        $validator->add(
            self::STATUS,
            new \Phalcon\Filter\Validation\Validator\InclusionIn([
                'domain' => [
                    self::STATUS_ACTIVE,
                    self::STATUS_ARCHIVED,
                    self::STATUS_DELETED
                ],
                'message' => Message::STATUS_INVALID
            ])
        );

        return $this->validate($validator);
    }

    /**
     * Get patient's full name
     */
    public function getFullName(): string
    {
        $name = $this->firstname;

        if (!empty($this->middlename)) {
            $name .= ' ' . $this->middlename;
        }

        $name .= ' ' . $this->lastname;

        return $name;
    }

    /**
     * Check if patient is active
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Check if patient is archived
     */
    public function isArchived(): bool
    {
        return $this->status === self::STATUS_ARCHIVED;
    }

    /**
     * Check if patient is deleted
     */
    public function isDeleted(): bool
    {
        return $this->status === self::STATUS_DELETED;
    }

    /**
     * Get all patient addresses
     */
    public function getAddresses(): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return $this->addresses;
    }

    /**
     * Get primary address (most recently created)
     */
    public function getPrimaryAddress(): ?Address
    {
        $addresses = $this->addresses;
        if (count($addresses) === 0) {
            return null;
        }

        // Sort addresses by creation date, newest first
        $addressArray = $addresses->toArray();
        usort($addressArray, function($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });

        // Return the newest address
        return Address::findFirst($addressArray[0]['id']);
    }

    /**
     * Find active patients
     */
    public static function findActive(): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return self::find([
            'conditions' => 'status = :status:',
            'bind' => ['status' => self::STATUS_ACTIVE],
            'bindTypes' => ['status' => \PDO::PARAM_INT],
            'order' => 'lastname, firstname'
        ]);
    }

    /**
     * Find patients by HHAexchange patient ID
     */
    public static function findByHHAPatientId(string $patientId): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return self::find([
            'conditions' => 'patient = :patient_id:',
            'bind' => ['patient_id' => $patientId],
            'bindTypes' => ['patient_id' => \PDO::PARAM_STR]
        ]);
    }

    /**
     * Find patients by admission ID
     */
    public static function findByAdmissionId(string $admissionId): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return self::find([
            'conditions' => 'admission = :admission_id:',
            'bind' => ['admission_id' => $admissionId],
            'bindTypes' => ['admission_id' => \PDO::PARAM_STR]
        ]);
    }

    /**
     * Search patients by name (partial match)
     */
    public static function searchByName(string $name): \Phalcon\Mvc\Model\ResultsetInterface
    {
        $name = '%' . trim($name) . '%';

        return self::find([
            'conditions' => 'firstname LIKE :name: OR lastname LIKE :name: OR CONCAT(firstname, " ", lastname) LIKE :name:',
            'bind' => ['name' => $name],
            'bindTypes' => ['name' => \PDO::PARAM_STR],
            'order' => 'lastname, firstname'
        ]);
    }

    /**
     * Find patients assigned to a specific user (caregiver)
     *
     * Note: This assumes you have a join table for user-patient assignments
     * If not, this would need modification based on your schema
     */
    public static function findByUserId(int $userId): \Phalcon\Mvc\Model\ResultsetInterface
    {
        // This is a placeholder - update with your actual assignment relationship
        $phql = "SELECT p.* FROM Api\Models\Patient p 
                 JOIN Api\Models\UserPatient up ON p.id = up.patient_id 
                 WHERE up.user_id = :user_id: AND p.status = :status:";

        $manager = new \Phalcon\Mvc\Model\Manager();
        return $manager->executeQuery($phql, [
            'user_id' => $userId,
            'status' => self::STATUS_ACTIVE
        ]);
    }
}