<?php

declare(strict_types=1);

namespace Api\Models;

use Api\Constants\PersonType;
use Phalcon\Filter\Validation;
use Phalcon\Filter\Validation\Validator\PresenceOf;
use Phalcon\Filter\Validation\Validator\InclusionIn;
use Phalcon\Filter\Validation\Validator\Regex;
use Phalcon\Filter\Validation\Validator\Numericality;
use Phalcon\Mvc\Model;
use Phalcon\Mvc\Model\Behavior\Timestampable;

class Address extends Model {
    // Common address types
    public const string HOUSE = 'House';
    public const string APARTMENT = 'Apartment';
    public const string CONDOMINIUM = 'Condominium';
    public const string TRAILER = 'Trailer';
    public const string OTHER = 'Other';

    // Default country
    public const string DEFAULT_COUNTRY = 'United States';

    // Column constants
    public const string ID = 'id';
    public const string PERSON_ID = 'person_id';
    public const string PERSON_TYPE = 'person_type';
    public const string TYPE = 'type';
    public const string ADDRESS = 'address';
    public const string CITY = 'city';
    public const string COUNTY = 'county';
    public const string STATE = 'state';
    public const string ZIPCODE = 'zipcode';
    public const string COUNTRY = 'country';
    public const string LATITUDE = 'latitude';
    public const string LONGITUDE = 'longitude';
    public const string CREATED_AT = 'created_at';
    public const string UPDATED_AT = 'updated_at';

    // Primary identification
    public ?int $id = null;
    public int $person_id;
    public int $person_type;

    // Address details
    public string $type;
    public string $address;
    public string $city;
    public string $county;
    public string $state;
    public string $zipcode;
    public string $country = self::DEFAULT_COUNTRY;

    // Geolocation
    public ?float $latitude = null;
    public ?float $longitude = null;

    // Timestamps
    public string $created_at;
    public string $updated_at;

    /**
     * Initialize model relationships and behaviors
     */
    public function initialize(): void {
        $this->setSource('address');

        // Define polymorphic relationships
        $this->belongsTo(
            'person_id',
            User::class,
            'id',
            [
                'alias' => 'user',
                'foreignKey' => [
                    'conditions' => 'person_type = ' . PersonType::USER
                ],
                'reusable' => true
            ]
        );

        $this->belongsTo(
            'person_id',
            Patient::class,
            'id',
            [
                'alias' => 'patient',
                'foreignKey' => [
                    'conditions' => 'person_type = ' . PersonType::PATIENT
                ],
                'reusable' => true
            ]
        );

        // Add automatic timestamp behavior
        $this->addBehavior(
            new Timestampable([
                'beforeCreate' => [
                    'field' => 'created_at',
                    'format' => 'Y-m-d H:i:s'
                ],
                'beforeUpdate' => [
                    'field' => 'updated_at',
                    'format' => 'Y-m-d H:i:s'
                ]
            ])
        );
    }

    /**
     * Model validation
     */
    public function validation(): bool {
        $validator = new Validation();

        // Required fields validation
        $requiredFields = [
            self::PERSON_ID => 'Person ID is required',
            self::TYPE => 'Address type is required',
            self::ADDRESS => 'Street address is required',
            self::CITY => 'City is required',
            self::COUNTY => 'County is required',
            self::STATE => 'State is required',
            self::ZIPCODE => 'ZIP code is required'
        ];

        foreach ($requiredFields as $field => $message) {
            $validator->add(
                $field,
                new PresenceOf([
                    'message' => $message
                ])
            );
        }

        // Person type validation
        $validator->add(
            self::PERSON_TYPE,
            new InclusionIn([
                'domain' => [PersonType::USER, PersonType::PATIENT],
                'message' => 'Invalid person type'
            ])
        );

        // State format validation
        $validator->add(
            self::STATE,
            new Regex([
                'pattern' => '/^[A-Z]{2}$/',
                'message' => 'State must be a 2-letter code'
            ])
        );

        // ZIP code validation
        $validator->add(
            self::ZIPCODE,
            new Regex([
                'pattern' => '/^\d{5}$/',
                'message' => 'ZIP code must be 5 digits'
            ])
        );

        // Coordinate validation if provided
        if (!is_null($this->latitude)) {
            $validator->add(
                self::LATITUDE,
                new Numericality([
                    'min' => -90,
                    'max' => 90,
                    'message' => 'Latitude must be between -90 and 90'
                ])
            );
        }

        if (!is_null($this->longitude)) {
            $validator->add(
                self::LONGITUDE,
                new Numericality([
                    'min' => -180,
                    'max' => 180,
                    'message' => 'Longitude must be between -180 and 180'
                ])
            );
        }

        return $this->validate($validator);
    }

    /**
     * Actions before saving the model
     */
    public function beforeSave(): bool {
        // Convert state to uppercase
        if (isset($this->state)) {
            $this->state = strtoupper($this->state);
        }

        // Set default country if empty
        if (empty($this->country)) {
            $this->country = self::DEFAULT_COUNTRY;
        }

        return true;
    }

    /**
     * Get the associated person (either user or patient)
     */
    public function getPerson(): ?Model {
        if ($this->person_type === PersonType::USER) {
            return $this->user;
        } else if ($this->person_type === PersonType::PATIENT) {
            return $this->patient;
        }

        return null;
    }

    /**
     * Get a formatted address string
     */
    public function getFormattedAddress(): string {
        $formatted = $this->address . "\n";
        $formatted .= $this->city . ", " . $this->state . " " . $this->zipcode;

        if ($this->country !== self::DEFAULT_COUNTRY) {
            $formatted .= "\n" . $this->country;
        }

        return $formatted;
    }

    /**
     * Check if this address has geolocation coordinates
     */
    public function hasCoordinates(): bool {
        return !is_null($this->latitude) && !is_null($this->longitude);
    }

    /**
     * Calculate distance to another address (in miles)
     * Uses Haversine formula
     */
    public function distanceTo(Address $other): ?float {
        if (!$this->hasCoordinates() || !$other->hasCoordinates()) {
            return null;
        }

        $earthRadius = 3958.8; // miles

        $latFrom = deg2rad((float)$this->latitude);
        $lonFrom = deg2rad((float)$this->longitude);
        $latTo = deg2rad((float)$other->latitude);
        $lonTo = deg2rad((float)$other->longitude);

        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        $angle = 2 * asin(sqrt(pow(sin($latDelta / 2), 2) +
                cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)));

        return $angle * $earthRadius;
    }

    /**
     * Find addresses by person ID and type
     */
    public static function findByPerson(int $personId, int $personType): \Phalcon\Mvc\Model\ResultsetInterface {
        return self::find([
            'conditions' => 'person_id = :person_id: AND person_type = :person_type:',
            'bind' => [
                'person_id' => $personId,
                'person_type' => $personType
            ],
            'bindTypes' => [
                'person_id' => \PDO::PARAM_INT,
                'person_type' => \PDO::PARAM_INT
            ],
            'order' => 'created_at DESC'
        ]);
    }

    /**
     * Find addresses within a certain radius of coordinates
     */
    public static function findNearby(float $latitude, float $longitude, float $radiusMiles): \Phalcon\Mvc\Model\ResultsetInterface {
        // This simplified version uses a square boundary
        // For a more accurate radius search, you'd need a raw SQL query using the Haversine formula
        $latDelta = $radiusMiles / 69; // ~69 miles per degree of latitude
        $lonDelta = $radiusMiles / (cos(deg2rad($latitude)) * 69); // Adjust for longitude

        return self::find([
            'conditions' => 'latitude BETWEEN :lat_min: AND :lat_max: AND longitude BETWEEN :lon_min: AND :lon_max:',
            'bind' => [
                'lat_min' => $latitude - $latDelta,
                'lat_max' => $latitude + $latDelta,
                'lon_min' => $longitude - $lonDelta,
                'lon_max' => $longitude + $lonDelta
            ],
            'bindTypes' => [
                'lat_min' => \PDO::PARAM_STR,
                'lat_max' => \PDO::PARAM_STR,
                'lon_min' => \PDO::PARAM_STR,
                'lon_max' => \PDO::PARAM_STR
            ]
        ]);
    }

    /**
     * Get common address types
     */
    public static function getAddressTypes(): array {
        return [
            self::HOUSE,
            self::APARTMENT,
            self::CONDOMINIUM,
            self::TRAILER,
            self::OTHER
        ];
    }
}