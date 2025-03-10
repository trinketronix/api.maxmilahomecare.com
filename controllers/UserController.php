<?php

declare(strict_types=1);

namespace Api\Controllers;

use Api\Constants\Message;
use Api\Constants\Role;
use Api\Encoding\Base64;
use Api\Models\Address;
use Api\Models\User;
use Exception;
use JsonException;

class UserController extends BaseController
{
    /**
     * Update a user's information
     */
    public function updateUser(int $userId): array
    {
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
                        return $this->respondWithError(Message::SSN_PROCESSING_ERROR, 500);
                    }
                }

                // Save the user
                if (!$user->save()) {
                    return $this->respondWithError($user->getMessages(), 422);
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
                ]);
            });

        } catch (Exception $e) {
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Update multiple users at once
     */
    public function updateBulkUsers(): array
    {
        try {
            // Only allow admin/manager to perform bulk updates
            if (!$this->isManagerOrHigher()) {
                return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 403);
            }

            // Get users array from request body
            $usersData = $this->getRequestBody();

            // Validate that we received an array
            if (!is_array($usersData)) {
                return $this->respondWithError("Request body must be a JSON array", 400);
            }

            $results = [
                'success' => [],
                'failed' => []
            ];

            // Begin transaction for all updates
            $this->beginTransaction();

            try {
                foreach ($usersData as $index => $data) {
                    try {
                        // Validate required ID field
                        if (empty($data['id'])) {
                            $results['failed'][] = [
                                'index' => $index,
                                'error' => 'Missing ID field'
                            ];
                            continue;
                        }

                        // Find user
                        $user = User::findFirst($data['id']);
                        if (!$user) {
                            $results['failed'][] = [
                                'index' => $index,
                                'id' => $data['id'],
                                'error' => 'User not found'
                            ];
                            continue;
                        }

                        // Define updatable fields
                        $fields = [
                            'lastname', 'firstname', 'middlename', 'birthdate',
                            'ssn', 'code', 'phone', 'phone2', 'email', 'email2',
                            'languages', 'description', 'photo'
                        ];

                        // Update fields
                        foreach ($fields as $field) {
                            if (isset($data[$field])) {
                                // Special handling for SSN - assume it's coming non-encoded from backup
                                if ($field === 'ssn' && !empty($data[$field])) {
                                    $user->$field = Base64::encodingSaltedPeppered($data[$field]);
                                } else {
                                    $user->$field = $data[$field];
                                }
                            }
                        }

                        // Save changes
                        if (!$user->save()) {
                            $results['failed'][] = [
                                'index' => $index,
                                'id' => $data['id'],
                                'error' => $user->getMessages()
                            ];
                            continue;
                        }

                        $results['success'][] = [
                            'index' => $index,
                            'id' => $data['id']
                        ];

                    } catch (Exception $e) {
                        $results['failed'][] = [
                            'index' => $index,
                            'id' => $data['id'] ?? 'unknown',
                            'error' => $e->getMessage()
                        ];
                        continue;
                    }
                }

                // If no records were updated successfully, rollback
                if (empty($results['success'])) {
                    $this->rollbackTransaction();
                    return $this->respondWithError([
                        'message' => 'No records were updated',
                        'details' => $results['failed']
                    ], 422);
                }

                // Commit all successful updates
                $this->commitTransaction();

                return $this->respondWithSuccess([
                    'message' => count($results['success']) . ' users updated successfully',
                    'results' => $results
                ]);

            } catch (Exception $e) {
                $this->rollbackTransaction();
                throw $e;
            }

        } catch (Exception $e) {
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Upload a new profile photo for a user
     */
    public function uploadPhoto(): array
    {
        try {
            // Get the current user's ID
            $userId = $this->getCurrentUserId();

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

            return $this->withTransaction(function() use ($user, $photo, $path, $filename) {
                // Delete old photo if exists and is not the default photo
                if ($user->photo && $user->photo !== User::DEFAULT_PHOTO_FILE && file_exists($user->photo)) {
                    unlink($user->photo);
                }

                if ($photo->moveTo($path)) {
                    $upath = "/$path";
                    $user->photo = $upath;

                    if (!$user->save()) {
                        // If save fails, clean up the uploaded file
                        if (file_exists($path)) {
                            unlink($path);
                        }
                        return $this->respondWithError($user->getMessages(), 422);
                    }

                    return $this->respondWithSuccess([
                        'message' => Message::UPLOAD_PHOTO_SUCCESS,
                        'path' => $upath,
                        'filename' => $filename
                    ]);
                }

                return $this->respondWithError(Message::UPLOAD_PHOTO_FAILED, 500);
            });

        } catch (Exception $e) {
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Update a user's profile photo
     */
    public function updatePhoto(int $userId = null): array
    {
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

            return $this->withTransaction(function() use ($user, $photo, $path, $filename) {
                // Delete old photo if exists and is not the default photo
                if ($user->photo && $user->photo !== User::DEFAULT_PHOTO_FILE && file_exists($user->photo)) {
                    unlink($user->photo);
                }

                if ($photo->moveTo($path)) {
                    $upath = "/$path";
                    $user->photo = $upath;

                    if (!$user->save()) {
                        // If save fails, clean up the uploaded file
                        if (file_exists($path)) {
                            unlink($path);
                        }
                        return $this->respondWithError($user->getMessages(), 422);
                    }

                    return $this->respondWithSuccess([
                        'message' => Message::UPLOAD_PHOTO_SUCCESS,
                        'path' => $upath,
                        'filename' => $filename
                    ]);
                }

                return $this->respondWithError(Message::UPLOAD_FAILED, 500);
            });

        } catch (Exception $e) {
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Get user profile by ID
     */
    public function getUser(int $userId = null): array
    {
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
                    // If there's an error decoding, just remove it from response
                    unset($userData[User::SSN]);
                }
            }

            // Get user addresses
            $addresses = Address::findByPerson($userId, Address::PERSON_TYPE_USER);
            if ($addresses && $addresses->count() > 0) {
                $userData['addresses'] = $addresses->toArray();
            } else {
                $userData['addresses'] = [];
            }

            return $this->respondWithSuccess($userData);

        } catch (Exception $e) {
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }
}