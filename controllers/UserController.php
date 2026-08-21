<?php

declare(strict_types=1);

namespace Api\Controllers;

use Api\Constants\Message;
use Api\Constants\PersonType;
use Api\Constants\Role;
use Api\Encoding\Base64;
use Api\Models\Address;
use Api\Models\User;
use Exception;

class UserController extends BaseController {
    /**
     * Update a user's information
     */
    public function updateUser(int $userId): array {
        try {
            // Get current user from authenticated token
            $tokenUserId = $this->getCurrentUserId();
            $currentUserRole = $this->getCurrentUserRole();

            // Find user to update
            $user = User::findFirst($userId);
            if (!$user) {
                return $this->respondWithError(Message::USER_NOT_FOUND, 404);
            }

            // Authorization check: allow updates only for own data or if admin/manager
            if ($tokenUserId !== $userId && !$this->isManagerOrHigher()) {
                return $this->respondWithError(Message::UNAUTHORIZED_ACCESS, 403);
            }

            $data = $this->getRequestBody();

            // Define updatable fields based on model
            $allowedFields = [
                // Personal information
                User::LASTNAME,
                User::FIRSTNAME,
                User::MIDDLENAME,
                User::BIRTHDATE,
                // Professional information
                User::CODE,
                // Contact information
                User::PHONE,
                User::PHONE2,
                User::EMAIL,
                User::EMAIL2,
                // Additional information
                User::LANGUAGES,
                User::DESCRIPTION
                // photo is managed exclusively through the upload endpoints
            ];

            // Track all updates
            $updates = [];

            return $this->withTransaction(function() use ($user, $data, $allowedFields, $updates, $tokenUserId, $currentUserRole, $userId) {
                // Apply updates for allowed fields
                foreach ($allowedFields as $field) {
                    if (isset($data[$field])) {
                        $oldValue = $user->$field;
                        $user->$field = $data[$field];
                        $updates[$field] = [
                            'from' => $oldValue,
                            'to' => $data[$field]
                        ];
                    }
                }

                // Handle SSN update - special case with encryption
                if (isset($data[User::SSN])) {
                    // Validate and normalize SSN format
                    $ssn = preg_replace('/[^0-9]/', '', $data[User::SSN]);

                    // Validate that we have exactly 9 digits
                    if (strlen($ssn) !== 9) {
                        return $this->respondWithError(Message::SSN_INVALID, 400);
                    }

                    try {
                        $oldSsn = $user->ssn ? Base64::decodingSaltedPeppered($user->ssn) : null;
                        $user->ssn = Base64::encodingSaltedPeppered($ssn);
                        $updates[User::SSN] = [
                            'from' => $oldSsn,
                            'to' => $ssn
                        ];
                    } catch (Exception $e) {
                        $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
                        error_log('Exception: ' . $message);
                        return $this->respondWithError(Message::SSN_PROCESSING_ERROR, 500);
                    }
                }

                // Save the user
                if (!$user->save()) {
                    $messages = $user->getMessages(); // This is Phalcon\Messages\MessageInterface[]
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

                // Prepare response data
                $responseData = $user->toArray();

                // Show SSN only if user is updating their own data or is admin/manager
                if ($tokenUserId !== $userId && !$this->isManagerOrHigher()) {
                    unset($responseData[User::SSN]);
                } else if (!empty($responseData[User::SSN])) {
                    $responseData[User::SSN] = Base64::decodingSaltedPeppered($responseData[User::SSN]);
                }

                return $this->respondWithSuccess([
                    'message' => Message::USER_UPDATED,
                    'user' => $responseData,
                    'updates' => $updates
                ], 201, Message::USER_UPDATED);
            });

        } catch (Exception $e) {
            $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
            error_log('Exception: ' . $message);
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Get user profile by ID
     */
    public function getUser(?int $userId = null): array {
        try {
            // Get the current user's ID
            $currentUserId = $this->getCurrentUserId();
            $currentUserRole = $this->getCurrentUserRole();

            // If no userId provided, use current user's ID
            if ($userId === null) {
                $userId = $currentUserId;
            }

            // Find user
            $user = User::findWithAuth($userId);
            if (!$user) {
                return $this->respondWithError(Message::USER_NOT_FOUND, 404);
            }

            // Authorization check: only allow viewing other profiles if admin/manager
            if ($currentUserId !== $userId && !$this->isManagerOrHigher()) {
                return $this->respondWithError(Message::UNAUTHORIZED_ACCESS, 403);
            }

            // Prepare user data
            $userData = $user->toArray();

            // Process SSN if present
            if (isset($userData[User::SSN]) && !empty($userData[User::SSN])) {
                try {
                    $userData[User::SSN] = Base64::decodingSaltedPeppered($userData[User::SSN]);
                } catch (Exception $e) {
                    $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
                    error_log('Exception: ' . $message);
                    // If there's an error decoding, just remove it from response
                    unset($userData[User::SSN]);
                }
            }

            // Get user addresses
            $addresses = Address::findByPerson($userId, PersonType::USER);
            if ($addresses && $addresses->count() > 0) {
                $userData['addresses'] = $addresses->toArray();
            } else {
                $userData['addresses'] = [];
            }

            return $this->respondWithSuccess($userData);

        } catch (Exception $e) {
            $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
            error_log('Exception: ' . $message);
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Upload a new profile photo (own photo, or any user's photo when manager/admin)
     */
    public function uploadPhoto(?int $userId = null): array {
        return $this->savePhoto($userId, 'uploaded_by');
    }

    /**
     * Replace a profile photo. Same rules as uploadPhoto; kept as a separate route for the clients.
     */
    public function updatePhoto(?int $userId = null): array {
        return $this->savePhoto($userId, 'updated_by');
    }

    /**
     * Shared implementation of uploadPhoto/updatePhoto.
     * Type detection, extension and directory protection are handled by BaseController::storeUploadedPhoto().
     */
    private function savePhoto(?int $userId, string $actorKey): array {
        try {
            $currentUserId = $this->getCurrentUserId();
            $userId = $userId ?? $currentUserId;

            if ($userId !== $currentUserId && !$this->isManagerOrHigher()) {
                return $this->respondWithError(Message::UNAUTHORIZED_ACCESS, 403);
            }

            if (!$this->request->hasFiles()) {
                return $this->respondWithError(Message::UPLOAD_NO_FILES, 400);
            }

            $user = User::findFirst($userId);
            if (!$user) {
                return $this->respondWithError(Message::USER_NOT_FOUND, 404);
            }

            $photo = $this->request->getUploadedFiles()[0];
            $baseName = sprintf('%d-%s-%s', $userId, $user->firstname, $user->lastname);

            return $this->withTransaction(function() use ($user, $photo, $baseName, $currentUserId, $userId, $actorKey) {
                $stored = $this->storeUploadedPhoto($photo, User::PATH_PHOTO_FILE, $baseName);
                if (is_string($stored)) {
                    return $this->respondWithError($stored, $stored === Message::UPLOAD_INVALID_TYPE ? 400 : 500);
                }

                $previousPhoto = $user->photo;
                $user->photo = $stored['path'];

                if (!$user->save()) {
                    $this->deleteStoredPhoto($stored['path'], User::PATH_PHOTO_FILE, User::DEFAULT_PHOTO_FILE, (string)$previousPhoto);
                    return $this->respondWithError($this->getFirstErrorMessage($user), 422);
                }

                $this->deleteStoredPhoto($previousPhoto, User::PATH_PHOTO_FILE, User::DEFAULT_PHOTO_FILE, $stored['path']);

                $by = ($currentUserId !== $userId) ? " by user ID: $currentUserId" : "";
                return $this->respondWithSuccess([
                    'message' => Message::UPLOAD_PHOTO_SUCCESS . $by,
                    'path' => $stored['path'],
                    'filename' => $stored['filename'],
                    'user_id' => $userId,
                    $actorKey => $currentUserId,
                    'processed' => true
                ], 201, Message::UPLOAD_PHOTO_SUCCESS);
            });

        } catch (Exception $e) {
            return $this->handleException($e);
        }
    }

    public function getPhoto(?int $userId = null): array {
        try {
            // Get current user ID
            $currentUserId = $this->getCurrentUserId();

            // If no ID provided in URL or empty string, use current user ID
            if ($userId === null || $userId === '') {
                $userId = $currentUserId;
            } else {
                $userId = (int)$userId;
            }

            // If requesting another user's account, check authorization
            if ($userId !== $currentUserId && !$this->isManagerOrHigher()) {
                return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 403);
            }

            // Find user in UserAuthView
            $user = User::findFirst([
                'conditions' => 'id = :id:',
                'bind' => [User::ID => $userId],
                'bindTypes' => [User::ID => \PDO::PARAM_INT]
            ]);

            if (!$user) {
                return $this->respondWithError(Message::USER_NOT_FOUND, 404);
            }

            $userPhoto = $user->photo;
            $userData[User::PHOTO] = $userPhoto;

            return $this->respondWithSuccess($userData);

        } catch (Exception $e) {
            $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
            error_log('Exception: ' . $message);
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }
}