<?php

declare(strict_types=1);

namespace Api\Models;

use Phalcon\Mvc\Model;

class UserAuthView extends Model {
    // Model properties
    public ?int $id = null;
    public string $username;
    public int $role;
    public int $status;
    public ?string $token = null;
    public ?int $expiration = null;
    public ?string $auth_created_at = null;
    public ?string $auth_updated_at = null;
    public ?string $firstname = null;
    public ?string $lastname = null;
    public ?string $middlename = null;
    public ?string $birthdate = null;
    public ?string $ssn = null;
    public ?string $code = null;
    public ?string $phone = null;
    public ?string $phone2 = null;
    public string $email;
    public ?string $email2 = null;
    public ?string $languages = null;
    public ?string $description = null;
    public ?string $photo = null;
    public ?string $user_created_at = null;
    public ?string $user_updated_at = null;

    /**
     * Initialize model
     */
    public function initialize(): void {
        // Set the source to our view instead of a regular table
        $this->setSource('user_auth_view');

        // This is a read-only model as it's based on a view
        //$this->setReadOnly(true);
    }

    /**
     * Search account by firstname, lastname, email, username, phone, code
     */
    public static function search(string $term): array {
        return self::find([
            'conditions' => 'firstname LIKE :term: OR 
                          lastname LIKE :term: OR 
                          email LIKE :term: OR 
                          username LIKE :term: OR
                          phone LIKE :term: OR
                          code LIKE :term:',
            'bind' => ['term' => '%' . $term . '%'],
            'order' => 'lastname ASC, firstname ASC'
        ])->toArray();
    }

    public static function findByRole(int $role): array {
        if (!in_array($role, [Role::ADMINISTRATOR, Role::MANAGER, Role::CAREGIVER])) {
            throw new InvalidArgumentException('Invalid role value');
        }

        return self::find([
            'conditions' => 'role = :role:',
            'bind' => ['role' => $role],
            'order' => 'lastname ASC, firstname ASC'
        ])->toArray();
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

    /**
     * Format the user's full name
     */
    public function getFullName(): string {
        $name = $this->firstname ?? '';
        if (!empty($this->middlename)) $name .= ' ' . $this->middlename;
        $name .= ' ' . ($this->lastname ?? '');
        return trim($name);
    }
}