<?php

declare(strict_types=1);

namespace Api\Models;

use Api\Constants\Message;
use Phalcon\Filter\Validation;
use Phalcon\Filter\Validation\Validator\Email;
use Phalcon\Filter\Validation\Validator\PresenceOf;
use Phalcon\Filter\Validation\Validator\Uniqueness;
use Phalcon\Mvc\Model;
use Phalcon\Mvc\Model\Behavior\Timestampable;

class User extends Model {
    // Column constants
    public const string ID = 'id';
    public const string LASTNAME = 'lastname';
    public const string FIRSTNAME = 'firstname';
    public const string MIDDLENAME = 'middlename';
    public const string FULLNAME = 'fullname';
    public const string BIRTHDATE = 'birthdate';
    public const string SSN = 'ssn';
    public const string CODE = 'code';
    public const string PHONE = 'phone';
    public const string PHONE2 = 'phone2';
    public const string EMAIL = 'email';
    public const string EMAIL2 = 'email2';
    public const string LANGUAGES = 'languages';
    public const string DESCRIPTION = 'description';
    public const string PHOTO = 'photo';
    public const string CREATED_AT = 'created_at';
    public const string UPDATED_AT = 'updated_at';

    // File paths
    public const string PATH_PHOTO_FILE = 'user/photo';
    public const string DEFAULT_PHOTO_FILE = '/'.self::PATH_PHOTO_FILE.'/default.jpg';

    // Primary identification
    public ?int $id = null;

    // Personal information
    public string $lastname = 'TBD';
    public string $firstname = 'TBD';
    public ?string $middlename = null;
    public ?string $birthdate = null;

    // Sensitive information
    public ?string $ssn = null;

    // Professional information
    public ?string $code = null;

    // Contact information
    public ?string $phone = null;
    public ?string $phone2 = null;
    public string $email;
    public ?string $email2 = null;

    // Additional information
    public ?string $languages = null;
    public ?string $description = null;

    // Profile media
    public ?string $photo = null;

    // Audit timestamps
    public ?string $created_at = null;
    public ?string $updated_at = null;

    /**
     * Initialize the model relationships and behaviors
     */
    public function initialize(): void {
        $this->setSource('user');

        // Define relationships
        $this->belongsTo(
            'id',
            Auth::class,
            'id',
            [
                'alias' => 'auth',
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
    public function validation(): bool {
        $validator = new Validation();

        // Required fields validation
        $validator->add(
            self::EMAIL,
            new PresenceOf([
                'message' => Message::EMAIL_REQUIRED
            ])
        );

        // Email format validation
        $validator->add(
            self::EMAIL,
            new Email([
                'message' => Message::EMAIL_INVALID
            ])
        );

        // Email uniqueness validation
        $validator->add(
            self::EMAIL,
            new Uniqueness([
                'message' => Message::EMAIL_REGISTERED
            ])
        );

        // Optional: Validate secondary email format if provided
        if (!empty($this->email2)) {
            $validator->add(
                self::EMAIL2,
                new Email([
                    'message' => Message::EMAIL_SECONDARY_INVALID
                ])
            );
        }

        return $this->validate($validator);
    }

    /**
     * Create a new user with minimal required information
     */
    public static function createTemplate(int $id, string $email): bool {
        $user = new self();
        $user->id = $id;
        $user->email = $email;
        $user->lastname = 'TBD';
        $user->firstname = 'TBD';
        $user->photo = self::DEFAULT_PHOTO_FILE;
        return $user->save();
    }

    /**
     * Actions to perform before saving the model
     */
    public function beforeSave(): bool {
        // Format birthdate if provided
        if (!empty($this->birthdate)) {
            $this->birthdate = $this->formatDate($this->birthdate);
        }

        return true;
    }

    /**
     * Actions to perform after fetching the model
     */
    public function afterFetch() {
        // Format dates for display if needed
        if (!empty($this->birthdate)) {
            $this->birthdate = $this->formatDate($this->birthdate);
        }
    }

    /**
     * Format a date to YYYY-MM-DD
     */
    private function formatDate(string $date): string {
        return date('Y-m-d', strtotime($date));
    }

    /**
     * Get user's full name
     */
    public function getFullName(): string {
        $name = $this->firstname;

        if (!empty($this->middlename)) {
            $name .= ' ' . $this->middlename;
        }

        $name .= ' ' . $this->lastname;

        return $name;
    }

    /**
     * Get user's profile photo URL
     */
    public function getPhotoUrl(): string {
        return $this->photo ?? self::DEFAULT_PHOTO_FILE;
    }

    /**
     * Check if user has a specific language skill
     */
    public function hasLanguage(string $language): bool {
        if (empty($this->languages)) {
            return false;
        }

        $userLanguages = array_map('trim', explode(',', $this->languages));
        return in_array(strtolower($language), array_map('strtolower', $userLanguages));
    }

    /**
     * Get user with related auth information
     */
    public static function findWithAuth(int $id): ?self {
        return self::findFirst([
            'conditions' => 'id = :id:',
            'bind' => ['id' => $id],
            'bindTypes' => ['id' => \PDO::PARAM_INT],
            'with' => ['auth']
        ]);
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
}