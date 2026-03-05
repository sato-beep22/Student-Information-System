-- Schema update: Section-based student management
-- Removes subject capacity, adds section management per course

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";

-- ============================================
-- CREATE SECTIONS TABLE
-- ============================================

CREATE TABLE IF NOT EXISTS `tbl_sections` (
  `section_id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `course_id` INT(11) NOT NULL,
  `section_name` ENUM('A', 'B', 'C') NOT NULL,
  `capacity` INT(11) NOT NULL DEFAULT 30,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_course_section` (`course_id`, `section_name`),
  CONSTRAINT `tbl_sections_course_fk` FOREIGN KEY (`course_id`) REFERENCES `tbl_course` (`course_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================
-- ADD SECTION TO USERS
-- ============================================

ALTER TABLE `tbl_users` ADD COLUMN IF NOT EXISTS `section_id` INT(11) NULL AFTER `course_id`;
ALTER TABLE `tbl_users` ADD CONSTRAINT `tbl_users_section_fk` FOREIGN KEY (`section_id`) REFERENCES `tbl_sections` (`section_id`) ON DELETE SET NULL;

-- ============================================
-- REMOVE CAPACITY FROM SUBJECTS (if exists)
-- ============================================

ALTER TABLE `tbl_subjects` DROP COLUMN IF EXISTS `max_capacity`;

-- ============================================
-- SAMPLE DATA: Create sections for existing courses
-- ============================================

INSERT IGNORE INTO `tbl_sections` (`course_id`, `section_name`, `capacity`) 
SELECT DISTINCT `course_id`, 'A', 30 FROM `tbl_course`;

INSERT IGNORE INTO `tbl_sections` (`course_id`, `section_name`, `capacity`) 
SELECT DISTINCT `course_id`, 'B', 30 FROM `tbl_course`;

INSERT IGNORE INTO `tbl_sections` (`course_id`, `section_name`, `capacity`) 
SELECT DISTINCT `course_id`, 'C', 30 FROM `tbl_course`;
