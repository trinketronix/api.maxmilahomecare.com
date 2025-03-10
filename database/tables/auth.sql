-- Drop table if exists with all its dependencies (triggers, foreign keys, etc.)
DROP TABLE IF EXISTS `auth`;

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