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
            error_log('Exception: ' . $e->getMessage());
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Get account by ID
     * If no ID provided or ID matches current user, return current user's account
     * For other IDs, only managers or admins can access
     *
     * @param int|null $id User ID (optional)
     * @return array Response data
     */
    public function getById($id = null): array {
        try {
            // Get current user ID
            $currentUserId = $this->getCurrentUserId();

            // If no ID provided in URL or empty string, use current user ID
            if ($id === null || $id === '') {
                $id = $currentUserId;
            } else {
                $id = (int)$id;
            }

            // If requesting another user's account, check authorization
            if ($id !== $currentUserId && !$this->isManagerOrHigher()) {
                return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 403);
            }

            // Find user in UserAuthView
            $user = UserAuthView::findFirst([
                'conditions' => 'id = :id:',
                'bind' => ['id' => $id],
                'bindTypes' => ['id' => \PDO::PARAM_INT]
            ]);

            if (!$user) {
                return $this->respondWithError(Message::USER_NOT_FOUND, 404);
            }

            // Convert to array and mask sensitive data
            $userData = $user->toArray();

            // Mask SSN
            if (isset($userData['ssn']) && !empty($userData['ssn'])) {
                $userData['ssn'] = '***-**-****';
            }

            return $this->respondWithSuccess($userData);

        } catch (Exception $e) {
            error_log('Exception: ' . $e->getMessage());
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }
}