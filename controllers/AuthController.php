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
    private $apiBaseUrl = API_BASE_URL;
    private $appBaseUrl = APP_BASE_URL;
    /**
     * Create a new user account
     */
    public function register(): array {
        try {

            $data = $this->getRequestBody();

            // Validate required fields
            if (empty($data[Auth::USERNAME]) || empty($data[Auth::PASSWORD]))
                return $this->respondWithError(Message::CREDENTIALS_REQUIRED, 400);

            // Validate email format
            if (!filter_var($data[Auth::USERNAME], FILTER_VALIDATE_EMAIL))
                return $this->respondWithError(Message::EMAIL_INVALID, 400);

            // Execute within transaction
            return $this->withTransaction(function() use ($data) {
                // Create new user record
                $auth = new Auth();
                $auth->username = $data[Auth::USERNAME];
                $auth->setPassword($data[Auth::PASSWORD]);
                $auth->role = Role::CAREGIVER;
                $auth->status = Status::NOT_VERIFIED;

                if (!$auth->save()) {
                    $messages = $auth->getMessages(); // This is Phalcon\Messages\MessageInterface[]
                    $msg = "An unknown error occurred."; // Default/fallback

                    if (count($messages) > 0) {
                        // Get the first message object from the array
                        $obj = $messages[0]; // or current($phalconMessages)

                        // Extract the string message from the object
                        // The MessageInterface guarantees the getMessage() method.
                        $msg = $obj->getMessage();
                    }

                    // Pass the extracted string message to your responder
                    return $this->respondWithError($msg, 422);
                }

                if (!$auth->id)
                    return $this->respondWithError(Message::DB_ID_GENERATION_FAILED, 422);

                if (!User::createTemplate($auth->id, $auth->username))
                    return $this->respondWithError(Message::DB_SESSION_UPDATE_FAILED, 422);

                if(!$this->sendActivationEmail($auth->username, $auth->password))
                    return $this->respondWithSuccess(Message::USER_CREATED." activation email fail", 201, Message::USER_CREATED." activation email fail");

                return $this->respondWithSuccess(Message::USER_CREATED." and ". Message::EMAIL_ACTIVATION_SENT . ' to: ' . $auth->username, 201, Message::USER_CREATED." and ". Message::EMAIL_ACTIVATION_SENT . ' to: ' . $auth->username);
            });

        } catch (Exception $e) {
            $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
            error_log('Exception: ' . $message);
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
            $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
            error_log('Exception: ' . $message);
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Activate a user account from the system
     */
    public function activateAccount(): array {
        try {
            // Role validation should now be done by middleware
            // If we need additional role checking for this specific operation:
            if (!$this->isManagerOrHigher())
                return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 403);

            $data = $this->getRequestBody();

            if (empty($data[Auth::ID]))
                return $this->respondWithError(Message::ID_REQUIRED, 400);

            $id = $data[Auth::ID];
            $auth = Auth::findFirstById($id);

            if (!$auth)
                return $this->respondWithError(Message::ID_NOT_FOUND, 404);

            return $this->withTransaction(function() use ($auth) {
                $auth->status = Status::ACTIVE;

                if (!$auth->save())
                    return $this->respondWithError(Message::USER_ACTIVATION_FAILED, 409);

                return $this->respondWithSuccess([
                    'message' => Message::USER_ACTIVATED
                ], 202, Message::USER_ACTIVATED);
            });

        } catch (Exception $e) {
            $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
            error_log('Exception: ' . $message);
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Inactivate a user account from the system
     */
    public function inactivateAccount(): array {
        try {
            // Role validation should now be done by middleware
            // If we need additional role checking for this specific operation:
            if (!$this->isManagerOrHigher())
                return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 403);

            $data = $this->getRequestBody();

            if (empty($data[Auth::ID]))
                return $this->respondWithError(Message::ID_REQUIRED, 400);

            $id = $data[Auth::ID];
            $auth = Auth::findFirstById($id);

            if (!$auth)
                return $this->respondWithError(Message::ID_NOT_FOUND, 404);

            return $this->withTransaction(function() use ($auth) {
                $auth->status = Status::INACTIVE;

                if (!$auth->save())
                    return $this->respondWithError(Message::USER_INACTIVATION_FAILED, 409);

                return $this->respondWithSuccess([
                    'message' => Message::USER_INACTIVATED
                ], 202, Message::USER_INACTIVATED);
            });

        } catch (Exception $e) {
            $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
            error_log('Exception: ' . $message);
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }


    /**
     * Inactivate a user account from the system
     */
    public function archivateAccount(): array {
        try {
            // Role validation should now be done by middleware
            // If we need additional role checking for this specific operation:
            if (!$this->isManagerOrHigher())
                return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 403);

            $data = $this->getRequestBody();

            if (empty($data[Auth::ID]))
                return $this->respondWithError(Message::ID_REQUIRED, 400);

            $id = $data[Auth::ID];
            $auth = Auth::findFirstById($id);

            if (!$auth)
                return $this->respondWithError(Message::ID_NOT_FOUND, 404);

            return $this->withTransaction(function() use ($auth) {
                $auth->status = Status::ARCHIVED;

                if (!$auth->save())
                    return $this->respondWithError(Message::USER_ARCHIVATION_FAILED, 409);

                return $this->respondWithSuccess([
                    'message' => Message::USER_ARCHIVED
                ], 202, Message::USER_ARCHIVED);
            });

        } catch (Exception $e) {
            $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
            error_log('Exception: ' . $message);
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }


    /**
     * Inactivate a user account from the system
     */
    public function deleteAccount(): array {
        try {
            // Role validation should now be done by middleware
            // If we need additional role checking for this specific operation:
            if (!$this->isManagerOrHigher())
                return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 403);

            $data = $this->getRequestBody();

            if (empty($data[Auth::ID]))
                return $this->respondWithError(Message::ID_REQUIRED, 400);

            $id = $data[Auth::ID];
            $auth = Auth::findFirstById($id);
            if (!$auth)
                return $this->respondWithError(Message::ID_NOT_FOUND, 404);

            // Check if patient is already deleted
            if ($auth->isDeleted()) {
                return $this->respondWithError('This account is already deleted', 400);
            }

            return $this->withTransaction(function() use ($auth) {
                $auth->status = Status::SOFT_DELETED;

                if (!$auth->save())
                    return $this->respondWithError(Message::USER_DELETION_FAILED, 409);

                return $this->respondWithSuccess([
                    'message' => Message::USER_DELETED
                ], 202, Message::USER_DELETED);
            });

        } catch (Exception $e) {
            $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
            error_log('Exception: ' . $message);
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
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
            $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
            error_log('Exception: ' . $message);
            $response = new \Phalcon\Http\Response();
            $response->setContentType('text/html', 'UTF-8');
            $htmlResponse = $this->getActivationResponseHtml(false, 'An error occurred: ' . $e->getMessage());
            $response->setContent($htmlResponse);
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
            $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
            error_log('Exception: ' . $message);
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Change a user's role
     */
    public function changeRole(): array {
        try {
            if (!$this->isManagerOrHigher())
                return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 403);

            $data = $this->getRequestBody();

            if (empty($data[Auth::ID]) || !isset($data[Auth::ROLE]))
                return $this->respondWithError(Message::USER_ID_ROLE_REQUIRED, 405);

            $id = $data[Auth::ID];
            $userRole = (int)$data[Auth::ROLE];

            // Validate role
            if (!in_array($userRole, [Role::ADMINISTRATOR, Role::MANAGER, Role::CAREGIVER]))
                return $this->respondWithError(Message::ROLE_INVALID, 400);

            $auth = Auth::findFirstById($id);

            if (!$auth)
                return $this->respondWithError(Message::USER_NOT_FOUND, 404);

            return $this->withTransaction(function() use ($auth, $userRole) {
                $auth->role = $userRole;

                if (!$auth->save())
                    return $this->respondWithError(Message::ROLE_CHANGE_FAILED, 409);

                return $this->respondWithSuccess([
                    'message' => Message::ROLE_CHANGED
                ], 202, Message::ROLE_CHANGED);
            });

        } catch (Exception $e) {
            $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
            error_log('Exception: ' . $message);
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Change a user's password from system
     */
    public function changePassword(): array {
        try {
            $data = $this->getRequestBody();

            if (empty($data[Auth::USERNAME]) || empty($data[Auth::PASSWORD]))
                return $this->respondWithError('Username and new password is expected', 400);

            $username = $data[Auth::USERNAME];
            $auth = Auth::findFirstByUsername($username);

            if (!$auth)
                return $this->respondWithError(Message::EMAIL_NOT_FOUND, 404);

            return $this->withTransaction(function() use ($auth, $data) {
                $auth->setPassword($data[Auth::PASSWORD]);

                if (!$auth->save())
                    return $this->respondWithError(Message::PASSWORD_CHANGE_FAILED, 409);

                return $this->respondWithSuccess([
                    'message' => Message::PASSWORD_CHANGED . ' ' . Message::PLEASE_RENEW_TOKEN
                ], 202, Message::PASSWORD_CHANGED . ' ' . Message::PLEASE_RENEW_TOKEN);
            });

        } catch (Exception $e) {
            $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
            error_log('Exception: ' . $message);
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
                return $this->respondWithSuccess(Message::DB_NO_RECORDS, 204, Message::DB_NO_RECORDS);
            }

            return $this->respondWithSuccess($auth->toArray());

        } catch (Exception $e) {
            $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
            error_log('Exception: ' . $message);
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Process login
     */
    private function processLogin(array $data): array {
        try {
            if (empty($data[Auth::USERNAME]) || empty($data[Auth::PASSWORD]))
                return $this->respondWithError(Message::CREDENTIALS_REQUIRED, 400);

            $auth = Auth::findFirstByUsername($data[Auth::USERNAME]);

            if (!$auth || !$auth->isCorrect($data[Auth::PASSWORD]))
                return $this->respondWithError(Message::INVALID_CREDENTIALS, 401);

            if (!$auth->isActive())
                return $this->respondWithError(Message::ACCOUNT_NOT_ACTIVATED, 403);

            $tokenService = $this->getDI()->get('tokenService');
            $expiration = $tokenService->generateTokenExpirationTime();
            $token = $tokenService->createToken($auth, $expiration);

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

        } catch (Exception $e) {
            $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
            error_log('Exception: ' . $message);
            if ($this->db->isUnderTransaction()) $this->rollbackTransaction();
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Send Activation code email
     */
    private function sendActivationEmail(string $address, string $code): bool {

        $apiBaseUrl = $this->apiBaseUrl;

        $subject = 'Activate Maxmila Account';
        $edoc= strrev($code);
        $link = "$apiBaseUrl/activation/$edoc";
        $body = "<h1>Welcome to Maxmila Homecare!</h1>>";
        $body .= "<p>Please click the link below to activate your account:</p>";
        $body .= "<a href=\"$link\">Click Here to Activate your Account</a>";
        $body .= "<p>This link will expire in 72 hours.</p>";
        $body .= "<p>If you receive this email by mistake, you can safely ignore this email.</p>";
        $body .= "<h3>Thank you</h3>";
        $body .= "<h4>Maxmila Homecare & Trinketronix Copyright 2025<h4>";

        $result = $this->processEmail($address, $subject, $body, true);

        $success = $result['success'];
        $message = $result['message'];

        if($success) return true;
        else {
            error_log("AuthController->sendActivationEmail(): fail $message");
            return false;}
    }

    /**
     * Generate HTML for activation response
     *
     * @param bool $success Whether activation was successful
     * @param string $message Message to display
     * @return string HTML content
     */
    private function getActivationResponseHtml(bool $success, string $message): string
    {
        $appBaseUrl = $this->appBaseUrl;
        $title = $success ? 'Account Activated' : 'Activation Failed';
        $color = $success ? '#4CAF50' : '#F44336';
        $appName = \Api\Constants\Api::NAME;

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
        <a href="{$appBaseUrl}/login" class="button">Go to Login</a>
    </div>
</body>
</html>
HTML;
    }
}