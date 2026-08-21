<?php

declare(strict_types=1);

namespace Api\Controllers;

use Exception;
use Api\Constants\Role;
use Api\Constants\Status;
use Api\Models\Auth;
use Api\Models\User;
use Api\Constants\Message;
use Phalcon\Http\Response;

class AuthController extends BaseController {
    private const int MIN_PASSWORD_LENGTH = 8;

    private $apiBaseUrl = API_BASE_URL;
    private $appBaseUrl = APP_BASE_URL;

    /**
     * Create a new user account
     */
    public function register(): array {
        try {
            $data = $this->getRequestBody();

            // Validate required fields
            if (empty($data[Auth::USERNAME]) || empty($data[Auth::PASSWORD]) || empty($data[User::LASTNAME]) || empty($data[User::FIRSTNAME]))
                return $this->respondWithError(Message::CREDENTIALS_REQUIRED, 400);

            // Validate email format
            if (!filter_var($data[Auth::USERNAME], FILTER_VALIDATE_EMAIL))
                return $this->respondWithError(Message::EMAIL_INVALID, 400);

            if (strlen((string)$data[Auth::PASSWORD]) < self::MIN_PASSWORD_LENGTH)
                return $this->respondWithError('Password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters', 400);

            // Execute within transaction
            return $this->withTransaction(function() use ($data) {
                // Create new user record
                $auth = new Auth();
                $auth->username = $data[Auth::USERNAME];
                $auth->setPassword($data[Auth::PASSWORD]);
                $auth->role = Role::CAREGIVER;
                $auth->status = Status::NOT_VERIFIED;

                if (!$auth->save())
                    return $this->respondWithError($this->getFirstErrorMessage($auth), 422);

                if (!$auth->id)
                    return $this->respondWithError(Message::DB_ID_GENERATION_FAILED, 422);

                if (!User::createTemplate($auth->id, $auth->username, $data[User::LASTNAME], $data[User::FIRSTNAME]))
                    return $this->respondWithError(Message::DB_SESSION_UPDATE_FAILED, 422);

                if(!$this->sendActivationEmail($auth->username, $auth->password))
                    return $this->respondWithSuccess(Message::USER_CREATED." activation email fail", 201, Message::USER_CREATED." activation email fail");

                return $this->respondWithSuccess(Message::USER_CREATED." and ". Message::EMAIL_ACTIVATION_SENT . ' to: ' . $auth->username, 201, Message::USER_CREATED." and ". Message::EMAIL_ACTIVATION_SENT . ' to: ' . $auth->username);
            });

        } catch (Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Process user login
     */
    public function login(): array {
        try {
            return $this->processLogin($this->getRequestBody());
        } catch (Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Activate a user account from the system (manager/admin)
     */
    public function activateAccount(): array {
        return $this->changeAccountStatus(Status::ACTIVE, Message::USER_ACTIVATED, Message::USER_ACTIVATION_FAILED);
    }

    /**
     * Inactivate a user account from the system (manager/admin)
     */
    public function inactivateAccount(): array {
        return $this->changeAccountStatus(Status::INACTIVE, Message::USER_INACTIVATED, Message::USER_INACTIVATION_FAILED);
    }

    /**
     * Archive a user account from the system (manager/admin)
     */
    public function archivateAccount(): array {
        return $this->changeAccountStatus(Status::ARCHIVED, Message::USER_ARCHIVED, Message::USER_ARCHIVATION_FAILED);
    }

    /**
     * Soft-delete a user account from the system (manager/admin)
     */
    public function deleteAccount(): array {
        return $this->changeAccountStatus(Status::SOFT_DELETED, Message::USER_DELETED, Message::USER_DELETION_FAILED);
    }

    /**
     * Shared implementation of the account status endpoints.
     * Body: { "id": <account id> }
     * Rules: manager/admin only; managers cannot touch administrator accounts; nobody can change their own status.
     * Any status other than Active also revokes the account's session token.
     */
    private function changeAccountStatus(int $newStatus, string $successMessage, string $failureMessage): array {
        try {
            if (!$this->isManagerOrHigher())
                return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 403);

            $data = $this->getRequestBody();

            if (empty($data[Auth::ID]))
                return $this->respondWithError(Message::ID_REQUIRED, 400);

            $auth = Auth::findFirstById((int)$data[Auth::ID]);
            if (!$auth)
                return $this->respondWithError(Message::ID_NOT_FOUND, 404);

            if ($denied = $this->denyIfProtectedTarget($auth))
                return $denied;

            if ($auth->status === $newStatus)
                return $this->respondWithError('Account is already in the requested status', 400);

            return $this->withTransaction(function() use ($auth, $newStatus, $successMessage, $failureMessage) {
                $auth->status = $newStatus;
                if ($newStatus !== Status::ACTIVE) {
                    $auth->clearToken();
                }

                if (!$auth->save())
                    return $this->respondWithError($failureMessage, 409);

                return $this->respondWithSuccess([
                    'message' => $successMessage
                ], 202, $successMessage);
            });

        } catch (Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Managers may not modify administrator accounts, and nobody may change their own role/status
     * through the management endpoints.
     */
    private function denyIfProtectedTarget(Auth $target): ?array {
        if ((int)$target->id === $this->getCurrentUserId())
            return $this->respondWithError('You cannot change your own account status or role', 403);

        if ($target->role === Role::ADMINISTRATOR && !$this->isAdmin())
            return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 403);

        return null;
    }

    /**
     * Activate a user account from email
     */
    public function emailActivation(string $edoc): Response{
        try {
            // Create response object
            $response = new \Phalcon\Http\Response();
            $response->setContentType('text/html', 'UTF-8');

            $code = strrev($edoc);
            $auth = Auth::findFirst([
                'conditions' => 'password = :code: AND status = :status:',
                'bind' => [
                    'code' => $code,
                    'status' => Status::NOT_VERIFIED
                ]
            ]);

            if (!$auth) {
                $htmlResponse = $this->getActivationResponseHtml(false, 'Invalid activation code or account already activated');
                $response->setContent($htmlResponse);
                return $response;
            }

            $auth->status = Status::ACTIVE;

            if (!$auth->save()) {
                $htmlResponse = $this->getActivationResponseHtml(false, 'Failed to activate account. Please try again.');
                $response->setContent($htmlResponse);
                return $response;
            }

            // Return success HTML
            $htmlResponse = $this->getActivationResponseHtml(true, 'Your account has been successfully activated! You can now log in.');
            $response->setContent($htmlResponse);

            return $response;

        } catch (Exception $e) {
            $this->handleException($e);
            $response = new \Phalcon\Http\Response();
            $response->setContentType('text/html', 'UTF-8');
            $response->setContent($this->getActivationResponseHtml(false, 'An unexpected error occurred. Please try again later.'));
            return $response;
        }
    }

    /**
     * Renew user's authentication token
     */
    public function renewToken(): array {
        try {
            $userId = $this->getCurrentUserId();
            $auth = $this->getAuthUserById($userId);

            if (!$auth)  return $this->respondWithError(Message::INVALID_CREDENTIALS, 401);

            // Token service should now be available through DI
            $tokenService = $this->getDI()->get('tokenService');
            $newExpiration = $tokenService->generateTokenExpirationTime();
            $newToken = $tokenService->createToken($auth, $newExpiration);

            $user = null;
            $u = User::findFirstById($auth->id);
            if($u){
                $user = [
                    User::FULLNAME => $u->firstname . ' ' . $u->lastname,
                    User::PHOTO => $u->photo,
                ];
            }

            return $this->withTransaction(function() use ($auth, $user, $newToken, $newExpiration) {
                $auth->token = $newToken;
                $auth->expiration = $newExpiration;

                if (!$auth->save()) return $this->respondWithError(Message::TOKEN_INVALID, 409);

                return $this->respondWithSuccess([
                    Auth::TOKEN => $newToken,
                    'user' => $user
                ]);
            });

        } catch (Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Change a user's role.
     * Body: { "id": <account id>, "role": 0|1|2 }
     * Rules: manager/admin; only administrators may grant the administrator role or modify an
     * administrator; nobody can change their own role. The affected session token is revoked.
     */
    public function changeRole(): array {
        try {
            if (!$this->isManagerOrHigher())
                return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 403);

            $data = $this->getRequestBody();

            if (empty($data[Auth::ID]) || !isset($data[Auth::ROLE]))
                return $this->respondWithError(Message::USER_ID_ROLE_REQUIRED, 400);

            $userRole = (int)$data[Auth::ROLE];

            // Validate role
            if (!in_array($userRole, [Role::ADMINISTRATOR, Role::MANAGER, Role::CAREGIVER], true))
                return $this->respondWithError(Message::ROLE_INVALID, 400);

            if ($userRole === Role::ADMINISTRATOR && !$this->isAdmin())
                return $this->respondWithError('Only administrators can grant the administrator role', 403);

            $auth = Auth::findFirstById((int)$data[Auth::ID]);

            if (!$auth)
                return $this->respondWithError(Message::USER_NOT_FOUND, 404);

            if ($denied = $this->denyIfProtectedTarget($auth))
                return $denied;

            if ($auth->role === $userRole)
                return $this->respondWithError('User already has this role', 400);

            return $this->withTransaction(function() use ($auth, $userRole) {
                $auth->role = $userRole;
                $auth->clearToken(); // force a new login so the client picks up the new role

                if (!$auth->save())
                    return $this->respondWithError(Message::ROLE_CHANGE_FAILED, 409);

                return $this->respondWithSuccess([
                    'message' => Message::ROLE_CHANGED
                ], 202, Message::ROLE_CHANGED);
            });

        } catch (Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Change a password (authenticated).
     *  - Own password:      { "current_password": "...", "password": "..." }
     *  - Another account:   { "username": "..." | "id": n, "password": "..." }  (administrators only)
     * The affected account's session token is revoked; the user has to log in again.
     * A forgot-password flow (unauthenticated, via emailed single-use token) is tracked separately.
     */
    public function changePassword(): array {
        try {
            $data = $this->getRequestBody();

            $newPassword = $data[Auth::PASSWORD] ?? '';
            if (!is_string($newPassword) || strlen($newPassword) < self::MIN_PASSWORD_LENGTH)
                return $this->respondWithError('New password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters', 400);

            $current = Auth::findFirstById($this->getCurrentUserId());
            if (!$current)
                return $this->respondWithError(Message::INVALID_CREDENTIALS, 401);

            $targetUsername = isset($data[Auth::USERNAME]) ? (string)$data[Auth::USERNAME] : null;
            $targetId = isset($data[Auth::ID]) ? (int)$data[Auth::ID] : null;
            $isSelf = ($targetUsername === null && $targetId === null)
                || ($targetUsername !== null && strcasecmp($targetUsername, $current->username) === 0)
                || ($targetId !== null && $targetId === (int)$current->id);

            if ($isSelf) {
                $currentPassword = $data['current_password'] ?? '';
                if (!is_string($currentPassword) || $currentPassword === '' || !$current->isCorrect($currentPassword))
                    return $this->respondWithError('Current password is incorrect', 403);
                $auth = $current;
            } else {
                if (!$this->isAdmin())
                    return $this->respondWithError('Only administrators can change another user\'s password', 403);

                $auth = $targetId !== null ? Auth::findFirstById($targetId) : Auth::findFirstByUsername($targetUsername);
                if (!$auth)
                    return $this->respondWithError(Message::ACCOUNT_NOT_FOUND, 404);
            }

            return $this->withTransaction(function() use ($auth, $newPassword) {
                $auth->setPassword($newPassword);
                $auth->clearToken();

                if (!$auth->save())
                    return $this->respondWithError(Message::PASSWORD_CHANGE_FAILED, 409);

                $message = Message::PASSWORD_CHANGED . '. Please log in again';
                return $this->respondWithSuccess(['message' => $message], 202, $message);
            });

        } catch (Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Get all auth records (admin only)
     */
    public function getAuths(): array {
        try {
            if (!$this->isAdmin()) {
                return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 403);
            }

            $auth = Auth::find();

            if (!$auth) {
                return $this->respondWithError(Message::DB_QUERY_FAILED, 500);
            }

            if ($auth->count() === 0) {
                return $this->respondWithSuccess(Message::DB_NO_RECORDS, 204, Message::DB_NO_RECORDS);
            }

            return $this->respondWithSuccess($auth->toArray());

        } catch (Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Process login
     */
    private function processLogin(array $data): array {
        if (empty($data[Auth::USERNAME]) || empty($data[Auth::PASSWORD]))
            return $this->respondWithError(Message::CREDENTIALS_REQUIRED, 400);

        $auth = Auth::findFirstByUsername($data[Auth::USERNAME]);

        if (!$auth || !$auth->isCorrect((string)$data[Auth::PASSWORD]))
            return $this->respondWithError(Message::INVALID_CREDENTIALS, 401);

        if (!$auth->isActive())
            return $this->respondWithError(Message::ACCOUNT_NOT_ACTIVATED, 403);

        $tokenService = $this->getDI()->get('tokenService');
        $expiration = $tokenService->generateTokenExpirationTime();
        $token = $tokenService->createToken($auth, $expiration);

        $user = null;
        $u = User::findFirstById($auth->id);
        if($u){
            $user = [
                User::FULLNAME => $u->firstname . ' ' . $u->lastname,
                User::PHOTO => $u->photo,
            ];
        }

        return $this->withTransaction(function() use ($auth, $user, $token, $expiration) {
            $auth->token = $token;
            $auth->expiration = $expiration;

            if (!$auth->save())
                return $this->respondWithError(Message::DB_SESSION_UPDATE_FAILED, 500);

            return $this->respondWithSuccess([
                Auth::TOKEN => $token,
                'user' => $user
            ]);
        });
    }

    /**
     * Send Activation code email
     */
    private function sendActivationEmail(string $address, string $code): bool {

        $apiBaseUrl = $this->apiBaseUrl;

        $subject = 'Activate Maxmila Account';
        $edoc= strrev($code);
        $link = "$apiBaseUrl/activation/$edoc";
        $body = "<h1>Welcome to Maxmila Homecare!</h1>";
        $body .= "<p>Please click the link below to activate your account:</p>";
        $body .= "<a href=\"$link\">Click Here to Activate your Account</a>";
        $body .= "<p>This link will expire in 72 hours.</p>";
        $body .= "<p>If you receive this email by mistake, you can safely ignore this email.</p>";
        $body .= "<h3>Thank you</h3>";
        $body .= "<h4>Maxmila Homecare & Trinketronix Copyright " . date('Y') . "</h4>";

        $result = $this->processEmail($address, $subject, $body, true);

        $success = $result['success'] ?? false;
        $message = $result['message'] ?? 'no result';

        if($success) return true;
        else {
            error_log("AuthController->sendActivationEmail(): fail $message");
            return false;}
    }

    /**
     * Generate HTML for activation response
     *
     * @param bool $success Whether activation was successful
     * @param string $message Message to display (escaped here)
     * @return string HTML content
     */
    private function getActivationResponseHtml(bool $success, string $message): string
    {
        $appBaseUrl = htmlspecialchars($this->appBaseUrl, ENT_QUOTES, 'UTF-8');
        $message = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        $title = $success ? 'Account Activated' : 'Activation Failed';
        $color = $success ? '#4CAF50' : '#F44336';
        $appName = htmlspecialchars(\Api\Constants\Api::NAME, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title} - {$appName}</title>
    <style>
        body {
            font-family: 'Arial', sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
            background-color: #f5f5f5;
        }
        .container {
            background: white;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            text-align: center;
            max-width: 500px;
        }
        h1 {
            color: {$color};
            margin-bottom: 20px;
        }
        p {
            font-size: 18px;
            line-height: 1.6;
            color: #555;
        }
        .logo {
            margin-bottom: 20px;
        }
        .button {
            display: inline-block;
            background-color: #007BFF;
            color: white;
            padding: 12px 24px;
            text-decoration: none;
            border-radius: 4px;
            font-weight: bold;
            margin-top: 20px;
            transition: background-color 0.3s;
        }
        .button:hover {
            background-color: #0056b3;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo">
            <h2>{$appName}</h2>
        </div>
        <h1>{$title}</h1>
        <p>{$message}</p>
        <a href="{$appBaseUrl}/signin" class="button">Go to Login</a>
    </div>
</body>
</html>
HTML;
    }
}
