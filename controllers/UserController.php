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
            if ($tokenUserId !== $userId && $currentUserRole > Role::MANAGER) {
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
                User::DESCRIPTION,
                // Profile media
                User::PHOTO
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
                if ($tokenUserId !== $userId && $currentUserRole > Role::MANAGER) {
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
            if ($currentUserId !== $userId && $currentUserRole > Role::MANAGER) {
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
     * Upload a new profile photo for a user
     * Modified to allow administrators and managers to upload photos for other users
     */
    public function uploadPhoto(?int $userId = null): array {
        try {
            // Get the current user's ID and role
            $currentUserId = $this->getCurrentUserId();
            $currentUserRole = $this->getCurrentUserRole();

            // If no userId provided, use current user's ID
            if ($userId === null) {
                $userId = $currentUserId;
            }

            // Authorization check: allow upload for own photo or if admin/manager
            if ($userId !== $currentUserId && $currentUserRole > Role::MANAGER) {
                return $this->respondWithError(Message::UNAUTHORIZED_ACCESS, 403);
            }

            // Check for files (middleware should already validate multipart form-data)
            if (!$this->request->hasFiles()) {
                return $this->respondWithError(Message::UPLOAD_NO_FILES, 400);
            }

            $files = $this->request->getUploadedFiles();
            $photo = $files[0];

            // Validate file type
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            if (!in_array($photo->getType(), $allowedTypes)) {
                return $this->respondWithError(Message::UPLOAD_INVALID_TYPE, 400);
            }

            // Find and validate user
            $user = User::findFirst($userId);
            if (!$user) {
                return $this->respondWithError(Message::USER_NOT_FOUND, 404);
            }

            // Create photos directory if it doesn't exist
            $uploadDir = User::PATH_PHOTO_FILE;
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            // Generate filename using user info
            $extension = pathinfo($photo->getName(), PATHINFO_EXTENSION);
            $sanitizedFirstname = preg_replace('/[^a-z0-9]/i', '', $user->firstname);
            $sanitizedLastname = preg_replace('/[^a-z0-9]/i', '', $user->lastname);
            $filename = sprintf(
                '%d-%s-%s.%s',
                $userId,
                strtolower($sanitizedFirstname),
                strtolower($sanitizedLastname),
                $extension
            );

            $path = $uploadDir . '/' . $filename;

            return $this->withTransaction(function() use ($user, $photo, $path, $filename, $uploadDir, $currentUserId, $userId) {
                // Get temporary file path
                $tempPath = $photo->getTempName();

                // Process and resize image to 360x360
                if (!$this->processAndResizeImage($tempPath, $path)) {
                    // If ImageMagick fails, fall back to regular file upload
                    if (!$photo->moveTo($path)) {
                        return $this->respondWithError(Message::UPLOAD_PHOTO_FAILED, 500);
                    }
                }

                // Delete old photo if exists and is not the default photo
                $oldPhoto = $uploadDir . '/' . $user->photo;
                if ($user->photo && $user->photo !== User::DEFAULT_PHOTO_FILE && file_exists($oldPhoto)) {
                    unlink($oldPhoto);
                }

                $upath = "/$path";
                $user->photo = $upath;

                if (!$user->save()) {
                    // If save fails, clean up the uploaded file
                    if (file_exists($path)) {
                        unlink($path);
                    }
                    $messages = $user->getMessages();
                    $msg = "An unknown error occurred.";

                    if (count($messages) > 0) {
                        $obj = $messages[0];
                        $msg = $obj->getMessage();
                    }

                    return $this->respondWithError($msg, 422);
                }

                // Add information about who uploaded the photo if it wasn't the user themselves
                $uploadedBy = ($currentUserId !== $userId) ? " by user ID: $currentUserId" : "";

                return $this->respondWithSuccess([
                    'message' => Message::UPLOAD_PHOTO_SUCCESS . $uploadedBy,
                    'path' => $upath,
                    'filename' => $filename,
                    'user_id' => $userId,
                    'uploaded_by' => $currentUserId,
                    'processed' => true // Indicates image was processed with ImageMagick
                ], 201, Message::UPLOAD_PHOTO_SUCCESS);
            });

        } catch (Exception $e) {
            $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
            error_log('Exception: ' . $message);
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Update a user's profile photo
     * Already supports admin/manager updating other users' photos
     */
    public function updatePhoto(?int $userId = null): array {
        try {
            // Get the current user's ID
            $currentUserId = $this->getCurrentUserId();
            $currentUserRole = $this->getCurrentUserRole();

            // If no userId provided, use current user's ID
            if ($userId === null) {
                $userId = $currentUserId;
            }

            // Authorization check
            if ($currentUserId !== $userId && $currentUserRole > Role::MANAGER) {
                return $this->respondWithError(Message::UNAUTHORIZED_ACCESS, 403);
            }

            // Check for files
            if (!$this->request->hasFiles()) {
                return $this->respondWithError(Message::UPLOAD_NO_FILES, 400);
            }

            $files = $this->request->getUploadedFiles();
            $photo = $files[0];

            // Validate file type
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            if (!in_array($photo->getType(), $allowedTypes)) {
                return $this->respondWithError(Message::UPLOAD_INVALID_TYPE, 400);
            }

            // Find user
            $user = User::findFirst($userId);
            if (!$user) {
                return $this->respondWithError(Message::USER_NOT_FOUND, 404);
            }

            // Create photos directory if it doesn't exist
            $uploadDir = User::PATH_PHOTO_FILE;
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            // Generate filename using user info
            $extension = pathinfo($photo->getName(), PATHINFO_EXTENSION);
            $sanitizedFirstname = preg_replace('/[^a-z0-9]/i', '', $user->firstname);
            $sanitizedLastname = preg_replace('/[^a-z0-9]/i', '', $user->lastname);
            $filename = sprintf(
                '%d-%s-%s.%s',
                $userId,
                strtolower($sanitizedFirstname),
                strtolower($sanitizedLastname),
                $extension
            );

            $path = $uploadDir . '/' . $filename;

            return $this->withTransaction(function() use ($user, $photo, $path, $filename, $uploadDir, $currentUserId, $userId) {
                // Get temporary file path
                $tempPath = $photo->getTempName();

                // Process and resize image to 360x360
                if (!$this->processAndResizeImage($tempPath, $path)) {
                    // If ImageMagick fails, fall back to regular file upload
                    if (!$photo->moveTo($path)) {
                        return $this->respondWithError(Message::UPLOAD_FAILED, 500);
                    }
                }

                // Delete old photo if exists and is not the default photo
                $oldPhoto = $uploadDir . '/' . $user->photo;
                if ($user->photo && $user->photo !== User::DEFAULT_PHOTO_FILE && file_exists($oldPhoto)) {
                    unlink($oldPhoto);
                }

                $upath = "/$path";
                $user->photo = $upath;

                if (!$user->save()) {
                    // If save fails, clean up the uploaded file
                    if (file_exists($path)) {
                        unlink($path);
                    }
                    $messages = $user->getMessages();
                    $msg = "An unknown error occurred.";

                    if (count($messages) > 0) {
                        $obj = $messages[0];
                        $msg = $obj->getMessage();
                    }

                    return $this->respondWithError($msg, 422);
                }

                // Add information about who updated the photo if it wasn't the user themselves
                $updatedBy = ($currentUserId !== $userId) ? " by user ID: $currentUserId" : "";

                return $this->respondWithSuccess([
                    'message' => Message::UPLOAD_PHOTO_SUCCESS . $updatedBy,
                    'path' => $upath,
                    'filename' => $filename,
                    'user_id' => $userId,
                    'updated_by' => $currentUserId,
                    'processed' => true
                ], 201, Message::UPLOAD_PHOTO_SUCCESS);
            });

        } catch (Exception $e) {
            $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
            error_log('Exception: ' . $message);
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
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