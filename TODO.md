# TODO - Subject Drop Notification Feature

## Task
When admin drops a subject from a student:
1. Notify the student by displaying the drop message on the notification dashboard
2. Update the status of the subject into "drop" in enrolled subjects

## Plan

### Step 1: Modify admin/student_enrollments.php
- [x] Change the "drop" action from DELETE to UPDATE enrollment_status = 'dropped' ✓
- [x] Add re-enroll functionality for dropped subjects ✓
- [x] Verify notification is created for the student ✓

### Step 2: Verify student notification display
- [x] student/index.php already displays notifications from tbl_notifications ✓

### Step 3: Verify student records display
- [x] student/records.php already displays enrollment_status correctly ✓

### Step 4: Update student/enroll.php display
- [x] Show correct status badge (Enrolled/Pending/Dropped) ✓

## Status: COMPLETED ✓
Features implemented:
1. Drop action updates enrollment status to "dropped" instead of deleting
2. Creates notification "Subject Dropped by Admin" for the student
3. Student sees dropped subject in enrollment records with "dropped" status
4. Student receives notification on their notification dashboard
5. Admin can re-enroll dropped students with "Re-enroll" button
6. Re-enrollment sends notification "Subject Re-enrolled by Admin"

