-- Drop table if exists with all its dependencies
DROP TABLE IF EXISTS `user`;

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