<?php

declare(strict_types=1);

namespace Api\Models;

use Api\Constants\Message;
use Api\Constants\Role;
use Api\Constants\Status;
use Phalcon\Mvc\Model;
use Phalcon\Filter\Validation;
use Phalcon\Filter\Validation\Validator\Email;
use Phalcon\Filter\Validation\Validator\InclusionIn;
use Phalcon\Filter\Validation\Validator\PresenceOf;
use Phalcon\Filter\Validation\Validator\Uniqueness;

class Auth extends Model {
    // Class constants for column names
    public const string ID = 'id';
    public const string USERNAME = 'username';
    public const string PASSWORD = 'password';
    public const string TOKEN = 'token';
    public const string EXPIRATION = 'expiration';
    public const string ROLE = 'role';
    public const string STATUS = 'status';

    // Model properties
    public ?int $id = null; // Make it nullable with default null
    public string $username;
    public string $password;
    public ?string $token = null;
    public ?int $expiration = null;
    public int $role = Role::CAREGIVER; // Default to caregiver
    public int $status = Status::INACTIVE; // Default to deactivated

    // Make timestamp fields nullable with default values
    public ?string $created_at = null;
    public ?string $updated_at = null;

    /**
     * Initialize the model
     */
    public function initialize(): void {
        $this->setSource('auth');

        // Set up relationships
        $this->hasOne(
            'id',
            User::class,
            'id',
            [
                'alias' => 'user',
                'reusable' => true
            ]
        );

        // Setup behaviors
        $this->addBehavior(
            new \Phalcon\Mvc\Model\Behavior\Timestampable([
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
     * Model validation rules
     */
    public function validation(): bool {
        $validator = new Validation();

        // Username must be present and a valid email
        $validator->add(
            self::USERNAME,
            new PresenceOf([
                'message' => Message::EMAIL_REQUIRED
            ])
        );

        $validator->add(
            self::USERNAME,
            new Email([
                'message' => Message::EMAIL_INVALID
            ])
        );

        // Username must be unique
        $validator->add(
            self::USERNAME,
            new Uniqueness([
                'message' => Message::EMAIL_REGISTERED
            ])
        );

        // Role must be valid
        $validator->add(
            self::ROLE,
            new InclusionIn([
                'domain' => [Role::ADMINISTRATOR, Role::MANAGER, Role::CAREGIVER],
                'message' => Message::ROLE_INVALID
            ])
        );

        // Status must be valid
        $validator->add(
            self::STATUS,
            new InclusionIn([
                'domain' => [Status::NOT_VERIFIED, Status::INACTIVE, Status::ACTIVE, Status::ARCHIVED, Status::SOFT_DELETED],
                'message' => Message::STATUS_INVALID
            ])
        );

        return $this->validate($validator);
    }

    /**
     * Set a password with secure hashing
     */
    public function setPassword(string $password): void {
        $this->password = $this->hashPassword($password);
    }

    /**
     * Verify if a password is correct
     */
    public function isCorrect(string $password): bool {
        return hash_equals($this->password, $this->hashPassword($password));
    }

    /**
     * Hash a password using the defined algorithm
     */
    private function hashPassword(string $password): string {
        $salt = $this->username; // Using username as salt
        $code = $salt . $password . $salt;
        return hash('sha512', $code);
    }

    /**
     * Check if user is deleted
     */
    public function isDeleted(): bool {
        return $this->status === Status::SOFT_DELETED;
    }

    /**
     * Check if user account is active
     */
    public function isActive(): bool {
        return $this->status === Status::ACTIVE;
    }

    /**
     * Check if token is expired
     */
    public function isTokenExpired(): bool {
        if ($this->expiration === null) {
            return true;
        }

        $currentTime = (int)(microtime(true) * 1000);
        return $this->expiration < $currentTime;
    }

    /**
     * Reset authentication token
     */
    public function clearToken(): void {
        $this->token = null;
        $this->expiration = null;
    }

    /**
     * Initialize timestamp fields explicitly before insert
     */
    public function beforeValidationOnCreate()
    {
        // Set created_at and updated_at to current timestamp if they're null
        if ($this->created_at === null) {
            $this->created_at = date('Y-m-d H:i:s');
        }

        if ($this->updated_at === null) {
            $this->updated_at = date('Y-m-d H:i:s');
        }
    }

    /**
     * Map role values to readable strings
     */
    public function getRoleName(): string {
        return match($this->role) {
            0 => 'Administrator',
            1 => 'Manager',
            2 => 'Caregiver',
            default => 'Unknown'
        };
    }

    /**
     * Map status values to readable strings
     */
    public function getStatusName(): string {
        return match($this->status) {
            -1 => 'Not Verified',
            0 => 'Inactive',
            1 => 'Active',
            2 => 'Archived',
            3 => 'Soft-Deleted',
            default => 'Unknown'
        };
    }
}