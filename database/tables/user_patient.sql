-- Drop table if exists with all its dependencies
DROP TABLE IF EXISTS `user_patient`;

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