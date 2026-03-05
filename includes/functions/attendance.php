<?php
/**
 * Attendance Functions
 * Handles recording and tracking student attendance
 */

require_once dirname(dirname(__DIR__)) . '/config/database.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions/academic.php';

/**
 * Create a notification for a student
 */
function createNotification(int $userId, string $title, string $message, string $type = 'info'): bool {
    $pdo = getDb();
    
    // Check if notifications table exists
    try {
        $stmt = $pdo->prepare('
            INSERT INTO tbl_notifications (user_id, title, message, type, created_at) 
            VALUES (?, ?, ?, ?, NOW())
        ');
        return $stmt->execute([$userId, $title, $message, $type]);
    } catch (Exception $e) {
        // Table might not exist
        return false;
    }
}

/**
 * Send warning/drop notification to student
 */
function sendWarningDropNotification(int $enrollmentId, string $status): bool {
    $pdo = getDb();
    
    // Get enrollment details
    $stmt = $pdo->prepare('
        SELECT 
            e.*,
            u.user_id as student_id,
            u.full_name as student_name,
            s.subject_code,
            s.subject_name
        FROM tbl_enrollments e
        JOIN tbl_users u ON u.user_id = e.student_id
        JOIN tbl_subjects s ON s.subject_id = e.subject_id
        WHERE e.enrollment_id = ?
    ');
    $stmt->execute([$enrollmentId]);
    $enrollment = $stmt->fetch();
    
    if (!$enrollment) {
        return false;
    }
    
    $studentId = $enrollment['student_id'];
    $subjectCode = $enrollment['subject_code'];
    $subjectName = $enrollment['subject_name'];
    
    if ($status === 'warning') {
        $title = 'Academic Warning';
        $message = "You have received an academic warning for {$subjectCode} - {$subjectName}. Please consult with your instructor.";
        $type = 'warning';
    } elseif ($status === 'dropped') {
        $title = 'Enrollment Dropped';
        $message = "You have been dropped from {$subjectCode} - {$subjectName} due to excessive absences or low grades.";
        $type = 'danger';
    } else {
        return false;
    }
    
    return createNotification($studentId, $title, $message, $type);
}

/**
 * Record attendance for an enrollment
 */
function recordAttendance(int $enrollmentId, string $status, ?string $date = null): bool {
    $pdo = getDb();
    
    $date = $date ?? date('Y-m-d');
    
    // Check if attendance already recorded for this date
    $stmt = $pdo->prepare('SELECT attendance_id FROM tbl_attendance WHERE enrollment_id = ? AND attendance_date = ?');
    $stmt->execute([$enrollmentId, $date]);
    
    if ($stmt->fetch()) {
        // Update existing record
        $update = $pdo->prepare('UPDATE tbl_attendance SET status = ? WHERE enrollment_id = ? AND attendance_date = ?');
        $result = $update->execute([$status, $enrollmentId, $date]);
    } else {
        // Insert new record
        $insert = $pdo->prepare('INSERT INTO tbl_attendance (enrollment_id, attendance_date, status) VALUES (?, ?, ?)');
        $result = $insert->execute([$enrollmentId, $date, $status]);
    }
    
    // Check and update warning/drop status after recording attendance
    if ($result) {
        $newStatus = checkWarningDropStatus($enrollmentId);
        
        // Send notification if status changed to warning or dropped
        if ($newStatus === 'warning' || $newStatus === 'dropped') {
            sendWarningDropNotification($enrollmentId, $newStatus);
        }
    }
    
    return $result;
}

/**
 * Get attendance records for an enrollment
 */
function getAttendanceRecords(int $enrollmentId, ?int $limit = null): array {
    $pdo = getDb();
    
    $sql = '
        SELECT a.*, e.student_id
        FROM tbl_attendance a
        JOIN tbl_enrollments e ON e.enrollment_id = a.enrollment_id
        WHERE a.enrollment_id = ?
        ORDER BY a.attendance_date DESC
    ';
    
    if ($limit) {
        $sql .= ' LIMIT ' . (int)$limit;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$enrollmentId]);
    return $stmt->fetchAll();
}

/**
 * Get attendance summary for a student across all enrollments
 */
function getStudentAttendanceSummary(int $studentId): array {
    $pdo = getDb();
    
    $stmt = $pdo->prepare('
        SELECT 
            SUM(CASE WHEN a.status = "absent" THEN 1 ELSE 0 END) as total_absences,
            SUM(CASE WHEN a.status = "tardy" THEN 1 ELSE 0 END) as total_tardiness,
            SUM(CASE WHEN a.status = "present" THEN 1 ELSE 0 END) as total_present,
            COUNT(*) as total_records
        FROM tbl_attendance a
        JOIN tbl_enrollments e ON e.enrollment_id = a.enrollment_id
        WHERE e.student_id = ?
    ');
    $stmt->execute([$studentId]);
    $result = $stmt->fetch();
    
    return [
        'absences' => (int)($result['total_absences'] ?? 0),
        'tardiness' => (int)($result['total_tardiness'] ?? 0),
        'present' => (int)($result['total_present'] ?? 0),
        'total' => (int)($result['total_records'] ?? 0)
    ];
}

/**
 * Get attendance by date range for a student
 */
function getStudentAttendanceByDateRange(int $studentId, string $startDate, string $endDate): array {
    $pdo = getDb();
    
    $stmt = $pdo->prepare('
        SELECT 
            a.*,
            s.subject_code,
            s.subject_name
        FROM tbl_attendance a
        JOIN tbl_enrollments e ON e.enrollment_id = a.enrollment_id
        JOIN tbl_subjects s ON s.subject_id = e.subject_id
        WHERE e.student_id = ?
        AND a.attendance_date BETWEEN ? AND ?
        ORDER BY a.attendance_date DESC
    ');
    $stmt->execute([$studentId, $startDate, $endDate]);
    return $stmt->fetchAll();
}

/**
 * Check for consecutive absences (triggers drop)
 */
function checkConsecutiveAbsences(int $enrollmentId): int {
    $pdo = getDb();
    
    $stmt = $pdo->prepare('
        SELECT attendance_date, status 
        FROM tbl_attendance 
        WHERE enrollment_id = ? 
        ORDER BY attendance_date DESC
    ');
    $stmt->execute([$enrollmentId]);
    $records = $stmt->fetchAll();
    
    $consecutiveAbsences = 0;
    foreach ($records as $record) {
        if ($record['status'] === 'absent') {
            $consecutiveAbsences++;
        } else {
            break;
        }
    }
    
    return $consecutiveAbsences;
}

/**
 * Mark multiple students as present/absent/tardy for a specific date
 */
function bulkRecordAttendance(array $enrollmentIds, string $status, ?string $date = null): int {
    $pdo = getDb();
    $date = $date ?? date('Y-m-d');
    $count = 0;
    
    foreach ($enrollmentIds as $enrollmentId) {
        if (recordAttendance((int)$enrollmentId, $status, $date)) {
            $count++;
        }
    }
    
    return $count;
}

/**
 * Get students with warning status (low grades or high absences)
 */
function getStudentsWithWarning(int $courseId = null): array {
    $pdo = getDb();
    
    $sql = '
        SELECT DISTINCT
            u.user_id,
            u.full_name,
            u.email,
            c.course_code,
            c.course_name,
            e.enrollment_status,
            e.grade,
            (
                SELECT SUM(CASE WHEN a.status = "absent" THEN 1 ELSE 0 END)
                FROM tbl_attendance a
                WHERE a.enrollment_id = e.enrollment_id
            ) as absences
        FROM tbl_enrollments e
        JOIN tbl_users u ON u.user_id = e.student_id
        JOIN tbl_course c ON c.course_id = u.course_id
        WHERE e.enrollment_status IN ("warning", "dropped")
    ';
    
    $params = [];
    if ($courseId) {
        $sql .= ' AND u.course_id = ?';
        $params[] = $courseId;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}
