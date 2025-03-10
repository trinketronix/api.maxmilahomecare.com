-- Drop table if exists with all its dependencies
DROP TABLE IF EXISTS `patient`;

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

    -- Contact information
    `phone` VARCHAR(20) NOT NULL COMMENT 'Primary contact phone number',

    -- Record status, allows to delete records in soft-deletion, archived, or just normal active
    `status` TINYINT NOT NULL DEFAULT 0 COMMENT 'Record status: 0=Active/Visible/Normal, 1=Archived, 2=Soft-Deleted',

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