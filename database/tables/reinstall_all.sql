DROP VIEW IF EXISTS `user_auth`;
DROP VIEW IF EXISTS `ordered_visits`;
DROP TABLE IF EXISTS `auth`;
DROP TABLE IF EXISTS `visit`;
DROP TABLE IF EXISTS `user_patient`;
DROP TABLE IF EXISTS `user`;
DROP TABLE IF EXISTS `patient`;
DROP TABLE IF EXISTS `address`;

-- Create the authentication table for user management and access control
CREATE TABLE `auth` (
    -- Primary identification
                        `id` BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT COMMENT 'Primary key, unique identifier for the user, auto-incremented',

    -- Authentication credentials
                        `username` VARCHAR(255) NOT NULL UNIQUE COMMENT 'Email address used as username, must be unique, used for login',
                        `password` CHAR(128) NOT NULL COMMENT 'SHA-512 hashed password, always 128 characters in length',

    -- Session management
                        `token` TEXT CHARACTER SET utf8mb4 DEFAULT NULL COMMENT 'JWT token for session management, null when no active session',
                        `expiration` BIGINT DEFAULT NULL COMMENT 'Token expiration timestamp in milliseconds since epoch (UTC), null when never login',

    -- Access control
                        `role` TINYINT NOT NULL DEFAULT 2 COMMENT 'User role hierarchy level:
    0 = Administrator (full access)
    1 = Manager (limited administrative access)
    2 = Caregiver (basic user access)',

    -- Account status
                        `status` TINYINT NOT NULL DEFAULT 0 COMMENT 'Account status flags:
    -1 = Not Verified (the user is pending of verification. Just for user authentication)
    0 = Deactivated (temporary suspension, like vacations, or personal leave)
    1 = Active (record is active and normally visible)
    2 = Archived (record archived)
    3 = Soft-Deleted (record marked for deletion)',

    -- Audit timestamps
                        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'Timestamp when the account was created (UTC)',
                        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Timestamp of last record update (UTC)',

    -- Indexes for performance
                        INDEX `idx_username` (`username`),
                        INDEX `idx_status` (`status`),
                        INDEX `idx_role` (`role`),
                        INDEX `idx_expiration` (`expiration`),  -- Added index for token expiration queries

    -- Constraints
                        CONSTRAINT `chk_role` CHECK(`role` IN (0,1,2)),
                        CONSTRAINT `chk_status` CHECK(`status` IN (-1,0,1,2,3))
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci
COMMENT='User Authentication table for user access control and session management';


-- Create table for storing user personal and contact information
CREATE TABLE `user` (
    -- Primary identification
                        `id` BIGINT UNSIGNED PRIMARY KEY COMMENT 'Primary key, linked to auth.id, not auto-incremented',

    -- Personal information
                        `lastname` VARCHAR(90) DEFAULT 'TBD' COMMENT 'User''s last/family name, defaults to TBD until updated',
                        `firstname` VARCHAR(90) DEFAULT 'TBD' COMMENT 'User''s first/given name, defaults to TBD until updated',
                        `middlename` VARCHAR(60) DEFAULT NULL COMMENT 'User''s middle name, optional',
                        `birthdate` DATE DEFAULT NULL COMMENT 'User''s date of birth in YYYY-MM-DD format',

    -- Sensitive information
                        `ssn` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Encrypted Social Security Number, stored with binary collation for encryption',

    -- Professional information
                        `code` VARCHAR(20) DEFAULT NULL COMMENT 'HHAexchange service provider identification code',

    -- Contact information
                        `phone` VARCHAR(20) DEFAULT NULL COMMENT 'Primary contact phone number',
                        `phone2` VARCHAR(20) DEFAULT NULL COMMENT 'Secondary/alternate phone number',
                        `email` VARCHAR(255) NOT NULL COMMENT 'Primary email address, required',
                        `email2` VARCHAR(255) DEFAULT NULL COMMENT 'Secondary/alternate email address',

    -- Additional information
                        `languages` TEXT DEFAULT NULL COMMENT 'Comma-separated list of spoken languages',
                        `description` TEXT DEFAULT NULL COMMENT 'Extra information about the user',

    -- Profile media
                        `photo` VARCHAR(2048) DEFAULT NULL COMMENT 'URL to user''s profile picture/avatar',

    -- Audit timestamps
                        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'Record creation timestamp (UTC)',
                        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last modification timestamp (UTC)',

    -- Indexes for performance
                        INDEX `idx_email` (`email`),
                        INDEX `idx_code` (`code`),
                        INDEX `idx_name` (`lastname`, `firstname`)

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci
COMMENT='Stores user personal, contact, and professional information';

-- Create table for storing HHAexchange patient information and location data
CREATE TABLE `patient` (
    -- Primary identification and HHAexchange linkage
                           `id` BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT COMMENT 'Primary key maxmila identifier for the patient, auto-incremented',
                           `patient` VARCHAR(20) DEFAULT NULL COMMENT 'Patient ID from HHAexchange system, unique patient identifier',
                           `admission` VARCHAR(20) DEFAULT NULL COMMENT 'Admission ID from HHAexchange system, unique patient identifier',

    -- Personal information
                           `firstname` VARCHAR(100) NOT NULL COMMENT 'Patient''s first/given name',
                           `middlename` VARCHAR(100) DEFAULT NULL COMMENT 'Patient''s middle name, optional',
                           `lastname` VARCHAR(100) NOT NULL COMMENT 'Patient''s last/family name',
                           `gender` ENUM('male', 'female') DEFAULT NULL COMMENT 'Patient gender',
                           `birthdate` DATE DEFAULT NULL COMMENT 'Patient''s date of birth in YYYY-MM-DD format',

    -- Contact information
                           `phone` VARCHAR(20) NOT NULL COMMENT 'Primary contact phone number',
                           `phone2` VARCHAR(20) NOT NULL COMMENT 'Secondary contact phone number',
                           `phone3` VARCHAR(20) NOT NULL COMMENT 'Thirdly contact phone number',

    -- Record status, allows to delete records in soft-deletion, archived, or just normal active
                           `status` TINYINT NOT NULL DEFAULT 1 COMMENT 'Record status: 0=Waiting/Not-Active, 1=Active/Visible/Normal, 2=Archived, 3=Soft-Deleted',

    -- Profile media
                           `photo` VARCHAR(2048) DEFAULT '/patient/photo/default.jpg' COMMENT 'URL to patient''s profile picture/avatar',

    -- Audit timestamps
                           `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'Record creation timestamp (UTC)',
                           `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last modification timestamp (UTC)',

    -- Indexes for performance
                           INDEX `idx_admission` (`admission`),
                           INDEX `idx_name` (`lastname`, `firstname`)
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci
COMMENT='Stores patient information synchronized from HHAexchange system including location data';

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

-- Create the junction table for the many-to-many relationship between users and patients
CREATE TABLE `user_patient` (
    -- Composite primary key
                                `user_id` BIGINT UNSIGNED NOT NULL COMMENT 'Reference to user.id',
                                `patient_id` BIGINT UNSIGNED NOT NULL COMMENT 'Reference to patient.id',

    -- Assignment metadata
                                `assigned_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'When the assignment was created',
                                `assigned_by` BIGINT UNSIGNED NOT NULL COMMENT 'User ID of who made the assignment',
                                `notes` TEXT DEFAULT NULL COMMENT 'Optional notes about this assignment',

    -- Status
                                `status` TINYINT NOT NULL DEFAULT 1 COMMENT 'Assignment status: 0=Inactive, 1=Active',

    -- Audit timestamps
                                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'Record creation timestamp (UTC)',
                                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last modification timestamp (UTC)',

    -- Primary key
                                PRIMARY KEY (`user_id`, `patient_id`),

    -- Indexes
                                INDEX `idx_user_id` (`user_id`),
                                INDEX `idx_patient_id` (`patient_id`),
                                INDEX `idx_assigned_by` (`assigned_by`),
                                INDEX `idx_status` (`status`),

    -- Foreign keys
                                CONSTRAINT `fk_user_patient_user_id`
                                    FOREIGN KEY (`user_id`) REFERENCES `user` (`id`)
                                        ON DELETE CASCADE ON UPDATE CASCADE,

                                CONSTRAINT `fk_user_patient_patient_id`
                                    FOREIGN KEY (`patient_id`) REFERENCES `patient` (`id`)
                                        ON DELETE CASCADE ON UPDATE CASCADE,

                                CONSTRAINT `fk_user_patient_assigned_by`
                                    FOREIGN KEY (`assigned_by`) REFERENCES `user` (`id`)
                                        ON DELETE RESTRICT ON UPDATE CASCADE,

    -- Constraints
                                CONSTRAINT `chk_status` CHECK (`status` IN (0, 1))

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci
COMMENT='Junction table for many-to-many relationship between users and patients';

-- Drop table if exists with all its dependencies (triggers, foreign keys, etc.)
DROP TABLE IF EXISTS `visit`;

-- Create the visit table for tracking patient visits
CREATE TABLE `visit` (
    -- Primary identification
                         `id` BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT COMMENT 'Primary key, unique identifier for the visit, auto-incremented',

    -- Related foreign ids
                         `user_id` BIGINT UNSIGNED NOT NULL COMMENT 'Identifier for the user (caregiver)',
                         `patient_id` BIGINT UNSIGNED NOT NULL COMMENT 'Identifier for the patient',
                         `address_id` BIGINT UNSIGNED NOT NULL COMMENT 'Identifier for the patient address where the visit will take place',

    -- Visit information
                         `visit_date` DATE NOT NULL COMMENT 'Date of the visit',
                         `start_time` DATETIME DEFAULT NULL COMMENT 'Visit start time',
                         `end_time` DATETIME DEFAULT NULL COMMENT 'Visit end time (calculated from start_time + total_hours)',
                         `total_hours` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Visit total of hours',
                         `note` TEXT CHARACTER SET utf8mb4 DEFAULT NULL COMMENT 'Visit note, comment, observation etc',

    -- Visit status information
                         `progress` TINYINT NOT NULL DEFAULT 0 COMMENT 'Visit progress: canceled=-1, scheduled=0, checkin=1, checkout=2, approved=3',

    -- User tracking for each state change
                         `scheduled_by` BIGINT UNSIGNED NOT NULL COMMENT 'User ID who scheduled the visit (defaults to user_id)',
                         `checkin_by` BIGINT UNSIGNED DEFAULT NULL COMMENT 'User ID who checked in (always user_id when set)',
                         `checkout_by` BIGINT UNSIGNED DEFAULT NULL COMMENT 'User ID who checked out (always user_id when set)',
                         `canceled_by` BIGINT UNSIGNED DEFAULT NULL COMMENT 'User ID who canceled the visit',
                         `approved_by` BIGINT UNSIGNED DEFAULT NULL COMMENT 'User ID who approved the visit (Manager/Admin only)',

    -- Record status
                         `status` TINYINT NOT NULL DEFAULT 1 COMMENT 'Record status: 1=Visible/Active, 2=Archived, 3=Soft-Deleted',

    -- Audit timestamps
                         `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'Timestamp when the visit was created (UTC)',
                         `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Timestamp of last record update (UTC)',

    -- Indexes
                         INDEX `idx_user_id` (`user_id`),
                         INDEX `idx_patient_id` (`patient_id`),
                         INDEX `idx_address_id` (`address_id`),
                         INDEX `idx_progress` (`progress`),
                         INDEX `idx_status` (`status`),
                         INDEX `idx_visit_date` (`visit_date`),
                         INDEX `idx_start_time` (`start_time`),
                         INDEX `idx_scheduled_by` (`scheduled_by`),
                         INDEX `idx_user_date_progress` (`user_id`, `visit_date`, `progress`),

    -- Constraints
                         CONSTRAINT `chk_progress` CHECK(`progress` IN (-1,0,1,2,3)),
                         CONSTRAINT `chk_status` CHECK(`status` IN (1,2,3)),
                         CONSTRAINT `chk_dates` CHECK(`end_time` IS NULL OR `end_time` >= `start_time`),
                         CONSTRAINT `chk_total_hours` CHECK(`total_hours` >= 1 AND `total_hours` <= 24),

    -- Foreign key constraints
                         CONSTRAINT `fk_visit_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`)
                             ON DELETE RESTRICT ON UPDATE CASCADE,
                         CONSTRAINT `fk_visit_patient` FOREIGN KEY (`patient_id`) REFERENCES `patient` (`id`)
                             ON DELETE RESTRICT ON UPDATE CASCADE,
                         CONSTRAINT `fk_visit_address` FOREIGN KEY (`address_id`) REFERENCES `address` (`id`)
                             ON DELETE RESTRICT ON UPDATE CASCADE,
                         CONSTRAINT `fk_visit_scheduled_by` FOREIGN KEY (`scheduled_by`) REFERENCES `user` (`id`)
                             ON DELETE RESTRICT ON UPDATE CASCADE,
                         CONSTRAINT `fk_visit_checkin_by` FOREIGN KEY (`checkin_by`) REFERENCES `user` (`id`)
                             ON DELETE SET NULL ON UPDATE CASCADE,
                         CONSTRAINT `fk_visit_checkout_by` FOREIGN KEY (`checkout_by`) REFERENCES `user` (`id`)
                             ON DELETE SET NULL ON UPDATE CASCADE,
                         CONSTRAINT `fk_visit_canceled_by` FOREIGN KEY (`canceled_by`) REFERENCES `user` (`id`)
                             ON DELETE SET NULL ON UPDATE CASCADE,
                         CONSTRAINT `fk_visit_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `user` (`id`)
                             ON DELETE SET NULL ON UPDATE CASCADE

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci
COMMENT='Visit tracking table for patient care management with specific address locations';

CREATE OR REPLACE VIEW user_auth AS
SELECT
    a.id,
    a.username,
    a.role,
    a.status,
    a.token,
    a.updated_at AS auth_updated_at,
    u.firstname,
    u.lastname,
    u.middlename,
    u.birthdate,
    u.ssn,
    u.code,
    u.phone,
    u.phone2,
    u.email,
    u.email2,
    u.languages,
    u.description,
    u.photo,
    u.created_at AS user_created_at,
    u.updated_at AS user_updated_at
FROM
    auth a
        INNER JOIN
    user u ON a.id = u.id;

-- Create view for visits with custom ordering
CREATE VIEW `ordered_visits` AS
SELECT
    v.*,
    CASE
        WHEN v.visit_date = CURDATE() AND v.progress = 1 THEN 1  -- Today's in-progress
        WHEN v.visit_date = CURDATE() AND v.progress = 0 THEN 2  -- Today's scheduled
        WHEN v.visit_date > CURDATE() AND v.progress = 0 THEN 3  -- Future scheduled
        WHEN v.progress = -1 THEN 4                              -- Canceled
        ELSE 5                                                   -- Past visits
        END AS sort_order
FROM visit v
ORDER BY
    sort_order,
    v.visit_date DESC,
    v.start_time DESC;