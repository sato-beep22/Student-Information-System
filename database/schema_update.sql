-- Schema updates to align with SIS spec (run after sis_db.sql if needed)
-- Adds: username, profiles table, course description, subject units, enrollment status

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- Add username to users (run once; skip if column already exists)
ALTER TABLE `tbl_users` ADD COLUMN `username` VARCHAR(80) NULL UNIQUE AFTER `user_id`;
UPDATE `tbl_users` SET `username` = `email` WHERE `username` IS NULL OR `username` = '';

-- Profiles table
CREATE TABLE IF NOT EXISTS `tbl_profiles` (
  `user_id` INT(11) NOT NULL,
  `first_name` VARCHAR(100) NOT NULL,
  `last_name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(100) NOT NULL,
  `phone` VARCHAR(50) DEFAULT NULL,
  `address` TEXT DEFAULT NULL,
  `gender` ENUM('male','female','other','prefer_not_to_say') DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `tbl_profiles_user_fk` FOREIGN KEY (`user_id`) REFERENCES `tbl_users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Backfill profiles from existing users
INSERT IGNORE INTO `tbl_profiles` (`user_id`, `first_name`, `last_name`, `email`, `phone`, `address`, `gender`)
SELECT `user_id`,
  COALESCE(SUBSTRING_INDEX(TRIM(`full_name`), ' ', 1), 'User'),
  COALESCE(NULLIF(TRIM(SUBSTRING(TRIM(`full_name`), LOCATE(' ', TRIM(`full_name`)) + 1)), ''), ''),
  `email`, NULL, NULL, NULL
FROM `tbl_users`
WHERE NOT EXISTS (SELECT 1 FROM `tbl_profiles` p WHERE p.user_id = tbl_users.user_id);

-- Course: add description (skip if already added)
ALTER TABLE `tbl_course` ADD COLUMN `description` TEXT NULL AFTER `course_code`;

-- Subjects: add units (skip if already added)
ALTER TABLE `tbl_subjects` ADD COLUMN `units` INT(11) NOT NULL DEFAULT 3 AFTER `subject_name`;

-- Enrollments: add status (skip if already added)
ALTER TABLE `tbl_enrollments` ADD COLUMN `status` ENUM('pending','enrolled') NOT NULL DEFAULT 'enrolled' AFTER `subject_id`;

-- Ensure passwords are hashed (run once; default demo passwords below)
-- UPDATE tbl_users SET password = '$2y$10$...' WHERE ...;
