<?php

declare(strict_types=1);

namespace Api\Controllers;

use Api\Constants\PersonType;
use Exception;
use Api\Models\Address;
use Api\Models\User;
use Api\Models\Patient;
use Api\Models\Visit;
use Api\Constants\Message;

class AddressController extends BaseController {
    /**
     * Create a new address
     */
    public function create(): array {
        try {
            $data = $this->getRequestBody();

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

            foreach ($requiredFields as $field => $message) {
                if ($field === Address::PERSON_TYPE) {
                    // Special handling for person_type since 0 is a valid value
                    if (!isset($data[$field]) || ($data[$field] === '' && $data[$field] !== 0)) {
                        return $this->respondWithError($message, 400);
                    }
                } else if (empty($data[$field])) {
                    return $this->respondWithError($message, 400);
                }
            }

            // Special handling for person_type since 0 is a valid value
            if (!isset($data[Address::PERSON_TYPE]) || !is_numeric($data[Address::PERSON_TYPE])) {
                return $this->respondWithError('Person type is required and must be numeric', 400);
            }

            // Validate person type (the community address is system-owned and cannot be created here)
            $personType = (int)$data[Address::PERSON_TYPE]; // Explicitly cast to integer
            if (!in_array($personType, [PersonType::USER, PersonType::PATIENT], true)) {
                return $this->respondWithError('Invalid person type', 400);
            }

            // Authorization: users may add their own addresses; patient addresses are managed by managers/admins
            if ($personType === PersonType::USER) {
                if ((int)$data[Address::PERSON_ID] !== $this->getCurrentUserId() && !$this->isManagerOrHigher()) {
                    return $this->respondWithError(Message::UNAUTHORIZED_ACCESS, 403);
                }
            } elseif (!$this->isManagerOrHigher()) {
                return $this->respondWithError(Message::UNAUTHORIZED_ROLE, 403);
            }

            // Validate person exists
            if ($personType === PersonType::USER) {
                $person = User::findFirstById((int)$data[Address::PERSON_ID]);
                if (!$person) {
                    return $this->respondWithError(Message::USER_NOT_FOUND, 404);
                }
            } else {
                $person = Patient::findFirstById((int)$data[Address::PERSON_ID]);
                if (!$person) {
                    return $this->respondWithError(Message::PATIENT_NOT_FOUND, 404);
                }
            }

            // Validate state format
            if (!preg_match('/^[A-Z]{2}$/', strtoupper((string)$data[Address::STATE]))) {
                return $this->respondWithError('State must be a 2-letter code', 400);
            }

            // Validate ZIP code format
            if (!preg_match('/^\d{5}$/', $data[Address::ZIPCODE])) {
                return $this->respondWithError('ZIP code must be 5 digits', 400);
            }

            // Create new address within transaction
            return $this->withTransaction(function() use ($data) {
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
                        return $this->respondWithError('Invalid coordinates', 400);
                    }

                    $address->latitude = $latitude;
                    $address->longitude = $longitude;
                }

                if (!$address->save()) {
                    $messages = $address->getMessages(); // This is Phalcon\Messages\MessageInterface[]
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
                    'message' => 'Address created successfully',
                    'id' => $address->id
                ], 201, 'Address created successfully');
            });

        } catch (Exception $e) {
            $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
            error_log('Exception: ' . $message);
            return $this->handleException($e);
        }
    }

    /**
     * Get addresses for a person (user or patient)
     */
    public function getByPerson(int $personId, int $personType): array {
        try {
            // Validate personType
            if (!in_array($personType, [PersonType::USER, PersonType::PATIENT])) {
                return $this->respondWithError('Invalid person type', 400);
            }

            // Validate that person exists
            if ($personType === PersonType::USER) {
                $person = User::findFirst($personId);
                if (!$person) {
                    return $this->respondWithError(Message::USER_NOT_FOUND, 404);
                }

                // Check authorization for user addresses
                $currentUserId = $this->getCurrentUserId();
                if ($currentUserId !== $personId && !$this->isManagerOrHigher()) {
                    return $this->respondWithError(Message::UNAUTHORIZED_ACCESS, 403);
                }
            } else {
                $person = Patient::findFirst($personId);
                if (!$person) {
                    return $this->respondWithError(Message::PATIENT_NOT_FOUND, 404);
                }
            }

            // Find addresses
            $addresses = Address::findByPerson($personId, $personType);

            if ($addresses->count() === 0) {
                return $this->respondWithSuccess([], 200);
            }

            return $this->respondWithSuccess($addresses->toArray());

        } catch (Exception $e) {
            $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
            error_log('Exception: ' . $message);
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Get a specific address by ID
     */
    public function getById(int $id): array {
        try {
            $address = Address::findFirst($id);

            if (!$address) {
                return $this->respondWithError('Address not found', 404);
            }

            // Authorization: own address, any patient address (needed to schedule visits), or manager/admin
            if (!$this->canReadAddress($address)) {
                return $this->respondWithError(Message::UNAUTHORIZED_ACCESS, 403);
            }

            return $this->respondWithSuccess($address->toArray());

        } catch (Exception $e) {
            $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
            error_log('Exception: ' . $message);
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Update an existing address
     */
    public function update(int $id): array {
        try {
            $address = Address::findFirst($id);

            if (!$address) {
                return $this->respondWithError('Address not found', 404);
            }

            // Authorization: own address or manager/admin; the community address (id 0) is immutable
            if ((int)$address->id === 0) {
                return $this->respondWithError('The community address cannot be modified', 400);
            }
            if (!$this->canWriteAddress($address)) {
                return $this->respondWithError(Message::UNAUTHORIZED_ACCESS, 403);
            }

            $data = $this->getRequestBody();

            // Only update fields that are present in the request
            $updateFields = [
                Address::TYPE, Address::ADDRESS, Address::CITY, Address::COUNTY,
                Address::STATE, Address::ZIPCODE, Address::COUNTRY,
                Address::LATITUDE, Address::LONGITUDE
            ];

            // Start transaction
            return $this->withTransaction(function() use ($address, $data, $updateFields) {
                foreach ($updateFields as $field) {
                    if (isset($data[$field])) {
                        // Perform validation for specific fields
                        switch ($field) {
                            case Address::STATE:
                                if (!preg_match('/^[A-Z]{2}$/', strtoupper((string)$data[$field]))) {
                                    return $this->respondWithError('State must be a 2-letter code', 400);
                                }
                                $address->$field = strtoupper($data[$field]);
                                break;

                            case Address::ZIPCODE:
                                if (!preg_match('/^\d{5}$/', $data[$field])) {
                                    return $this->respondWithError('ZIP code must be 5 digits', 400);
                                }
                                $address->$field = $data[$field];
                                break;

                            case Address::LATITUDE:
                                $latitude = (float)$data[$field];
                                if ($latitude < -90 || $latitude > 90) {
                                    return $this->respondWithError('Latitude must be between -90 and 90', 400);
                                }
                                $address->$field = $latitude;
                                break;

                            case Address::LONGITUDE:
                                $longitude = (float)$data[$field];
                                if ($longitude < -180 || $longitude > 180) {
                                    return $this->respondWithError('Longitude must be between -180 and 180', 400);
                                }
                                $address->$field = $longitude;
                                break;

                            default:
                                $address->$field = $data[$field];
                                break;
                        }
                    }
                }

                if (!$address->save()) {
                    $messages = $address->getMessages(); // This is Phalcon\Messages\MessageInterface[]
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
                    'message' => 'Address updated successfully'
                ],201, 'Address updated successfully');
            });

        } catch (Exception $e) {
            $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
            error_log('Exception: ' . $message);
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Delete an address
     */
    public function delete(int $id): array {
        try {
            $address = Address::findFirst($id);

            if (!$address) {
                return $this->respondWithError('Address not found', 404);
            }

            // Authorization: own address or manager/admin; the community address (id 0) is immutable
            if ((int)$address->id === 0) {
                return $this->respondWithError('The community address cannot be modified', 400);
            }
            if (!$this->canWriteAddress($address)) {
                return $this->respondWithError(Message::UNAUTHORIZED_ACCESS, 403);
            }

            // Visits keep a RESTRICT foreign key to their address
            $visitCount = (int)Visit::count([
                'conditions' => 'address_id = :id:',
                'bind' => ['id' => (int)$address->id],
                'bindTypes' => ['id' => \PDO::PARAM_INT]
            ]);
            if ($visitCount > 0) {
                return $this->respondWithError("Address is used by $visitCount visit(s) and cannot be deleted", 409);
            }

            return $this->withTransaction(function() use ($address) {
                if (!$address->delete()) {
                    $messages = $address->getMessages(); // This is Phalcon\Messages\MessageInterface[]
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
                    'message' => 'Address deleted successfully'
                ], 201, 'Address deleted successfully');
            });

        } catch (Exception $e) {
            $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
            error_log('Exception: ' . $message);
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Find addresses within a specific radius of coordinates
     */
    public function findNearby(): array {
        try {
            // GET endpoint: parameters come from the query string
            // e.g. /address/nearby?latitude=42.33&longitude=-83.04&radius=10&person_type=1
            $data = $this->request->getQuery();

            // Validate required fields
            if (!isset($data['latitude']) || !isset($data['longitude']) || !isset($data['radius'])) {
                return $this->respondWithError('Latitude, longitude and radius are required', 400);
            }

            $latitude = (float)$data['latitude'];
            $longitude = (float)$data['longitude'];
            $radius = (float)$data['radius'];

            // Validate coordinate values
            if ($latitude < -90 || $latitude > 90) {
                return $this->respondWithError('Latitude must be between -90 and 90', 400);
            }

            if ($longitude < -180 || $longitude > 180) {
                return $this->respondWithError('Longitude must be between -180 and 180', 400);
            }

            if ($radius <= 0 || $radius > 100) {
                return $this->respondWithError('Radius must be between 0 and 100 miles', 400);
            }

            // Get additional filters
            $personType = isset($data['person_type']) && $data['person_type'] !== '' ? (int)$data['person_type'] : null;

            if ($personType !== null && !in_array($personType, [PersonType::USER, PersonType::PATIENT])) {
                return $this->respondWithError('Invalid person type', 400);
            }

            // Find nearby addresses (plain array: Phalcon's Resultset::filter() does not take a boolean predicate)
            $addresses = iterator_to_array(Address::findNearby($latitude, $longitude, $radius), false);

            // Filter by person type if specified
            if ($personType !== null) {
                $addresses = array_filter($addresses, fn (Address $a) => (int)$a->person_type === $personType);
            }

            // Caregivers only see patient/community addresses and their own
            if (!$this->isManagerOrHigher()) {
                $currentUserId = $this->getCurrentUserId();
                $addresses = array_filter($addresses, fn (Address $a) =>
                    (int)$a->person_type !== PersonType::USER || (int)$a->person_id === $currentUserId);
            }

            // Calculate distance for each address
            $result = [];
            foreach ($addresses as $address) {
                $distance = $this->calculateDistance(
                    $latitude,
                    $longitude,
                    (float)$address->latitude,
                    (float)$address->longitude
                );

                $addressData = $address->toArray();
                $addressData['distance_miles'] = round($distance, 2);
                $result[] = $addressData;
            }

            // Sort by distance
            usort($result, function($a, $b) {
                return $a['distance_miles'] <=> $b['distance_miles'];
            });

            return $this->respondWithSuccess($result);

        } catch (Exception $e) {
            $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
            error_log('Exception: ' . $message);
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Users can read their own addresses and any patient/community address; managers/admins read all.
     */
    private function canReadAddress(Address $address): bool {
        if ($this->isManagerOrHigher()) {
            return true;
        }
        if ((int)$address->person_type !== PersonType::USER) {
            return true;
        }
        return (int)$address->person_id === $this->getCurrentUserId();
    }

    /**
     * Users can modify only their own addresses; patient addresses require manager/admin.
     */
    private function canWriteAddress(Address $address): bool {
        if ($this->isManagerOrHigher()) {
            return true;
        }
        return (int)$address->person_type === PersonType::USER
            && (int)$address->person_id === $this->getCurrentUserId();
    }

    /**
     * Calculate distance between two coordinates using Haversine formula
     */
    private function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float {
        $earthRadius = 3958.8; // miles

        $latFrom = deg2rad($lat1);
        $lonFrom = deg2rad($lon1);
        $latTo = deg2rad($lat2);
        $lonTo = deg2rad($lon2);

        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        $angle = 2 * asin(sqrt(pow(sin($latDelta / 2), 2) +
                cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)));

        return $angle * $earthRadius;
    }
}