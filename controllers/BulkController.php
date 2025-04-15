<?php

namespace Api\Controllers;

use Api\Constants\Message;
use Api\Constants\PersonType;
use Api\Constants\Progress;
use Api\Constants\Role;
use Api\Constants\Status;
use Api\Encoding\Base64;
use Api\Models\Address;
use Api\Models\Auth;
use Api\Models\Patient;
use Api\Models\User;
use Api\Models\UserPatient;
use Api\Models\Visit;
use DateTime;
use Exception;

class BulkController extends BaseController {

    /**
     * Bulk create multiple user accounts
     */
    public function auths(): array {
        try {
            $accounts = $this->getRequestBody();

            // Validate that we received an array
            if (!is_array($accounts)) {
                return $this->respondWithError(Message::REQUEST_BODY_JSON_ARRAY, 400);
            }

            $results = [
                'success' => [],
                'failed' => []
            ];

            // Start transaction
            $this->beginTransaction();

            foreach ($accounts as $index => $data) {
                try {
                    // Validate username and password input
                    if (empty($data[Auth::USERNAME]) || empty($data[Auth::PASSWORD])) {
                        $results['failed'][] = [
                            'index' => $index,
                            'username' => $data[Auth::USERNAME] ?? 'unknown',
                            'error' => Message::CREDENTIALS_REQUIRED
                        ];
                        continue;
                    }

                    // Validate username as an email
                    if (!filter_var($data[Auth::USERNAME], FILTER_VALIDATE_EMAIL)) {
                        $results['failed'][] = [
                            'index' => $index,
                            'username' => $data[Auth::USERNAME],
                            'error' => Message::EMAIL_INVALID
                        ];
                        continue;
                    }

                    // Check if username already exists
                    if (Auth::findFirstByUsername($data[Auth::USERNAME])) {
                        $results['failed'][] = [
                            'index' => $index,
                            'username' => $data[Auth::USERNAME],
                            'error' => Message::EMAIL_REGISTERED
                        ];
                        continue;
                    }

                    // Create new user record
                    $auth = new Auth();
                    $auth->username = $data[Auth::USERNAME];
                    $auth->setPassword($data[Auth::PASSWORD]);
                    $auth->role = Role::CAREGIVER;
                    $auth->status = Status::NOT_VERIFIED;

                    if (!$auth->save()) {
                        $results['failed'][] = [
                            'index' => $index,
                            'username' => $data[Auth::USERNAME],
                            'error' => $auth->getMessages()
                        ];
                        continue;
                    }

                    if (!$auth->id) {
                        $results['failed'][] = [
                            'index' => $index,
                            'username' => $data[Auth::USERNAME],
                            'error' => Message::DB_ID_GENERATION_FAILED
                        ];
                        continue;
                    }

                    if (!User::createTemplate($auth->id, $auth->username)) {
                        $results['failed'][] = [
                            'index' => $index,
                            'username' => $data[Auth::USERNAME],
                            'error' => Message::DB_SESSION_UPDATE_FAILED
                        ];
                        continue;
                    }

                    $results['success'][] = [
                        'index' => $index,
                        'username' => $data[Auth::USERNAME]
                    ];

                } catch (Exception $e) {
                    $results['failed'][] = [
                        'index' => $index,
                        'username' => $data[Auth::USERNAME] ?? 'unknown',
                        'error' => $e->getMessage()
                    ];
                    continue;
                }
            }

            // If no accounts were created successfully, rollback and return error
            if (empty($results['success'])) {
                $this->rollbackTransaction();
                return $this->respondWithError([
                    'message' => Message::BATCH_NONE_CREATED,
                    'details' => $results['failed']
                ], 422);
            }

            // Commit transaction if at least one account was created
            $this->commitTransaction();

            // Return results including both successes and failures
            return $this->respondWithSuccess([
                'message' => count($results['success']) . Message::BATCH_CREATED_SUFFIX,
                'results' => $results
            ], 201);

        } catch (Exception $e) {
            $this->rollbackTransaction();
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }


    /**
     * Update multiple users with optional address creation
     */
    public function users(): array {
        try {
            // Verify user has permission (manager or higher)
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

                        // Save user changes
                        if (!$user->save()) {
                            $results['failed'][] = [
                                'index' => $index,
                                'id' => $data['id'],
                                'error' => $user->getMessages()
                            ];
                            continue;
                        }

                        $successResult = [
                            'index' => $index,
                            'id' => $data['id']
                        ];

                        // If address data is included, create an address for the user
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

                            $missingAddressFields = [];
                            foreach ($requiredAddressFields as $field) {
                                if (empty($addressData[$field])) {
                                    $missingAddressFields[] = $field;
                                }
                            }

                            if (!empty($missingAddressFields)) {
                                $successResult['address_status'] = 'not created';
                                $successResult['missing_address_fields'] = $missingAddressFields;
                                $results['success'][] = $successResult;
                                continue;
                            }

                            $address = new Address();
                            $address->person_id = $user->id;
                            $address->person_type = PersonType::USER;
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
                                $successResult['address_status'] = 'failed';
                                $successResult['address_errors'] = $address->getMessages();
                                $results['success'][] = $successResult;
                                continue;
                            }

                            $successResult['address_id'] = $address->id;
                            $successResult['address_status'] = 'created';
                        }

                        $results['success'][] = $successResult;

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
     * Create multiple patients in bulk
     */
    public function patients(): array {
        try {
            // Verify user has permission to create patients (manager or higher)
            if (!$this->isManagerOrHigher()) {
                return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 403);
            }

            $patientsData = $this->getRequestBody();

            // Validate that we received an array
            if (!is_array($patientsData)) {
                return $this->respondWithError("Request body must be a JSON array", 400);
            }

            $results = [
                'success' => [],
                'failed' => []
            ];

            // Begin transaction for all patients
            $this->beginTransaction();

            try {
                foreach ($patientsData as $index => $data) {
                    try {
                        // Validate required fields
                        $requiredFields = [
                            Patient::FIRSTNAME => 'First name is required',
                            Patient::LASTNAME => 'Last name is required',
                            Patient::PHONE => 'Phone number is required'
                        ];

                        $missingFields = [];
                        foreach ($requiredFields as $field => $message) {
                            if (empty($data[$field])) {
                                $missingFields[$field] = $message;
                            }
                        }

                        if (!empty($missingFields)) {
                            $results['failed'][] = [
                                'index' => $index,
                                'errors' => $missingFields
                            ];
                            continue;
                        }

                        // Create new patient
                        $patient = new Patient();

                        // Set required fields
                        $patient->firstname = $data[Patient::FIRSTNAME];
                        $patient->lastname = $data[Patient::LASTNAME];
                        $patient->phone = $data[Patient::PHONE];

                        // Set optional fields if provided
                        $optionalFields = [
                            Patient::MIDDLENAME,
                            Patient::PATIENT_ID,
                            Patient::ADMISSION
                        ];

                        foreach ($optionalFields as $field) {
                            if (isset($data[$field])) {
                                $patient->$field = $data[$field];
                            }
                        }

                        // Default to active status
                        $patient->status = Status::ACTIVE;

                        if (!$patient->save()) {
                            $results['failed'][] = [
                                'index' => $index,
                                'error' => $patient->getMessages()
                            ];
                            continue;
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

                            $missingAddressFields = [];
                            foreach ($requiredAddressFields as $field) {
                                if (empty($addressData[$field])) {
                                    $missingAddressFields[] = $field;
                                }
                            }

                            if (!empty($missingAddressFields)) {
                                $results['success'][] = [
                                    'index' => $index,
                                    'patient_id' => $patient->id,
                                    'status' => 'created without address',
                                    'missing_address_fields' => $missingAddressFields
                                ];
                                continue;
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
                                $results['success'][] = [
                                    'index' => $index,
                                    'patient_id' => $patient->id,
                                    'status' => 'created without address',
                                    'address_errors' => $address->getMessages()
                                ];
                                continue;
                            }

                            $results['success'][] = [
                                'index' => $index,
                                'patient_id' => $patient->id,
                                'address_id' => $address->id,
                                'status' => 'created with address'
                            ];
                        } else {
                            $results['success'][] = [
                                'index' => $index,
                                'patient_id' => $patient->id,
                                'status' => 'created without address'
                            ];
                        }

                    } catch (Exception $e) {
                        $results['failed'][] = [
                            'index' => $index,
                            'error' => $e->getMessage()
                        ];
                        continue;
                    }
                }

                // If no patients were created successfully, rollback
                if (empty($results['success'])) {
                    $this->rollbackTransaction();
                    return $this->respondWithError([
                        'message' => 'No patients were created',
                        'details' => $results['failed']
                    ], 422);
                }

                // Commit all successful patients
                $this->commitTransaction();

                return $this->respondWithSuccess([
                    'message' => count($results['success']) . ' patients created successfully',
                    'results' => $results
                ], 201);

            } catch (Exception $e) {
                $this->rollbackTransaction();
                throw $e;
            }

        } catch (Exception $e) {
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Create multiple addresses in bulk
     */
    public function addresses(): array {
        try {
            // Verify user has permission (manager or higher)
            if (!$this->isManagerOrHigher()) {
                return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 403);
            }

            $addressesData = $this->getRequestBody();

            // Validate that we received an array
            if (!is_array($addressesData)) {
                return $this->respondWithError("Request body must be a JSON array", 400);
            }

            $results = [
                'success' => [],
                'failed' => []
            ];

            // Begin transaction for all addresses
            $this->beginTransaction();

            try {
                foreach ($addressesData as $index => $data) {
                    try {
                        // Validate required fields
                        $requiredFields = [
                            Address::PERSON_ID => 'Person ID is required',
                            Address::PERSON_TYPE => 'Person type is required',
                            Address::TYPE => 'Address type is required',
                            Address::ADDRESS => 'Street address is required',
                            Address::CITY => 'City is required',
                            Address::COUNTY => 'County is required',
                            Address::STATE => 'State is required',
                            Address::ZIPCODE => 'ZIP code is required'
                        ];

                        $missingFields = [];
                        foreach ($requiredFields as $field => $message) {
                            if (empty($data[$field])) {
                                $missingFields[$field] = $message;
                            }
                        }

                        if (!empty($missingFields)) {
                            $results['failed'][] = [
                                'index' => $index,
                                'errors' => $missingFields
                            ];
                            continue;
                        }

                        // Validate person type
                        if (!in_array($data[Address::PERSON_TYPE], [PersonType::USER, PersonType::PATIENT])) {
                            $results['failed'][] = [
                                'index' => $index,
                                'error' => 'Invalid person type'
                            ];
                            continue;
                        }

                        // Validate person exists
                        if ($data[Address::PERSON_TYPE] == PersonType::USER) {
                            $person = User::findFirst($data[Address::PERSON_ID]);
                            if (!$person) {
                                $results['failed'][] = [
                                    'index' => $index,
                                    'error' => Message::USER_NOT_FOUND
                                ];
                                continue;
                            }
                        } else {
                            $person = Patient::findFirst($data[Address::PERSON_ID]);
                            if (!$person) {
                                $results['failed'][] = [
                                    'index' => $index,
                                    'error' => Message::PATIENT_NOT_FOUND
                                ];
                                continue;
                            }
                        }

                        // Validate state format
                        if (!preg_match('/^[A-Z]{2}$/', $data[Address::STATE])) {
                            $results['failed'][] = [
                                'index' => $index,
                                'error' => 'State must be a 2-letter code'
                            ];
                            continue;
                        }

                        // Validate ZIP code format
                        if (!preg_match('/^\d{5}$/', $data[Address::ZIPCODE])) {
                            $results['failed'][] = [
                                'index' => $index,
                                'error' => 'ZIP code must be 5 digits'
                            ];
                            continue;
                        }

                        // Create new address
                        $address = new Address();

                        // Set basic fields
                        $address->person_id = (int)$data[Address::PERSON_ID];
                        $address->person_type = (int)$data[Address::PERSON_TYPE];
                        $address->type = $data[Address::TYPE];
                        $address->address = $data[Address::ADDRESS];
                        $address->city = $data[Address::CITY];
                        $address->county = $data[Address::COUNTY];
                        $address->state = strtoupper($data[Address::STATE]);
                        $address->zipcode = $data[Address::ZIPCODE];

                        // Set optional fields if provided
                        if (isset($data[Address::COUNTRY])) {
                            $address->country = $data[Address::COUNTRY];
                        }

                        if (isset($data[Address::LATITUDE]) && isset($data[Address::LONGITUDE])) {
                            $latitude = (float)$data[Address::LATITUDE];
                            $longitude = (float)$data[Address::LONGITUDE];

                            // Validate coordinates
                            if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
                                $results['failed'][] = [
                                    'index' => $index,
                                    'error' => 'Invalid coordinates'
                                ];
                                continue;
                            }

                            $address->latitude = $latitude;
                            $address->longitude = $longitude;
                        }

                        if (!$address->save()) {
                            $results['failed'][] = [
                                'index' => $index,
                                'error' => $address->getMessages()
                            ];
                            continue;
                        }

                        $results['success'][] = [
                            'index' => $index,
                            'id' => $address->id,
                            'person_id' => $address->person_id,
                            'person_type' => $address->person_type
                        ];

                    } catch (Exception $e) {
                        $results['failed'][] = [
                            'index' => $index,
                            'error' => $e->getMessage()
                        ];
                        continue;
                    }
                }

                // If no addresses were created successfully, rollback
                if (empty($results['success'])) {
                    $this->rollbackTransaction();
                    return $this->respondWithError([
                        'message' => 'No addresses were created',
                        'details' => $results['failed']
                    ], 422);
                }

                // Commit all successful addresses
                $this->commitTransaction();

                return $this->respondWithSuccess([
                    'message' => count($results['success']) . ' addresses created successfully',
                    'results' => $results
                ], 201);

            } catch (Exception $e) {
                $this->rollbackTransaction();
                throw $e;
            }

        } catch (Exception $e) {
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Create multiple user-patient assignments in bulk
     *
     * @return array Response data
     */
    public function userPatient(): array {
        try {
            // Verify manager role or higher
            if (!$this->isManagerOrHigher()) {
                return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 403);
            }

            $assignmentsData = $this->getRequestBody();

            // Validate that we received an array
            if (!is_array($assignmentsData)) {
                return $this->respondWithError("Request body must be a JSON array", 400);
            }

            $results = [
                'success' => [],
                'failed' => []
            ];

            // Begin transaction for all assignments
            $this->beginTransaction();

            try {
                $currentUserId = $this->getCurrentUserId();

                foreach ($assignmentsData as $index => $data) {
                    try {
                        // Validate required fields
                        if (empty($data[UserPatient::USER_ID]) || empty($data[UserPatient::PATIENT_ID])) {
                            $results['failed'][] = [
                                'index' => $index,
                                'error' => 'User ID and Patient ID are required'
                            ];
                            continue;
                        }

                        $userId = (int)$data[UserPatient::USER_ID];
                        $patientId = (int)$data[UserPatient::PATIENT_ID];

                        // Check if user exists
                        $user = User::findFirst($userId);
                        if (!$user) {
                            $results['failed'][] = [
                                'index' => $index,
                                'error' => 'User not found'
                            ];
                            continue;
                        }

                        // Check if patient exists
                        $patient = Patient::findFirst($patientId);
                        if (!$patient) {
                            $results['failed'][] = [
                                'index' => $index,
                                'error' => 'Patient not found'
                            ];
                            continue;
                        }

                        // Check if assignment already exists
                        $existingAssignment = UserPatient::findAssignment($userId, $patientId);
                        if ($existingAssignment) {
                            // If it exists but is inactive, reactivate it
                            if ($existingAssignment->status === Status::INACTIVE) {
                                $existingAssignment->status = Status::ACTIVE;
                                $existingAssignment->assigned_by = $currentUserId;
                                $existingAssignment->assigned_at = date('Y-m-d H:i:s');

                                if (isset($data[UserPatient::NOTES])) {
                                    $existingAssignment->notes = $data[UserPatient::NOTES];
                                }

                                if (!$existingAssignment->save()) {
                                    $results['failed'][] = [
                                        'index' => $index,
                                        'error' => 'Failed to reactivate assignment: ' . implode(', ', $existingAssignment->getMessages())
                                    ];
                                    continue;
                                }

                                $results['success'][] = [
                                    'index' => $index,
                                    'user_id' => $userId,
                                    'patient_id' => $patientId,
                                    'action' => 'reactivated'
                                ];
                                continue;
                            }

                            $results['failed'][] = [
                                'index' => $index,
                                'error' => 'User is already assigned to this patient'
                            ];
                            continue;
                        }

                        // Create new assignment
                        $assignment = new UserPatient();
                        $assignment->user_id = $userId;
                        $assignment->patient_id = $patientId;
                        $assignment->assigned_by = $currentUserId;

                        if (isset($data[UserPatient::NOTES])) {
                            $assignment->notes = $data[UserPatient::NOTES];
                        }

                        if (!$assignment->save()) {
                            $results['failed'][] = [
                                'index' => $index,
                                'error' => 'Failed to create assignment: ' . implode(', ', $assignment->getMessages())
                            ];
                            continue;
                        }

                        $results['success'][] = [
                            'index' => $index,
                            'user_id' => $userId,
                            'patient_id' => $patientId,
                            'action' => 'created'
                        ];

                    } catch (Exception $e) {
                        $results['failed'][] = [
                            'index' => $index,
                            'error' => $e->getMessage()
                        ];
                        continue;
                    }
                }

                // If no assignments were created successfully, rollback
                if (empty($results['success'])) {
                    $this->rollbackTransaction();
                    return $this->respondWithError([
                        'message' => 'No assignments were created',
                        'details' => $results['failed']
                    ], 422);
                }

                // Commit all successful assignments
                $this->commitTransaction();

                return $this->respondWithSuccess([
                    'message' => count($results['success']) . ' assignments created successfully',
                    'results' => $results
                ], 201);

            } catch (Exception $e) {
                $this->rollbackTransaction();
                throw $e;
            }

        } catch (Exception $e) {
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Create multiple visits in bulk with any status (for backup recovery)
     *
     * @return array Response data
     */
    public function visits(): array {
        try {
            // Verify user has admin privileges (this is a restricted backup recovery function)
            if (!$this->isAdmin()) {
                return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 403);
            }

            $visitsData = $this->getRequestBody();

            // Validate that we received an array
            if (!is_array($visitsData)) {
                return $this->respondWithError("Request body must be a JSON array", 400);
            }

            $results = [
                'success' => [],
                'failed' => []
            ];

            // Begin transaction for all visits
            $this->beginTransaction();

            try {
                foreach ($visitsData as $index => $data) {
                    try {
                        // Validate minimum required fields
                        $requiredFields = [
                            Visit::USER_ID => 'User ID is required',
                            Visit::PATIENT_ID => 'Patient ID is required',
                            Visit::START_TIME => 'Start time is required',
                            Visit::END_TIME => 'End time is required'
                        ];

                        $missingFields = [];
                        foreach ($requiredFields as $field => $message) {
                            if (!isset($data[$field])) {
                                $missingFields[$field] = $message;
                            }
                        }

                        if (!empty($missingFields)) {
                            $results['failed'][] = [
                                'index' => $index,
                                'errors' => $missingFields
                            ];
                            continue;
                        }

                        // Verify references exist
                        $userId = (int)$data[Visit::USER_ID];
                        $patientId = (int)$data[Visit::PATIENT_ID];

                        $user = User::findFirst($userId);
                        if (!$user) {
                            $results['failed'][] = [
                                'index' => $index,
                                'error' => "User with ID {$userId} not found"
                            ];
                            continue;
                        }

                        $patient = Patient::findFirst($patientId);
                        if (!$patient) {
                            $results['failed'][] = [
                                'index' => $index,
                                'error' => "Patient with ID {$patientId} not found"
                            ];
                            continue;
                        }

                        // Create visit with all fields from backup
                        $visit = new Visit();

                        // Set required fields
                        $visit->user_id = $userId;
                        $visit->patient_id = $patientId;

                        // Parse dates
                        try {
                            $startTime = new DateTime($data[Visit::START_TIME]);
                            $endTime = new DateTime($data[Visit::END_TIME]);

                            if ($endTime < $startTime) {
                                $results['failed'][] = [
                                    'index' => $index,
                                    'error' => "End time cannot be before start time"
                                ];
                                continue;
                            }

                            $visit->start_time = $startTime->format('Y-m-d H:i:s');
                            $visit->end_time = $endTime->format('Y-m-d H:i:s');
                        } catch (Exception $e) {
                            $results['failed'][] = [
                                'index' => $index,
                                'error' => "Invalid date format: " . $e->getMessage()
                            ];
                            continue;
                        }

                        // Set optional fields if provided
                        if (isset($data[Visit::NOTE])) {
                            $visit->note = $data[Visit::NOTE];
                        }

                        // Set status fields - allow any status value for backup recovery
                        if (isset($data[Visit::PROGRESS])) {
                            $progress = (int)$data[Visit::PROGRESS];
                            // Validate progress is within allowed values
                            if (!in_array($progress, [
                                Progress::CANCELED,
                                Progress::SCHEDULED,
                                Progress::IN_PROGRESS,
                                Progress::COMPLETED,
                                Progress::PAID
                            ])) {
                                $results['failed'][] = [
                                    'index' => $index,
                                    'error' => "Invalid progress value: {$progress}"
                                ];
                                continue;
                            }
                            $visit->progress = $progress;
                        } else {
                            $visit->progress = Progress::SCHEDULED; // Default
                        }

                        if (isset($data[Visit::STATUS])) {
                            $status = (int)$data[Visit::STATUS];
                            // Validate status is within allowed values
                            if (!in_array($status, [
                                Status::ACTIVE,
                                Status::ARCHIVED,
                                Status::SOFT_DELETED
                            ])) {
                                $results['failed'][] = [
                                    'index' => $index,
                                    'error' => "Invalid status value: {$status}"
                                ];
                                continue;
                            }
                            $visit->status = $status;
                        } else {
                            $visit->status = Status::ACTIVE; // Default
                        }

                        // Preserve original timestamps if provided (for exact backup recovery)
                        if (isset($data[Visit::CREATED_AT])) {
                            $visit->created_at = $data[Visit::CREATED_AT];
                        }

                        if (isset($data[Visit::UPDATED_AT])) {
                            $visit->updated_at = $data[Visit::UPDATED_AT];
                        }

                        // For exact backup recovery, allow setting the ID if provided
                        if (isset($data[Visit::ID])) {
                            // Check if a visit with this ID already exists
                            $existingVisit = Visit::findFirst($data[Visit::ID]);
                            if ($existingVisit) {
                                $results['failed'][] = [
                                    'index' => $index,
                                    'error' => "Visit with ID {$data[Visit::ID]} already exists"
                                ];
                                continue;
                            }

                            // Set the ID for exact recovery
                            $visit->id = $data[Visit::ID];
                        }

                        // Save the visit
                        if (!$visit->save()) {
                            $results['failed'][] = [
                                'index' => $index,
                                'error' => implode(', ', $visit->getMessages())
                            ];
                            continue;
                        }

                        $results['success'][] = [
                            'index' => $index,
                            'id' => $visit->id,
                            'user_id' => $visit->user_id,
                            'patient_id' => $visit->patient_id,
                            'status' => 'created',
                            'progress_value' => $visit->progress,
                            'status_value' => $visit->status
                        ];

                    } catch (Exception $e) {
                        $results['failed'][] = [
                            'index' => $index,
                            'error' => $e->getMessage()
                        ];
                        continue;
                    }
                }

                // If no visits were created successfully, rollback
                if (empty($results['success'])) {
                    $this->rollbackTransaction();
                    return $this->respondWithError([
                        'message' => 'No visits were created',
                        'details' => $results['failed']
                    ], 422);
                }

                // Commit all successful visits
                $this->commitTransaction();

                return $this->respondWithSuccess([
                    'message' => count($results['success']) . ' visits created successfully',
                    'results' => $results
                ], 201);

            } catch (Exception $e) {
                $this->rollbackTransaction();
                throw $e;
            }

        } catch (Exception $e) {
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

}