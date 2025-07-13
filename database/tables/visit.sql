-- Drop table if exists with all its dependencies
DROP TABLE IF EXISTS `visit`;

-- Create the visit table for tracking patient visits by caregivers
CREATE TABLE `visit` (
    -- Primary identification
    `id` BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT COMMENT 'Primary key, unique identifier for the visit, auto-incremented',

    -- Related foreign ids
    `caregiver_id` BIGINT UNSIGNED NOT NULL COMMENT 'Identifier for the user (caregiver) assigned to the visit',
    `patient_id` BIGINT UNSIGNED NOT NULL COMMENT 'Identifier for the patient receiving the visit',
    `address_id` BIGINT UNSIGNED NOT NULL COMMENT 'Identifier for the patient address where the visit takes place',

    -- === SCHEDULING / PLANNING Information (Required at creation) ===
    `visit_date` DATE NOT NULL COMMENT 'The specific date the visit is scheduled for',
    `scheduled_hours` DECIMAL(4,2) NOT NULL COMMENT 'The planned duration of the visit in hours (e.g., 2.5 for 2h 30m)',

    -- === ACTUAL / LOGGED Information (NULL until check-in/out occurs) ===
    `actual_start_datetime` DATETIME NULL DEFAULT NULL COMMENT 'The actual timestamp when the caregiver checked in (UTC)',
    `actual_end_datetime` DATETIME NULL DEFAULT NULL COMMENT 'The actual timestamp when the caregiver checked out (UTC)',
    `actual_duration_minutes` INT UNSIGNED AS (
        CASE
            WHEN actual_start_datetime IS NOT NULL AND actual_end_datetime IS NOT NULL
            THEN TIMESTAMPDIFF(MINUTE, actual_start_datetime, actual_end_datetime)
            ELSE NULL
        END
    ) STORED COMMENT 'Automatically calculated actual visit duration in minutes. Stored for indexing.',

    -- General Visit Information
    `note` TEXT CHARACTER SET utf8mb4 DEFAULT NULL COMMENT 'Visit note, comment, observation etc.',

    -- Visit status and history tracking
    `progress` ENUM(
        'SCHEDULED',      -- To-do, not yet started
        'IN_PROGRESS',    -- Caregiver has checked in
        'COMPLETED',      -- Caregiver has checked out
        'APPROVED',       -- Admin/system has approved for payroll
        'CANCELED'        -- Visit was canceled
    ) NOT NULL DEFAULT 'SCHEDULED' COMMENT 'The current stage of the visit workflow',

    -- Audit trail for state changes (who and when)
    `scheduled_by` BIGINT UNSIGNED DEFAULT NULL COMMENT 'User ID who created the visit record',
    `checkin_by` BIGINT UNSIGNED DEFAULT NULL COMMENT 'User ID who checked in the visit',
    `checkout_by` BIGINT UNSIGNED DEFAULT NULL COMMENT 'User ID who checked out/completed the visit',
    `canceled_by` BIGINT UNSIGNED DEFAULT NULL COMMENT 'User ID who canceled the visit',
    `approved_by` BIGINT UNSIGNED DEFAULT NULL COMMENT 'User ID who approved the visit for payment',

    `checkin_at` DATETIME DEFAULT NULL COMMENT 'Timestamp when the visit was checked in (redundant with actual_start_datetime but useful for audit clarity)',
    `checkout_at` DATETIME DEFAULT NULL COMMENT 'Timestamp when the visit was checked out (redundant with actual_end_datetime but useful for audit clarity)',
    `canceled_at` DATETIME DEFAULT NULL COMMENT 'Timestamp when the visit was canceled',
    `approved_at` DATETIME DEFAULT NULL COMMENT 'Timestamp when the visit was approved',

    -- Record status, allows for soft-deletion or archiving
    `status` TINYINT NOT NULL DEFAULT 1 COMMENT 'Record status: 1=Active, 2=Archived, 3=Soft-Deleted',

    -- Record-level audit timestamps
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'Timestamp when the record was created (UTC)',
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Timestamp of last record update (UTC)',

    -- Indexes
    INDEX `idx_caregiver_id` (`caregiver_id`),
    INDEX `idx_patient_id` (`patient_id`),
    INDEX `idx_progress` (`progress`),
    INDEX `idx_status` (`status`),
    INDEX `idx_visit_date` (`visit_date`), -- Crucial for finding visits on a certain day
    INDEX `idx_actual_datetimes` (`actual_start_datetime`, `actual_end_datetime`),
    INDEX `idx_caregiver_schedule` (`caregiver_id`, `visit_date`), -- For quickly finding a caregiver's daily schedule
    INDEX `idx_patient_schedule` (`patient_id`, `visit_date`),   -- For quickly finding a patient's daily schedule

    -- Constraints
    CONSTRAINT `chk_status` CHECK(`status` IN (1,2,3)),
    CONSTRAINT `chk_actual_datetimes` CHECK (`actual_end_datetime` IS NULL OR `actual_start_datetime` IS NULL OR `actual_end_datetime` >= `actual_start_datetime`),

    -- Foreign key constraints (renamed for clarity)
    CONSTRAINT `fk_visit_caregiver` FOREIGN KEY (`caregiver_id`) REFERENCES `user` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_visit_patient` FOREIGN KEY (`patient_id`) REFERENCES `patient` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_visit_address` FOREIGN KEY (`address_id`) REFERENCES `address` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_visit_scheduled_by` FOREIGN KEY (`scheduled_by`) REFERENCES `user` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_visit_checkin_by` FOREIGN KEY (`checkin_by`) REFERENCES `user` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_visit_checkout_by` FOREIGN KEY (`checkout_by`) REFERENCES `user` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_visit_canceled_by` FOREIGN KEY (`canceled_by`) REFERENCES `user` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_visit_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `user` (`id`) ON DELETE SET NULL ON UPDATE CASCADE

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci
COMMENT='Tracks patient care visits, separating planned schedule from actual logged times.';