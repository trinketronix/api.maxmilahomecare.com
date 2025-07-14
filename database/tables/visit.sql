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