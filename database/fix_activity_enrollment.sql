-- Fix: Allow NULL for enrollment_id in tbl_activity_uploads
-- This allows admin to post activities without requiring a valid enrollment_id

ALTER TABLE `tbl_activity_uploads` MODIFY COLUMN `enrollment_id` INT(11) NULL;

-- Also update the foreign key to allow NULL
ALTER TABLE `tbl_activity_uploads` DROP FOREIGN KEY `tbl_activity_uploads_enrollment_fk`;

ALTER TABLE `tbl_activity_uploads` ADD CONSTRAINT `tbl_activity_uploads_enrollment_fk` 
    FOREIGN KEY (`enrollment_id`) REFERENCES `tbl_enrollments` (`enrollment_id`) ON DELETE CASCADE;
