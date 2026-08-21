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
use Api\Services\InvalidQueryException;

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

                if (isset($data[Patient::GENDER]))
                    $patient->gender = $data[Patient::GENDER];

                if (isset($data[Patient::BIRTHDATE]))
                    $patient->birthdate = $data[Patient::BIRTHDATE];

                if (isset($data[Patient::PHONE2]))
                    $patient->phone2 = $data[Patient::PHONE2];

                if (isset($data[Patient::PHONE3]))
                    $patient->phone3 = $data[Patient::PHONE3];

                if (isset($data[Patient::ADMISSION]))
                    $patient->admission = $data[Patient::ADMISSION];

                // Default to active status
                $patient->status = Status::ACTIVE;

                if (!$patient->save()) {
                    return $this->respondWithError($this->getFirstErrorMessage($patient), 422);
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

        } catch (\Throwable $e) {
            return $this->handleException($e);
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
                                Status::INACTIVE,
                                Status::ARCHIVED,
                                Status::SOFT_DELETED
                            ], true)) {
                                return $this->respondWithError(Message::STATUS_INVALID, 400);
                            }
                            $patient->$field = $status;
                        } else {
                            $patient->$field = $data[$field];
                        }
                    }
                }

                if (!$patient->save()) {
                    return $this->respondWithError($this->getFirstErrorMessage($patient), 422);
                }

                return $this->respondWithSuccess([
                    'message' => 'Patient updated successfully',
                    'patient_id' => $patient->id
                ], 201, 'Patient updated successfully');
            });

        } catch (\Throwable $e) {
            return $this->handleException($e);
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

        } catch (\Throwable $e) {
            return $this->handleException($e);
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

        } catch (\Throwable $e) {
            return $this->handleException($e);
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

        } catch (\Throwable $e) {
            return $this->handleException($e);
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

        } catch (\Throwable $e) {
            return $this->handleException($e);
        }
    }

    /**
     * List patients (managers/admins).
     * Query: page, per_page, status (0|1|2|3), search (first/middle/last name, HHAexchange patient or admission id).
     * Without any parameter the legacy behaviour is kept: every row, 204 when there are none.
     */
    public function getAllPatients(): array {
        return $this->listPatients(false);
    }

    /**
     * List patients with their addresses (managers/admins). Same query parameters as GET /patients.
     */
    public function getAllPatientsWithAddresses(): array {
        return $this->listPatients(true);
    }

    /**
     * Shared implementation of the patient list endpoints.
     */
    private function listPatients(bool $withAddresses): array {
        try {
            if (!$this->isManagerOrHigher())
                return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 403);

            $paging = $this->getPaging();
            $status = $this->queryInt('status', [Status::INACTIVE, Status::ACTIVE, Status::ARCHIVED, Status::SOFT_DELETED]);
            $search = $this->queryText('search');

            $conditions = []; $bind = []; $bindTypes = [];
            if ($status !== null) {
                $conditions[] = 'status = :status:'; $bind['status'] = $status; $bindTypes['status'] = \PDO::PARAM_INT;
            }
            if ($search !== null) {
                $conditions[] = $this->likeAnyCondition(['firstname', 'lastname', 'middlename', 'patient', 'admission'], $search, $bind, $bindTypes);
            }

            $where = $conditions ? ['conditions' => implode(' AND ', $conditions), 'bind' => $bind, 'bindTypes' => $bindTypes] : [];
            $total = (int)Patient::count($where);
            $patients = Patient::find($this->applyPaging($where + ['order' => 'lastname, firstname'], $paging));

            $patientsArray = $patients->toArray();

            if (!$patientsArray && !$this->hasListParams(['status', 'search']))
                return $this->respondWithSuccess(Message::DB_NO_RECORDS, 204, Message::DB_NO_RECORDS);

            if ($withAddresses && $patientsArray) {
                $byPatient = $this->addressesByPatient(array_column($patientsArray, 'id'));
                foreach ($patientsArray as &$patientData) {
                    $patientData['addresses'] = $byPatient[$patientData['id']] ?? [];
                }
                unset($patientData);
            }

            return $this->respondWithList('patients', $patientsArray, $paging, $total);

        } catch (InvalidQueryException $e) {
            return $this->respondWithError($e->getMessage(), 400);
        } catch (\Throwable $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Addresses for many patients in two queries (instead of one per patient).
     * Like Address::findByPerson(), every patient's list includes the Community address (id 0).
     * @return array<int, array[]> patient id => addresses, newest first
     */
    private function addressesByPatient(array $patientIds): array {
        $result = array_fill_keys($patientIds, []);
        $addresses = Address::find([
            'conditions' => 'person_type = :type: AND person_id IN ({ids:array})',
            'bind' => ['type' => PersonType::PATIENT, 'ids' => $patientIds],
            'order' => 'created_at DESC'
        ]);
        foreach ($addresses as $address) {
            $result[(int)$address->person_id][] = $address->toArray();
        }
        $community = Address::findFirst(0);
        if ($community) {
            $communityData = $community->toArray();
            foreach ($result as &$list) {
                $list[] = $communityData;
                usort($list, static fn (array $a, array $b) => strcmp((string)$b['created_at'], (string)$a['created_at']));
            }
            unset($list);
        }
        return $result;
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

        } catch (\Throwable $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Upload a patient photo (manager/admin only)
     */
    public function uploadPhoto(?int $patientId = null): array {
        return $this->savePhoto($patientId, 'uploaded_by');
    }

    /**
     * Replace a patient photo. Same rules as uploadPhoto; kept as a separate route for the clients.
     */
    public function updatePhoto(?int $patientId = null): array {
        return $this->savePhoto($patientId, 'updated_by');
    }

    /**
     * Shared implementation of uploadPhoto/updatePhoto.
     * Type detection, extension and directory protection are handled by BaseController::storeUploadedPhoto().
     */
    private function savePhoto(?int $patientId, string $actorKey): array {
        try {
            if (!$this->isManagerOrHigher())
                return $this->respondWithError(Message::UNAUTHORIZED_ACCESS, 403);

            if ($patientId === null)
                return $this->respondWithError('Patient ID is required', 400);

            if (!$this->request->hasFiles())
                return $this->respondWithError(Message::UPLOAD_NO_FILES, 400);

            $patient = Patient::findFirstById($patientId);
            if (!$patient)
                return $this->respondWithError(Message::PATIENT_NOT_FOUND, 404);

            $currentUserId = $this->getCurrentUserId();
            $photo = $this->request->getUploadedFiles()[0];
            $baseName = sprintf('%d-%s-%s', $patientId, $patient->firstname, $patient->lastname);

            return $this->withTransaction(function() use ($patient, $photo, $baseName, $currentUserId, $patientId, $actorKey) {
                $stored = $this->storeUploadedPhoto($photo, Patient::PATH_PHOTO_FILE, $baseName);
                if (is_string($stored)) {
                    return $this->respondWithError($stored, $stored === Message::UPLOAD_INVALID_TYPE ? 400 : 500);
                }

                $previousPhoto = $patient->photo;
                $patient->photo = $stored['path'];

                if (!$patient->save()) {
                    $this->deleteStoredPhoto($stored['path'], Patient::PATH_PHOTO_FILE, Patient::DEFAULT_PHOTO_FILE, (string)$previousPhoto);
                    return $this->respondWithError($this->getFirstErrorMessage($patient), 422);
                }

                $this->deleteStoredPhoto($previousPhoto, Patient::PATH_PHOTO_FILE, Patient::DEFAULT_PHOTO_FILE, $stored['path']);

                return $this->respondWithSuccess([
                    'message' => Message::UPLOAD_PHOTO_SUCCESS . " by user ID: $currentUserId",
                    'path' => $stored['path'],
                    'filename' => $stored['filename'],
                    'patient_id' => $patientId,
                    $actorKey => $currentUserId,
                    'processed' => true
                ], 201, Message::UPLOAD_PHOTO_SUCCESS);
            });

        } catch (Exception $e) {
            return $this->handleException($e);
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

        } catch (\Throwable $e) {
            return $this->handleException($e);
        }
    }

}