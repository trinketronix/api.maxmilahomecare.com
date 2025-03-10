-- Drop table if exists with all its dependencies (triggers, foreign keys, etc.)
DROP TABLE IF EXISTS `visit`;

-- Create the visit table for tracking patient visits
CREATE TABLE `visit` (
    -- Primary identification
    `id` BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT COMMENT 'Primary key, unique identifier for the visit, auto-incremented',

    -- Related foreign ids
    `user_id` BIGINT UNSIGNED NOT NULL COMMENT 'Identifier for the user',
    `patient_id` BIGINT UNSIGNED NOT NULL COMMENT 'Identifier for the patient',

    -- Visit information
    `start_time` DATETIME NOT NULL COMMENT 'Visit start date and time',
    `end_time` DATETIME NOT NULL COMMENT 'Visit end date and time',
    `note` TEXT CHARACTER SET utf8mb4 DEFAULT NULL COMMENT 'Visit note, comment, observation etc',

    -- Visit status information
    `progress` TINYINT NOT NULL DEFAULT 0 COMMENT 'Visit progress: cancel=-1, scheduled/to-do=0, checkin/in-progress=1, checkout/completed=2, approved/paid=3',

    -- Record status, allows to delete records in soft-deletion, archived, or just normal active
    `status` TINYINT NOT NULL DEFAULT 0 COMMENT 'Record status: 1=Active/Visible/Normal, 2=Archived, 3=Soft-Deleted',

    -- Audit timestamps
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'Timestamp when the account was created (UTC)',
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Timestamp of last record update (UTC)',

    -- Indexes
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_patient_id` (`patient_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_dates` (`start_time`, `end_time`),

    -- Constraints
    CONSTRAINT `chk_progress` CHECK(`progress` IN (-1,0,1,2,3)),
    CONSTRAINT `chk_status` CHECK(`status` IN (1,2,3)),
    CONSTRAINT `chk_dates` CHECK(`end_time` >= `start_time`)
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci
COMMENT='Visit tracking table for patient care management';
