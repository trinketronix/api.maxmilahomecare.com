<?php

declare(strict_types=1);

namespace Api\Controllers;

use Api\Constants\Role;
use Api\Constants\Message;
use Api\Models\UserAuthView;
use Exception;

class AccountController extends BaseController {
    /**
     * Get all user accounts with detailed information
     * Restricted to Managers and Administrators only
     */
    public function getAll(): array {
        try {
            // Check if the current user has appropriate role (admin or manager)
            if (!$this->isManagerOrHigher())
                return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 403);

            // Fetch all records from the UserAuthView
            $users = UserAuthView::find([
                'order' => 'lastname, firstname'
            ]);

            if (!$users)
                return $this->respondWithError(Message::DB_QUERY_FAILED, 500);

            if ($users->count() === 0)
                return $this->respondWithSuccess(Message::DB_NO_RECORDS, 204);

            $usersArray = $users->toArray();

            // Process sensitive data (SSN) if present
            foreach ($usersArray as &$user) {
                if (isset($user['ssn']) && !empty($user['ssn'])) {
                    // Make sure we don't include plain SSNs in the response
                    // BaseController has a method for decoding SSNs
                    $user['ssn'] = '***-**-****'; // Mask SSN for security
                }
            }

            return $this->respondWithSuccess([
                'count' => $users->count(),
                'users' => $usersArray
            ]);

        } catch (Exception $e) {
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }
}