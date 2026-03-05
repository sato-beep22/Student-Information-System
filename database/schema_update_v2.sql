-- Schema updates v2 for SIS Academic Performance Tracking
-- Run this after schema_update.sql

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- ============================================
-- ENROLLMENTS: Add academic status fields
-- ============================================

-- Add academic_status to enrollments (pending/passed/failed)
ALTER TABLE `tbl_enrollments` ADD COLUMN IF NOT EXISTS `academic_status` ENUM('pending', 'passed', 'failed') NOT NULL DEFAULT 'pending' AFTER `status`;

-- Add enrollment_status for warning/drop tracking (active/warning/dropped)
ALTER TABLE `tbl_enrollments` ADD COLUMN IF NOT EXISTS `enrollment_status` ENUM('active', 'warning', 'dropped') NOT NULL DEFAULT 'active' AFTER `academic_status`;

-- Add grade field
ALTER TABLE `tbl_enrollments` ADD COLUMN IF NOT EXISTS `grade` DECIMAL(5,2) NULL AFTER `enrollment_status`;

-- ============================================
-- SUBJECTS: Add max capacity field
-- ============================================

ALTER TABLE `tbl_subjects` ADD COLUMN IF NOT EXISTS `max_capacity` INT(11) NOT NULL DEFAULT 30 AFTER `units`;

-- ============================================
-- ATTENDANCE: Track absences and tardiness
-- ============================================

CREATE TABLE IF NOT EXISTS `tbl_attendance` (
  `attendance_id` INT(11) NOT NULL AUTO_INCREMENT,
  `enrollment_id` INT(11) NOT NULL,
  `attendance_date` DATE NOT NULL,
  `status` ENUM('present', 'absent', 'tardy') NOT NULL DEFAULT 'present',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  PRIMARY KEY (`attendance_id`),
  KEY `enrollment_id` (`enrollment_id`),
  KEY `attendance_date` (`attendance_date`),
  CONSTRAINT `tbl_attendance_enrollment_fk` FOREIGN KEY (`enrollment_id`) REFERENCES `tbl_enrollments` (`enrollment_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================
-- GRADE REQUESTS: Grade review workflow
-- ============================================

CREATE TABLE IF NOT EXISTS `tbl_grade_requests` (
  `request_id` INT(11) NOT NULL AUTO_INCREMENT,
  `enrollment_id` INT(11) NOT NULL,
  `student_id` INT(11) NOT NULL,
  `reason` TEXT NOT NULL,
  `status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
  `admin_notes` TEXT DEFAULT NULL,
  `processed_by` INT(11) DEFAULT NULL,
  `processed_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  PRIMARY KEY (`request_id`),
  KEY `enrollment_id` (`enrollment_id`),
  KEY `student_id` (`student_id`),
  KEY `processed_by` (`processed_by`),
  CONSTRAINT `tbl_grade_requests_enrollment_fk` FOREIGN KEY (`enrollment_id`) REFERENCES `tbl_enrollments` (`enrollment_id`) ON DELETE CASCADE,
  CONSTRAINT `tbl_grade_requests_student_fk` FOREIGN KEY (`student_id`) REFERENCES `tbl_users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `tbl_grade_requests_admin_fk` FOREIGN KEY (`processed_by`) REFERENCES `tbl_users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================
-- ACTIVITY UPLOADS: Quiz/Activity file uploads
-- ============================================

CREATE TABLE IF NOT EXISTS `tbl_activity_uploads` (
  `upload_id` INT(11) NOT NULL AUTO_INCREMENT,
  `enrollment_id` INT(11) NOT NULL,
  `student_id` INT(11) NOT NULL,
  `file_name` VARCHAR(255) NOT NULL,
  `file_path` VARCHAR(500) NOT NULL,
  `file_type` VARCHAR(100) NOT NULL,
  `file_size` INT(11) NOT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `uploaded_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  PRIMARY KEY (`upload_id`),
  KEY `enrollment_id` (`enrollment_id`),
  KEY `student_id` (`student_id`),
  CONSTRAINT `tbl_activity_uploads_enrollment_fk` FOREIGN KEY (`enrollment_id`) REFERENCES `tbl_enrollments` (`enrollment_id`) ON DELETE CASCADE,
  CONSTRAINT `tbl_activity_uploads_student_fk` FOREIGN KEY (`student_id`) REFERENCES `tbl_users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================
-- CONFIG: Store system settings
-- ============================================

CREATE TABLE IF NOT EXISTS `tbl_settings` (
  `setting_id` INT(11) NOT NULL AUTO_INCREMENT,
  `setting_key` VARCHAR(100) NOT NULL UNIQUE,
  `setting_value` TEXT DEFAULT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  PRIMARY KEY (`setting_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Insert default settings
INSERT IGNORE INTO `tbl_settings` (`setting_key`, `setting_value`, `description`) VALUES
('grade_pass_threshold', '75', 'Minimum grade to pass (>= 75)'),
('max_consecutive_absences', '3', 'Number of consecutive absences before dropping'),
('default_section_capacity', '30', 'Default maximum students per section'),
('max_upload_size', '2097152', 'Maximum file upload size in bytes (2MB)'),
('allowed_file_types', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'Allowed MIME types for uploads');

-- ============================================
-- NOTIFICATIONS: Student notifications
-- ============================================

CREATE TABLE IF NOT EXISTS `tbl_notifications` (
  `notification_id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `message` TEXT NOT NULL,
  `type` ENUM('info', 'warning', 'danger', 'success') NOT NULL DEFAULT 'info',
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  PRIMARY KEY (`notification_id`),
  KEY `user_id` (`user_id`),
  KEY `is_read` (`is_read`),
  CONSTRAINT `tbl_notifications_user_fk` FOREIGN KEY (`user_id`) REFERENCES `tbl_users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
