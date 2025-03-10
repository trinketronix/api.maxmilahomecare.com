-- Drop table if it already exists
DROP TABLE IF EXISTS `tool`;

-- Create the tool table
CREATE TABLE `tool` (
    -- Primary identification
                        `id` BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT COMMENT 'Unique identifier for the tool',

    -- Tool information
                        `name` VARCHAR(100) NOT NULL COMMENT 'Name of the tool',
                        `description` TEXT NOT NULL COMMENT 'Description of what the tool is used for',
                        `material` VARCHAR(100) DEFAULT NULL COMMENT 'Primary material the tool is made of',
                        `inventor` VARCHAR(100) DEFAULT NULL COMMENT 'Person who invented the tool',
                        `year` INT DEFAULT NULL COMMENT 'Year the tool was invented',

    -- Audit timestamps
                        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'Record creation timestamp (UTC)',
                        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last modification timestamp (UTC)',

    -- Indexes for performance
                        INDEX `idx_name` (`name`),
                        INDEX `idx_year` (`year`),
                        INDEX `idx_inventor` (`inventor`)

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci
COMMENT='Tools catalog for API performance testing';