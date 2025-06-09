<?php

declare(strict_types=1);

namespace Api\Controllers;

use Api\Constants\Message;
use Api\Encoding\Base64;
use Api\Models\User;
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
                return $this->respondWithSuccess(Message::DB_NO_RECORDS, 204, Message::DB_NO_RECORDS);

            $usersArray = $users->toArray();

            // Process sensitive data (SSN) if present
            foreach ($usersArray as &$user) {
                if (isset($user[User::SSN]) && !empty($user[User::SSN])) {
                    $user[User::SSN] = Base64::decodingSaltedPeppered($user[User::SSN]);
                }
            }

            return $this->respondWithSuccess([
                'count' => $users->count(),
                'users' => $usersArray
            ]);

        } catch (Exception $e) {
            $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
            error_log('Exception: ' . $message);
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
                'bind' => [User::ID => $id],
                'bindTypes' => [User::ID => \PDO::PARAM_INT]
            ]);

            if (!$user) {
                return $this->respondWithError(Message::USER_NOT_FOUND, 404);
            }

            // Convert to array and mask sensitive data
            $userData = $user->toArray();

            // Mask SSN
            if (isset($userData[User::SSN]) && !empty($userData[User::SSN])) {
                $userData[User::SSN] =  Base64::decodingSaltedPeppered($userData[User::SSN]);
            }

            return $this->respondWithSuccess($userData);

        } catch (Exception $e) {
            $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
            error_log('Exception: ' . $message);
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }
}