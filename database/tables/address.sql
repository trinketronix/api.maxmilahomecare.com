-- Drop table if exists with all its dependencies (triggers, foreign keys, etc.)
DROP TABLE IF EXISTS `address`;

-- Create the address table for the patients
CREATE TABLE `address` (
    -- Primary identification and HHAexchange linkage
    `id` BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT COMMENT 'Primary key identifier for the address, auto-incremented',
    `person_id` BIGINT UNSIGNED NOT NULL COMMENT 'Identifier for the person, user_id or patient_id',
    `person_type` TINYINT NOT NULL COMMENT 'Person type identifies if is a user or a patient, 0 = user, 1 = patient',

    -- Address and location details
    `type` VARCHAR(50) NOT NULL COMMENT 'Type of residence (e.g., Home, Apartment, Facility)',
    `address` VARCHAR(255) NOT NULL COMMENT 'Street address including apartment/unit number',
    `city` VARCHAR(100) NOT NULL COMMENT 'City name',
    `county` VARCHAR(100) NOT NULL COMMENT 'County name, no defaults',
    `state` CHAR(2) NOT NULL COMMENT 'US state code (2 letters)',
    `zipcode` CHAR(5) NOT NULL COMMENT 'US ZIP code (5 digits)',
    `country` VARCHAR(100) NOT NULL DEFAULT 'United States' COMMENT 'Country name',

    -- Geolocation coordinates
    `latitude` DECIMAL(17,15) DEFAULT NULL COMMENT 'Geographic latitude coordinate for address location',
    `longitude` DECIMAL(17,15) DEFAULT NULL COMMENT 'Geographic longitude coordinate for address location',

    -- Audit timestamps
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'Record creation timestamp (UTC)',
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last modification timestamp (UTC)',

    -- Indexes for performance
    INDEX `idx_person` (`person_id`, `person_type`),
    INDEX `idx_location` (`state`, `city`),
    INDEX `idx_zipcode` (`zipcode`),
    INDEX `idx_coordinates` (`latitude`, `longitude`),

    -- Constraints
    CONSTRAINT `chk_state` CHECK (LENGTH(state) = 2),
    CONSTRAINT `chk_zipcode` CHECK (LENGTH(zipcode) = 5),
    CONSTRAINT `chk_coordinates` CHECK (
       latitude IS NULL OR (latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180)
       ),
    CONSTRAINT `chk_person_type` CHECK (person_type IN (0,1))
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci
COMMENT='Addresses for users and patients';


-- 1. Update constraint for Community Address
ALTER TABLE `address`
DROP CONSTRAINT `chk_person_type`,
ADD CONSTRAINT `chk_person_type` CHECK (person_type IN (-1, 0, 1));

-- 2. Enable inserting 0
SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO';

-- 3. Insert system address
INSERT INTO `address` (id, person_id, person_type, type, address, city, county, state, zipcode)
VALUES (0, 0, -1, 'System', 'Community', 'Maxmila', 'System', 'XX', 'ABCDE');

-- That's it! Next insert will automatically be 1244