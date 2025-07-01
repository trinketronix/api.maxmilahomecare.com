<?php

declare(strict_types=1);

namespace Api\Controllers;

use Api\Constants\PersonType;
use Api\Constants\Role;
use Api\Constants\Status;
use Exception;
use Api\Models\Patient;
use Api\Models\Address;
use Api\Constants\Message;

class PatientController extends BaseController {
    /**
     * Create a new patient
     */
    public function create(): array {
        try {
            // Verify user has permission to create patients (manager or higher)
            if (!$this->isManagerOrHigher())
                return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 403);

            $data = $this->getRequestBody();

            // Validate required fields
            $requiredFields = [
                Patient::FIRSTNAME => 'First name is required',
                Patient::LASTNAME => 'Last name is required',
                Patient::PHONE => 'Phone number is required'
            ];

            foreach ($requiredFields as $field => $message) {
                if (empty($data[$field]))
                    return $this->respondWithError($message, 400);
            }

            // Create patient within transaction
            return $this->withTransaction(function() use ($data) {
                $patient = new Patient();

                // Set required fields
                $patient->firstname = $data[Patient::FIRSTNAME];
                $patient->lastname = $data[Patient::LASTNAME];
                $patient->phone = $data[Patient::PHONE];

                // Set optional fields if provided
                if (isset($data[Patient::MIDDLENAME]))
                    $patient->middlename = $data[Patient::MIDDLENAME];

                if (isset($data[Patient::PATIENT_ID]))
                    $patient->patient = $data[Patient::PATIENT_ID];

                if (isset($data[Patient::ADMISSION]))
                    $patient->admission = $data[Patient::ADMISSION];

                // Default to active status
                $patient->status = Status::ACTIVE;

                if (!$patient->save()) {
                    $messages = $patient->getMessages(); // This is Phalcon\Messages\MessageInterface[]
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

                // If address data is included, create an address for the patient
                if (isset($data['address']) && is_array($data['address'])) {
                    $addressData = $data['address'];

                    // Check for required address fields
                    $requiredAddressFields = [
                        Address::TYPE,
                        Address::ADDRESS,
                        Address::CITY,
                        Address::COUNTY,
                        Address::STATE,
                        Address::ZIPCODE
                    ];

                    $missingFields = [];
                    foreach ($requiredAddressFields as $field) {
                        if (empty($addressData[$field])) {
                            $missingFields[] = $field;
                        }
                    }

                    if (!empty($missingFields)) {
                        return $this->respondWithSuccess([
                            'message' => 'Patient created but address was incomplete',
                            'patient_id' => $patient->id,
                            'missing_address_fields' => $missingFields
                        ], 201, 'Patient created but address was incomplete');
                    }

                    $address = new Address();
                    $address->person_id = $patient->id;
                    $address->person_type = PersonType::PATIENT;
                    $address->type = $addressData[Address::TYPE];
                    $address->address = $addressData[Address::ADDRESS];
                    $address->city = $addressData[Address::CITY];
                    $address->county = $addressData[Address::COUNTY];
                    $address->state = strtoupper($addressData[Address::STATE]);
                    $address->zipcode = $addressData[Address::ZIPCODE];

                    if (isset($addressData[Address::COUNTRY])) {
                        $address->country = $addressData[Address::COUNTRY];
                    }

                    if (isset($addressData[Address::LATITUDE]) && isset($addressData[Address::LONGITUDE])) {
                        $address->latitude = (float)$addressData[Address::LATITUDE];
                        $address->longitude = (float)$addressData[Address::LONGITUDE];
                    }

                    if (!$address->save()) {
                        return $this->respondWithSuccess([
                            'message' => 'Patient created but address save failed',
                            'patient_id' => $patient->id,
                            'address_errors' => $address->getMessages()
                        ], 201, 'Patient created but address save failed');
                    }

                    return $this->respondWithSuccess([
                        'message' => 'Patient created with address',
                        'patient_id' => $patient->id,
                        'address_id' => $address->id
                    ], 201, 'Patient created with address');
                }

                return $this->respondWithSuccess([
                    'message' => 'Patient created successfully',
                    'patient_id' => $patient->id
                ], 201, 'Patient created successfully');
            });

        } catch (Exception $e) {
            $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
            error_log('Exception: ' . $message);
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Update an existing patient
     */
    public function updatePatient(int $id): array {
        try {
            // Verify user has permission to update patients
            if (!$this->isManagerOrHigher()) {
                return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 403);
            }

            // Find patient
            $patient = Patient::findFirstById($id);
            if (!$patient) {
                return $this->respondWithError(Message::PATIENT_NOT_FOUND, 404);
            }

            // Check if patient is deleted
            if ($patient->isDeleted()) {
                return $this->respondWithError('Cannot update a deleted patient', 400);
            }

            $data = $this->getRequestBody();

            // Update patient within transaction
            return $this->withTransaction(function() use ($patient, $data) {
                // Update fields if provided
                $updateableFields = [
                    Patient::FIRSTNAME,
                    Patient::MIDDLENAME,
                    Patient::LASTNAME,
                    Patient::PHONE,
                    Patient::PATIENT_ID,
                    Patient::ADMISSION,
                    Patient::STATUS
                ];

                foreach ($updateableFields as $field) {
                    if (isset($data[$field])) {
                        // Validate status values
                        if ($field === Patient::STATUS) {
                            $status = (int)$data[$field];
                            if (!in_array($status, [
                                Status::ACTIVE,
                                Status::ARCHIVED,
                                Status::SOFT_DELETED
                            ])) {
                                return $this->respondWithError(Message::STATUS_INVALID, 400);
                            }
                            $patient->$field = $status;
                        } else {
                            $patient->$field = $data[$field];
                        }
                    }
                }

                if (!$patient->save()) {
                    $messages = $patient->getMessages(); // This is Phalcon\Messages\MessageInterface[]
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

                return $this->respondWithSuccess([
                    'message' => 'Patient updated successfully',
                    'patient_id' => $patient->id
                ], 201, 'Patient updated successfully');
            });

        } catch (Exception $e) {
            $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
            error_log('Exception: ' . $message);
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Activate a patient (status = 1)
     */
    public function activatePatient(int $id): array {
        try {

            // Role validation should now be done by middleware
            // If we need additional role checking for this specific operation:
            if (!$this->isManagerOrHigher())
                return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 403);

//            $data = $this->getRequestBody();
//
//            if (empty($data[Patient::ID]))
//                return $this->respondWithError(Message::ID_REQUIRED, 400);

            $patient = Patient::findFirstById($id);
            if (!$patient)
                return $this->respondWithError(Message::PATIENT_NOT_FOUND, 404);

            // Check if patient is already deleted
            if ($patient->isActive()) {
                return $this->respondWithError('Patient is already activated', 400);
            }

            return $this->withTransaction(function() use ($patient) {
                $patient->status = Status::ACTIVE;

                if (!$patient->save())
                    return $this->respondWithError(Message::PATIENT_ACTIVATION_FAILED, 409);

                return $this->respondWithSuccess([
                    'message' => Message::PATIENT_ACTIVATED
                ], 202, Message::PATIENT_ACTIVATED);
            });

        } catch (Exception $e) {
            $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
            error_log('Exception: ' . $message);
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Inactivate a patient (status = 0)
     */
    public function inactivatePatient(int $id): array {
        try {

            // Role validation should now be done by middleware
            // If we need additional role checking for this specific operation:
            if (!$this->isManagerOrHigher())
                return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 403);

//            $data = $this->getRequestBody();
//
//            if (empty($data[Patient::ID]))
//                return $this->respondWithError(Message::ID_REQUIRED, 400);

            $patient = Patient::findFirstById($id);
            if (!$patient)
                return $this->respondWithError(Message::PATIENT_NOT_FOUND, 404);

            // Check if patient is already inactivated
            if ($patient->isInactive()) {
                return $this->respondWithError('Patient is already inactive', 400);
            }

            return $this->withTransaction(function() use ($patient) {
                $patient->status = Status::INACTIVE;

                if (!$patient->save())
                    return $this->respondWithError(Message::PATIENT_INACTIVATION_FAILED, 409);

                return $this->respondWithSuccess([
                    'message' => Message::PATIENT_INACTIVATED
                ], 202, Message::PATIENT_INACTIVATED);
            });

        } catch (Exception $e) {
            $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
            error_log('Exception: ' . $message);
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Archive a patient (status = 2)
     */
    public function archivatePatient(int $id): array {
        try {

            // Role validation should now be done by middleware
            // If we need additional role checking for this specific operation:
            if (!$this->isManagerOrHigher())
                return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 403);

//            $data = $this->getRequestBody();
//
//            if (empty($data[Patient::ID]))
//                return $this->respondWithError(Message::ID_REQUIRED, 400);

            $patient = Patient::findFirstById($id);
            if (!$patient)
                return $this->respondWithError(Message::PATIENT_NOT_FOUND, 404);

            // Check if patient is already deleted
            if ($patient->isArchived()) {
                return $this->respondWithError('Patient is already archived', 400);
            }

            return $this->withTransaction(function() use ($patient) {
                $patient->status = Status::ARCHIVED;

                if (!$patient->save())
                    return $this->respondWithError(Message::PATIENT_ARCHIVATION_FAILED, 409);

                return $this->respondWithSuccess([
                    'message' => Message::PATIENT_ARCHIVED
                ], 202, Message::PATIENT_ARCHIVED);
            });

        } catch (Exception $e) {
            $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
            error_log('Exception: ' . $message);
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Soft delete a patient (status = 3)
     */
    public function deletePatient(int $id): array {
        try {

            // Role validation should now be done by middleware
            // If we need additional role checking for this specific operation:
            if (!$this->isManagerOrHigher())
                return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 403);
//
//            $data = $this->getRequestBody();
//
//            if (empty($data[Patient::ID]))
//                return $this->respondWithError(Message::ID_REQUIRED, 400);

            $patient = Patient::findFirstById($id);
            if (!$patient)
                return $this->respondWithError(Message::PATIENT_NOT_FOUND, 404);

            // Check if patient is already deleted
            if ($patient->isDeleted()) {
                return $this->respondWithError('Patient is already deleted', 400);
            }

            return $this->withTransaction(function() use ($patient) {
                $patient->status = Status::SOFT_DELETED;

                if (!$patient->save())
                    return $this->respondWithError(Message::PATIENT_DELETION_FAILED, 409);

                return $this->respondWithSuccess([
                    'message' => Message::PATIENT_DELETED
                ], 202, Message::PATIENT_DELETED);
            });

        } catch (Exception $e) {
            $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
            error_log('Exception: ' . $message);
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Get all patients
     * Restricted to Managers and Administrators only
     */
    public function getAllPatients(): array {
        try {
            // Check if the current user has appropriate role (admin or manager)
            if (!$this->isManagerOrHigher())
                return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 403);

            // Fetch all patients
            $patients = Patient::find([
                'order' => 'lastname, firstname'
            ]);

            if (!$patients)
                return $this->respondWithError(Message::DB_QUERY_FAILED, 500);

            if ($patients->count() === 0)
                return $this->respondWithSuccess(Message::DB_NO_RECORDS, 204, Message::DB_NO_RECORDS);

            // Get patient data
            $patientsArray = [];
            foreach ($patients as $patient) {
                $patientData = $patient->toArray();
                $patientsArray[] = $patientData;
            }

            return $this->respondWithSuccess([
                'count' => $patients->count(),
                'patients' => $patientsArray
            ]);

        } catch (Exception $e) {
            $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
            error_log('Exception: ' . $message);
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Get all patients with addresses
     * Restricted to Managers and Administrators only
     */
    public function getAllPatientsWithAddresses(): array {
        try {
            // Check if the current user has appropriate role (admin or manager)
            if (!$this->isManagerOrHigher())
                return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 403);

            // Fetch all patients
            $patients = Patient::find([
                'order' => 'lastname, firstname'
            ]);

            if (!$patients)
                return $this->respondWithError(Message::DB_QUERY_FAILED, 500);

            if ($patients->count() === 0)
                return $this->respondWithSuccess(Message::DB_NO_RECORDS, 204, Message::DB_NO_RECORDS);

            // Get patient data
            $patientsArray = [];
            foreach ($patients as $patient) {
                $patientData = $patient->toArray();

                // Get patient's addresses
                $addresses = Address::findByPerson($patient->id,PersonType::PATIENT);
                if ($addresses && $addresses->count() > 0) {
                    $patientData['addresses'] = $addresses->toArray();
                } else {
                    $patientData['addresses'] = [];
                }

                $patientsArray[] = $patientData;
            }

            return $this->respondWithSuccess([
                'count' => $patients->count(),
                'patients' => $patientsArray
            ]);

        } catch (Exception $e) {
            $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
            error_log('Exception: ' . $message);
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Get a patient by ID
     */
    public function getPatientById(int $id): array {
        try {
            // Verify user has permission to view patients
            if (!$this->isManagerOrHigher()) {
                return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 403);
            }

            // Find patient
            $patient = Patient::findFirstById($id);
            if (!$patient) {
                return $this->respondWithError(Message::PATIENT_NOT_FOUND, 404);
            }

            return $this->respondWithSuccess($patient->toArray());

        } catch (Exception $e) {
            $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
            error_log('Exception: ' . $message);
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * ----------------------
     */



    /**
     * Upload a new profile photo for a user
     * Modified to allow administrators and managers to upload photos for other users
     */
    public function uploadPhoto(?int $patientId = null): array {
        try {
            // Get the current user's ID and role
            $currentUserId = $this->getCurrentUserId();

            // Authorization check: allow upload for own photo or if admin/manager
            if (!$this->isManagerOrHigher())
                return $this->respondWithError(Message::UNAUTHORIZED_ACCESS, 403);

            // Check for files (middleware should already validate multipart form-data)
            if (!$this->request->hasFiles())
                return $this->respondWithError(Message::UPLOAD_NO_FILES, 400);

            $files = $this->request->getUploadedFiles();
            $photo = $files[0];

            // Validate file type
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            if (!in_array($photo->getType(), $allowedTypes))
                return $this->respondWithError(Message::UPLOAD_INVALID_TYPE, 400);

            // Find and validate user
            $patient = Patient::findFirstById($patientId);
            if (!$patient) return $this->respondWithError(Message::PATIENT_NOT_FOUND, 404);

            // Create photos directory if it doesn't exist
            $uploadDir = Patient::PATH_PHOTO_FILE;
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

            // Generate filename using user info
            $extension = pathinfo($photo->getName(), PATHINFO_EXTENSION);
            $sanitizedFirstname = preg_replace('/[^a-z0-9]/i', '', $patient->firstname);
            $sanitizedLastname = preg_replace('/[^a-z0-9]/i', '', $patient->lastname);
            $filename = sprintf(
                '%d-%s-%s.%s',
                $patientId,
                strtolower($sanitizedFirstname),
                strtolower($sanitizedLastname),
                $extension
            );

            $path = $uploadDir . '/' . $filename;

            return $this->withTransaction(function() use ($patient, $photo, $path, $filename, $uploadDir, $currentUserId, $patientId) {
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
                $oldPhoto = $uploadDir . '/' . $patient->photo;
                if ($patient->photo && $patient->photo !== Patient::DEFAULT_PHOTO_FILE && file_exists($oldPhoto)) {
                    unlink($oldPhoto);
                }

                $upath = "/$path";
                $patient->photo = $upath;

                if (!$patient->save()) {
                    // If save fails, clean up the uploaded file
                    if (file_exists($path)) unlink($path);

                    $messages = $patient->getMessages();
                    $msg = "An unknown error occurred.";

                    if (count($messages) > 0) {
                        $obj = $messages[0];
                        $msg = $obj->getMessage();
                    }

                    return $this->respondWithError($msg, 422);
                }

                // Add information about who uploaded the photo if it wasn't the user themselves
                $uploadedBy = " by user ID: $currentUserId";

                return $this->respondWithSuccess([
                    'message' => Message::UPLOAD_PHOTO_SUCCESS . $uploadedBy,
                    'path' => $upath,
                    'filename' => $filename,
                    'patient_id' => $patientId,
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
    public function updatePhoto(?int $patientId = null): array {
        try {
            // Get the current user's ID
            $currentUserId = $this->getCurrentUserId();
            $currentUserRole = $this->getCurrentUserRole();

            // Authorization check
            if (!$this->isManagerOrHigher())
                return $this->respondWithError(Message::UNAUTHORIZED_ACCESS, 403);

            // Check for files
            if (!$this->request->hasFiles())
                return $this->respondWithError(Message::UPLOAD_NO_FILES, 400);

            $files = $this->request->getUploadedFiles();
            $photo = $files[0];

            // Validate file type
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            if (!in_array($photo->getType(), $allowedTypes))
                return $this->respondWithError(Message::UPLOAD_INVALID_TYPE, 400);

            // Find user
            $patient = Patient::findFirstById($patientId);
            if (!$patient)
                return $this->respondWithError(Message::PATIENT_NOT_FOUND, 404);

            // Create photos directory if it doesn't exist
            $uploadDir = Patient::PATH_PHOTO_FILE;
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

            // Generate filename using user info
            $extension = pathinfo($photo->getName(), PATHINFO_EXTENSION);
            $sanitizedFirstname = preg_replace('/[^a-z0-9]/i', '', $patient->firstname);
            $sanitizedLastname = preg_replace('/[^a-z0-9]/i', '', $patient->lastname);
            $filename = sprintf(
                '%d-%s-%s.%s',
                $patientId,
                strtolower($sanitizedFirstname),
                strtolower($sanitizedLastname),
                $extension
            );

            $path = $uploadDir . '/' . $filename;

            return $this->withTransaction(function() use ($patient, $photo, $path, $filename, $uploadDir, $currentUserId, $patientId) {
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
                $oldPhoto = $uploadDir . '/' . $patient->photo;
                if ($patient->photo && $patient->photo !== Patient::DEFAULT_PHOTO_FILE && file_exists($oldPhoto)) {
                    unlink($oldPhoto);
                }

                $upath = "/$path";
                $patient->photo = $upath;

                if (!$patient->save()) {
                    // If save fails, clean up the uploaded file
                    if (file_exists($path)) unlink($path);

                    $messages = $patient->getMessages();
                    $msg = "An unknown error occurred.";

                    if (count($messages) > 0) {
                        $obj = $messages[0];
                        $msg = $obj->getMessage();
                    }

                    return $this->respondWithError($msg, 422);
                }

                // Add information about who updated the photo if it wasn't the user themselves
                $updatedBy = " by user ID: $currentUserId";

                return $this->respondWithSuccess([
                    'message' => Message::UPLOAD_PHOTO_SUCCESS . $updatedBy,
                    'path' => $upath,
                    'filename' => $filename,
                    'patient_id' => $patientId,
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

    public function getPhoto(?int $patientId = null): array {
        try {

            // If requesting another user's account, check authorization
            if (!$this->isManagerOrHigher())
                return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 403);

            // Find patient
            $patient = Patient::findFirstById($patientId);

            if (!$patient)
                return $this->respondWithError(Message::PATIENT_NOT_FOUND, 404);

            $userPhoto = $patient->photo;
            $userData[Patient::PHOTO] = $userPhoto;

            return $this->respondWithSuccess($userData);

        } catch (Exception $e) {
            $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
            error_log('Exception: ' . $message);
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

}