<?php

declare(strict_types=1);

namespace Api\Controllers;

use Api\Constants\Message;
use Api\Constants\Role;
use Api\Constants\Status;
use Api\Encoding\Base64;
use Api\Models\User;
use Api\Models\UserAuthView;
use Api\Services\InvalidQueryException;
use Exception;

class AccountController extends BaseController {
    /**
     * List accounts (managers/admins).
     * Query: page, per_page, role (0|1|2), status (-1..3), search (name, email, username, phone, code).
     * Without any parameter the legacy behaviour is kept: every row, 204 when there are none.
     */
    public function getAll(): array {
        try {
            if (!$this->isManagerOrHigher())
                return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 403);

            $paging = $this->getPaging();
            $role = $this->queryInt('role', [Role::ADMINISTRATOR, Role::MANAGER, Role::CAREGIVER]);
            $status = $this->queryInt('status', [Status::NOT_VERIFIED, Status::INACTIVE, Status::ACTIVE, Status::ARCHIVED, Status::SOFT_DELETED]);
            $search = $this->queryText('search');

            $conditions = []; $bind = []; $bindTypes = [];
            if ($role !== null) {
                $conditions[] = 'role = :role:'; $bind['role'] = $role; $bindTypes['role'] = \PDO::PARAM_INT;
            }
            if ($status !== null) {
                $conditions[] = 'status = :status:'; $bind['status'] = $status; $bindTypes['status'] = \PDO::PARAM_INT;
            }
            if ($search !== null) {
                $conditions[] = $this->likeAnyCondition(['firstname', 'lastname', 'email', 'username', 'phone', 'code'], $search, $bind, $bindTypes);
            }

            $where = $conditions ? ['conditions' => implode(' AND ', $conditions), 'bind' => $bind, 'bindTypes' => $bindTypes] : [];
            $total = (int)UserAuthView::count($where);
            $users = UserAuthView::find($this->applyPaging($where + ['order' => 'lastname, firstname'], $paging));

            $usersArray = $users->toArray();

            if (!$usersArray && !$this->hasListParams(['role', 'status', 'search']))
                return $this->respondWithSuccess(Message::DB_NO_RECORDS, 204, Message::DB_NO_RECORDS);

            // Process sensitive data (SSN) if present
            foreach ($usersArray as &$user) {
                if (!empty($user[User::SSN])) {
                    $user[User::SSN] = Base64::decodingSaltedPeppered($user[User::SSN]);
                }
            }
            unset($user);

            return $this->respondWithList('users', $usersArray, $paging, $total);

        } catch (InvalidQueryException $e) {
            return $this->respondWithError($e->getMessage(), 400);
        } catch (\Throwable $e) {
            return $this->handleException($e);
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
    public function getById(?int $id = null): array {
        try {
            // Get current user ID
            $currentUserId = $this->getCurrentUserId();

            // If no ID provided in URL, use current user ID
            $id = $id ?? $currentUserId;

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

        } catch (\Throwable $e) {
            return $this->handleException($e);
        }
    }
}