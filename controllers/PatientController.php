<?php

declare(strict_types=1);

namespace Api\Controllers;

use Api\Constants\PersonType;
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
            if (!$this->isManagerOrHigher()) {
                return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 403);
            }

            $data = $this->getRequestBody();

            // Validate required fields
            $requiredFields = [
                Patient::FIRSTNAME => 'First name is required',
                Patient::LASTNAME => 'Last name is required',
                Patient::PHONE => 'Phone number is required'
            ];

            foreach ($requiredFields as $field => $message) {
                if (empty($data[$field])) {
                    return $this->respondWithError($message, 400);
                }
            }

            // Create patient within transaction
            return $this->withTransaction(function() use ($data) {
                $patient = new Patient();

                // Set required fields
                $patient->firstname = $data[Patient::FIRSTNAME];
                $patient->lastname = $data[Patient::LASTNAME];
                $patient->phone = $data[Patient::PHONE];

                // Set optional fields if provided
                if (isset($data[Patient::MIDDLENAME])) {
                    $patient->middlename = $data[Patient::MIDDLENAME];
                }

                if (isset($data[Patient::PATIENT_ID])) {
                    $patient->patient = $data[Patient::PATIENT_ID];
                }

                if (isset($data[Patient::ADMISSION])) {
                    $patient->admission = $data[Patient::ADMISSION];
                }

                // Default to active status
                $patient->status = Status::ACTIVE;

                if (!$patient->save()) {
                    return $this->respondWithError($patient->getMessages(), 422);
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
                        ], 201);
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
                        ], 201);
                    }

                    return $this->respondWithSuccess([
                        'message' => 'Patient created with address',
                        'patient_id' => $patient->id,
                        'address_id' => $address->id
                    ], 201);
                }

                return $this->respondWithSuccess([
                    'message' => 'Patient created successfully',
                    'patient_id' => $patient->id
                ], 201);
            });

        } catch (Exception $e) {
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Update an existing patient
     */
    public function update(int $id): array {
        try {
            // Verify user has permission to update patients
            if (!$this->isManagerOrHigher()) {
                return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 403);
            }

            // Find patient
            $patient = Patient::findFirst($id);
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
                    return $this->respondWithError($patient->getMessages(), 422);
                }

                return $this->respondWithSuccess([
                    'message' => 'Patient updated successfully',
                    'patient_id' => $patient->id
                ]);
            });

        } catch (Exception $e) {
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Soft delete a patient (mark as deleted)
     */
    public function delete(int $id): array {
        try {
            // Verify user has permission to delete patients
            if (!$this->isManagerOrHigher()) {
                return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 403);
            }

            // Find patient
            $patient = Patient::findFirst($id);
            if (!$patient) {
                return $this->respondWithError(Message::PATIENT_NOT_FOUND, 404);
            }

            // Check if patient is already deleted
            if ($patient->isDeleted()) {
                return $this->respondWithError('Patient is already deleted', 400);
            }

            // Soft delete patient within transaction
            return $this->withTransaction(function() use ($patient) {
                // Set status to deleted
                $patient->status = Status::SOFT_DELETED;

                if (!$patient->save()) {
                    return $this->respondWithError($patient->getMessages(), 422);
                }

                return $this->respondWithSuccess([
                    'message' => 'Patient deleted successfully',
                    'patient_id' => $patient->id
                ]);
            });

        } catch (Exception $e) {
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Archive a patient
     */
    public function archive(int $id): array {
        try {
            // Verify user has permission to archive patients
            if (!$this->isManagerOrHigher()) {
                return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 403);
            }

            // Find patient
            $patient = Patient::findFirst($id);
            if (!$patient) {
                return $this->respondWithError(Message::PATIENT_NOT_FOUND, 404);
            }

            // Check if patient is deleted
            if ($patient->isDeleted()) {
                return $this->respondWithError('Cannot archive a deleted patient', 400);
            }

            // Check if patient is already archived
            if ($patient->isArchived()) {
                return $this->respondWithError('Patient is already archived', 400);
            }

            // Archive patient within transaction
            return $this->withTransaction(function() use ($patient) {
                // Set status to archived
                $patient->status = Status::ARCHIVED;

                if (!$patient->save()) {
                    return $this->respondWithError($patient->getMessages(), 422);
                }

                return $this->respondWithSuccess([
                    'message' => 'Patient archived successfully',
                    'patient_id' => $patient->id
                ]);
            });

        } catch (Exception $e) {
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Restore an archived or deleted patient to active status
     */
    public function restore(int $id): array {
        try {
            // Verify user has permission to restore patients
            if (!$this->isManagerOrHigher()) {
                return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 403);
            }

            // Find patient
            $patient = Patient::findFirst($id);
            if (!$patient) {
                return $this->respondWithError(Message::PATIENT_NOT_FOUND, 404);
            }

            // Check if patient is already active
            if ($patient->isActive()) {
                return $this->respondWithError('Patient is already active', 400);
            }

            // Restore patient within transaction
            return $this->withTransaction(function() use ($patient) {
                // Set status to active
                $patient->status = Status::ACTIVE;

                if (!$patient->save()) {
                    return $this->respondWithError($patient->getMessages(), 422);
                }

                return $this->respondWithSuccess([
                    'message' => 'Patient restored successfully',
                    'patient_id' => $patient->id
                ]);
            });

        } catch (Exception $e) {
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Get all patients
     * Restricted to Managers and Administrators only
     */
    public function getAll(): array {
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
                return $this->respondWithSuccess(Message::DB_NO_RECORDS, 204);

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
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Get a patient by ID
     */
    public function getById(int $id): array {
        try {
            // Verify user has permission to view patients
            if (!$this->isManagerOrHigher()) {
                return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 403);
            }

            // Find patient
            $patient = Patient::findFirst($id);
            if (!$patient) {
                return $this->respondWithError(Message::PATIENT_NOT_FOUND, 404);
            }

            return $this->respondWithSuccess($patient->toArray());

        } catch (Exception $e) {
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }
}