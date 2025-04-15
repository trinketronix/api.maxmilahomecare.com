<?php

declare(strict_types=1);

namespace Api\Controllers;

use Exception;
use Api\Constants\Role;
use Api\Constants\Status;
use Api\Models\Auth;
use Api\Models\User;
use Api\Constants\Message;

class AuthController extends BaseController {
    /**
     * Create a new user account
     */
    public function register(): array {
        try {

            $data = $this->getRequestBody();

            // Validate required fields
            if (empty($data[Auth::USERNAME]) || empty($data[Auth::PASSWORD])) {
                return $this->respondWithError(Message::CREDENTIALS_REQUIRED, 400);
            }

            // Validate email format
            if (!filter_var($data[Auth::USERNAME], FILTER_VALIDATE_EMAIL)) {
                return $this->respondWithError(Message::EMAIL_INVALID, 400);
            }

            // Execute within transaction
            return $this->withTransaction(function() use ($data) {
                // Create new user record
                $auth = new Auth();
                $auth->username = $data[Auth::USERNAME];
                $auth->setPassword($data[Auth::PASSWORD]);
                $auth->role = Role::CAREGIVER;
                $auth->status = Status::NOT_VERIFIED;

                if (!$auth->save()) {
                    return $this->respondWithError($auth->getMessages(), 422);
                }

                if (!$auth->id) {
                    return $this->respondWithError(Message::DB_ID_GENERATION_FAILED, 422);
                }

                if (!User::createTemplate($auth->id, $auth->username)) {
                    return $this->respondWithError(Message::DB_SESSION_UPDATE_FAILED, 422);
                }

                return $this->respondWithSuccess(Message::USER_CREATED, 201);
            });

        } catch (Exception $e) {
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Process user login
     */
    public function login(): array {
        try {
            return $this->processLogin($this->getRequestBody());
        } catch (Exception $e) {
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Activate a user account
     */
    public function activateAccount(): array {
        try {
            // Role validation should now be done by middleware
            // If we need additional role checking for this specific operation:
            if (!$this->isManagerOrHigher()) {
                return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 401);
            }

            $data = $this->getRequestBody();

            if (empty($data[Auth::USERNAME])) {
                return $this->respondWithError(Message::EMAIL_REQUIRED, 400);
            }

            $username = $data[Auth::USERNAME];
            $auth = Auth::findFirstByUsername($username);

            if (!$auth) {
                return $this->respondWithError(Message::EMAIL_NOT_FOUND, 404);
            }

            return $this->withTransaction(function() use ($auth) {
                $auth->status = Status::ACTIVE;

                if (!$auth->save()) {
                    return $this->respondWithError(Message::USER_ACTIVATED, 409);
                }

                return $this->respondWithSuccess([
                    'message' => Message::USER_ACTIVATED
                ], 202);
            });

        } catch (Exception $e) {
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Renew user's authentication token
     */
    public function renewToken(): array {
        try {
            $userId = $this->getCurrentUserId();
            $auth = $this->getAuthUserById($userId);

            if (!$auth) {
                return $this->respondWithError(Message::INVALID_CREDENTIALS, 401);
            }

            // Token service should now be available through DI
            $tokenService = $this->getDI()->get('tokenService');
            $newToken = $tokenService->createToken($auth);
            $newExpiration = $tokenService->getExpiration($newToken);

            return $this->withTransaction(function() use ($auth, $newToken, $newExpiration) {
                $auth->token = $newToken;
                $auth->expiration = $newExpiration;

                if (!$auth->save()) {
                    return $this->respondWithError(Message::TOKEN_INVALID, 409);
                }

                return $this->respondWithSuccess([
                    Auth::TOKEN => $newToken
                ]);
            });

        } catch (Exception $e) {
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Change a user's role
     */
    public function changeRole(): array {
        try {
            if (!$this->isManagerOrHigher()) {
                return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 401);
            }

            $data = $this->getRequestBody();

            if (empty($data[Auth::USERNAME]) || !isset($data[Auth::ROLE])) {
                return $this->respondWithError('User and role were expected', 400);
            }

            $username = $data[Auth::USERNAME];
            $userRole = (int)$data[Auth::ROLE];

            // Validate role
            if (!in_array($userRole, [Role::ADMINISTRATOR, Role::MANAGER, Role::CAREGIVER])) {
                return $this->respondWithError(Message::ROLE_INVALID, 400);
            }

            $auth = Auth::findFirstByUsername($username);

            if (!$auth) {
                return $this->respondWithError(Message::USER_NOT_FOUND, 404);
            }

            return $this->withTransaction(function() use ($auth, $userRole) {
                $auth->role = $userRole;

                if (!$auth->save()) {
                    return $this->respondWithError(Message::ROLE_CHANGE_FAILED, 409);
                }

                return $this->respondWithSuccess([
                    'message' => Message::ROLE_CHANGED
                ], 202);
            });

        } catch (Exception $e) {
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Change a user's password
     */
    public function changePassword(): array {
        try {
            $data = $this->getRequestBody();

            if (empty($data[Auth::USERNAME]) || empty($data[Auth::PASSWORD])) {
                return $this->respondWithError('Username and new password is expected', 400);
            }

            $username = $data[Auth::USERNAME];
            $auth = Auth::findFirstByUsername($username);

            if (!$auth) {
                return $this->respondWithError(Message::EMAIL_NOT_FOUND, 404);
            }

            return $this->withTransaction(function() use ($auth, $data) {
                $auth->setPassword($data[Auth::PASSWORD]);

                if (!$auth->save()) {
                    return $this->respondWithError(Message::PASSWORD_CHANGE_FAILED, 409);
                }

                return $this->respondWithSuccess([
                    'message' => Message::PASSWORD_CHANGED
                ], 202);
            });

        } catch (Exception $e) {
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Get all auth records (admin only)
     */
    public function getAuths(): array {
        try {
            if (!$this->isAdmin()) {
                return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 401);
            }

            $auth = Auth::find();

            if (!$auth) {
                return $this->respondWithError(Message::DB_QUERY_FAILED, 500);
            }

            if ($auth->count() === 0) {
                return $this->respondWithSuccess(Message::DB_NO_RECORDS, 204);
            }

            return $this->respondWithSuccess($auth->toArray());

        } catch (Exception $e) {
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Process login
     */
    private function processLogin(array $data): array {
        try {
            if (empty($data[Auth::USERNAME]) || empty($data[Auth::PASSWORD])) {
                return $this->respondWithError(Message::CREDENTIALS_REQUIRED, 400);
            }

            $auth = Auth::findFirstByUsername($data[Auth::USERNAME]);

            if (!$auth || !$auth->isCorrect($data[Auth::PASSWORD])) {
                return $this->respondWithError(Message::INVALID_CREDENTIALS, 401);
            }

            if (!$auth->isActive()) {
                return $this->respondWithError(Message::ACCOUNT_NOT_ACTIVATED, 403);
            }

            $tokenService = $this->getDI()->get('tokenService');
            $token = $tokenService->createToken($auth);
            $expiration = $tokenService->getExpiration($token);

            return $this->withTransaction(function() use ($auth, $token, $expiration) {
                $auth->token = $token;
                $auth->expiration = $expiration;

                if (!$auth->save()) {
                    return $this->respondWithError(Message::DB_SESSION_UPDATE_FAILED, 500);
                }

                return $this->respondWithSuccess([
                    Auth::TOKEN => $token
                ]);
            });

        } catch (Exception $e) {
            if ($this->db->isUnderTransaction()) {
                $this->rollbackTransaction();
            }
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }
}