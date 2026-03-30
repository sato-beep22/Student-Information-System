<?php
/**
 * Academic Functions
 * Handles grade calculations, academic status, GPA, rankings, and enrollment capacity
 */

require_once dirname(dirname(__DIR__)) . '/config/database.php';

/**
 * Get a setting value from tbl_settings
 */
function getSetting(string $key, $default = null) {
    $pdo = getDb();
    $stmt = $pdo->prepare('SELECT setting_value FROM tbl_settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? $row['setting_value'] : $default;
}

/**
 * Calculate academic status based on grade
 * Grade >= 75 is Passed, Grade <= 74 is Failed
 */
function calculateAcademicStatus($grade): string {
    $threshold = (float) getSetting('grade_pass_threshold', 75);
    
    if ($grade === null || $grade === '') {
        return 'pending';
    }
    
    return (float)$grade >= $threshold ? 'passed' : 'failed';
}

/**
 * Update academic status for an enrollment based on grade
 */
function updateAcademicStatus(int $enrollmentId): bool {
    $pdo = getDb();
    
    // Get current grade
    $stmt = $pdo->prepare('SELECT grade FROM tbl_enrollments WHERE enrollment_id = ?');
    $stmt->execute([$enrollmentId]);
    $enrollment = $stmt->fetch();
    
    if (!$enrollment) {
        return false;
    }
    
    $status = calculateAcademicStatus($enrollment['grade']);
    
    $update = $pdo->prepare('UPDATE tbl_enrollments SET academic_status = ? WHERE enrollment_id = ?');
    return $update->execute([$status, $enrollmentId]);
}

/**
 * Get attendance summary for an enrollment
 */
function getAttendanceSummary(int $enrollmentId): array {
    $pdo = getDb();
    
    $stmt = $pdo->prepare('
        SELECT 
            SUM(CASE WHEN status = "absent" THEN 1 ELSE 0 END) as absences,
            SUM(CASE WHEN status = "tardy" THEN 1 ELSE 0 END) as tardiness,
            SUM(CASE WHEN status = "present" THEN 1 ELSE 0 END) as present,
            COUNT(*) as total
        FROM tbl_attendance 
        WHERE enrollment_id = ?
    ');
    $stmt->execute([$enrollmentId]);
    $result = $stmt->fetch();
    
    return [
        'absences' => (int)($result['absences'] ?? 0),
        'tardiness' => (int)($result['tardiness'] ?? 0),
        'present' => (int)($result['present'] ?? 0),
        'total' => (int)($result['total'] ?? 0)
    ];
}

/**
 * Check consecutive absences and update enrollment status
 * If 3+ consecutive absences, mark as dropped
 */
function checkWarningDropStatus(int $enrollmentId): string {
    $pdo = getDb();
    
    $maxAbsences = (int) getSetting('max_consecutive_absences', 3);
    
    // Get all attendance records ordered by date
    $stmt = $pdo->prepare('
        SELECT attendance_id, attendance_date, status 
        FROM tbl_attendance 
        WHERE enrollment_id = ? 
        ORDER BY attendance_date DESC
    ');
    $stmt->execute([$enrollmentId]);
    $records = $stmt->fetchAll();
    
    // Count consecutive absences from most recent
    $consecutiveAbsences = 0;
    foreach ($records as $record) {
        if ($record['status'] === 'absent') {
            $consecutiveAbsences++;
        } else {
            break; // Stop counting when we hit a non-absence
        }
    }
    
    // Also check total absences
    $summary = getAttendanceSummary($enrollmentId);
    $totalAbsences = $summary['absences'];
    
    // Get current enrollment
    $stmt = $pdo->prepare('SELECT enrollment_status, grade FROM tbl_enrollments WHERE enrollment_id = ?');
    $stmt->execute([$enrollmentId]);
    $enrollment = $stmt->fetch();
    
    if (!$enrollment) {
        return 'unknown';
    }
    
    $currentStatus = $enrollment['enrollment_status'];
    $grade = $enrollment['grade'];
    
    // Determine new status
    $newStatus = 'active';
    
    // Check for drop (3 consecutive absences)
    if ($consecutiveAbsences >= $maxAbsences) {
        $newStatus = 'dropped';
    }
    // Check for warning (based on low grade or other criteria)
    elseif ($grade !== null && (float)$grade < 75) {
        $newStatus = 'warning';
    }
    
    // Update if status changed
    if ($newStatus !== $currentStatus) {
        $update = $pdo->prepare('UPDATE tbl_enrollments SET enrollment_status = ? WHERE enrollment_id = ?');
        $update->execute([$newStatus, $enrollmentId]);
    }
    
    return $newStatus;
}

/**
 * Calculate GPA/Weighted Average for a student
 */
function calculateGPA(int $studentId): ?float {
    $pdo = getDb();
    
    $stmt = $pdo->prepare('
        SELECT e.grade, s.units
        FROM tbl_enrollments e
        JOIN tbl_subjects s ON s.subject_id = e.subject_id
        WHERE e.student_id = ? 
        AND e.grade IS NOT NULL
        AND e.academic_status != "pending"
    ');
    $stmt->execute([$studentId]);
    $grades = $stmt->fetchAll();
    
    if (empty($grades)) {
        return null;
    }
    
    $totalPoints = 0;
    $totalUnits = 0;
    
    foreach ($grades as $g) {
        $grade = (float)$g['grade'];
        $units = (int)$g['units'];
        $totalPoints += $grade * $units;
        $totalUnits += $units;
    }
    
    return $totalUnits > 0 ? round($totalPoints / $totalUnits, 2) : null;
}

/**
 * Get student's rank in a course
 */
function getStudentRank(int $studentId, int $courseId): ?int {
    $pdo = getDb();
    
    // Get all students in the course with their GPAs
    $stmt = $pdo->prepare('
        SELECT 
            u.user_id,
            SUM(CASE WHEN e.grade IS NOT NULL THEN e.grade * s.units ELSE 0 END) / 
            NULLIF(SUM(CASE WHEN e.grade IS NOT NULL THEN s.units ELSE 0 END), 0) as gpa
        FROM tbl_users u
        JOIN tbl_course c ON c.course_id = u.course_id
        LEFT JOIN tbl_enrollments e ON e.student_id = u.user_id
        LEFT JOIN tbl_subjects s ON s.subject_id = e.subject_id
        WHERE u.role = "student" 
        AND u.course_id = ?
        AND e.grade IS NOT NULL
        GROUP BY u.user_id
        ORDER BY gpa DESC
    ');
    $stmt->execute([$courseId]);
    $students = $stmt->fetchAll();
    
    $rank = 1;
    foreach ($students as $s) {
        if ((int)$s['user_id'] === $studentId) {
            return $rank;
        }
        $rank++;
    }
    
    return null;
}

/**
 * Get class rankings for a course
 */
function getClassRankings(int $courseId, int $limit = 10): array {
    $pdo = getDb();
    
    $stmt = $pdo->prepare('
        SELECT 
            u.user_id,
            u.full_name,
            c.course_code,
            SUM(CASE WHEN e.grade IS NOT NULL THEN e.grade * s.units ELSE 0 END) / 
            NULLIF(SUM(CASE WHEN e.grade IS NOT NULL THEN s.units ELSE 0 END), 0) as gpa
        FROM tbl_users u
        JOIN tbl_course c ON c.course_id = u.course_id
        LEFT JOIN tbl_enrollments e ON e.student_id = u.user_id
        LEFT JOIN tbl_subjects s ON s.subject_id = e.subject_id
        WHERE u.role = "student" 
        AND u.course_id = ?
        GROUP BY u.user_id, u.full_name, c.course_code
        HAVING gpa IS NOT NULL
        ORDER BY gpa DESC
        LIMIT ?
    ');
    $stmt->execute([$courseId, $limit]);
    return $stmt->fetchAll();
}

/**
 * Check if a section has capacity for new enrollment
 */
function canEnroll(int $subjectId): bool {
    $pdo = getDb();
    
    // Get the student's section and its capacity
    $stmt = $pdo->prepare('
        SELECT sec.capacity 
        FROM tbl_subjects sub
        JOIN tbl_users u ON u.course_id = sub.course_id AND u.role = "student"
        LEFT JOIN tbl_sections sec ON sec.section_id = u.section_id AND sec.course_id = sub.course_id
        WHERE sub.subject_id = ?
        LIMIT 1
    ');
    $stmt->execute([$subjectId]);
    $result = $stmt->fetch();
    
    // Fallback: get any section capacity for this subject's course
    if (!$result || !$result['capacity']) {
        $stmt2 = $pdo->prepare('
            SELECT sec.capacity 
            FROM tbl_subjects sub
            JOIN tbl_sections sec ON sec.course_id = sub.course_id
            WHERE sub.subject_id = ?
            LIMIT 1
        ');
        $stmt2->execute([$subjectId]);
        $result = $stmt2->fetch();
    }
    
    $maxCapacity = $result && $result['capacity'] ? (int)$result['capacity'] : 30;
    
    // Get current enrollment count
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM tbl_enrollments WHERE subject_id = ? AND IFNULL(enrollment_status, "active") != "dropped"');
    $stmt->execute([$subjectId]);
    $currentCount = (int)$stmt->fetchColumn();
    
    return $currentCount < $maxCapacity;
}

/**
 * Get enrollment count for a subject
 */
function getEnrollmentCount(int $subjectId): int {
    $pdo = getDb();
    
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM tbl_enrollments WHERE subject_id = ? AND enrollment_status != "dropped"');
    $stmt->execute([$subjectId]);
    return (int)$stmt->fetchColumn();
}

/**
 * Get enrollment with all details
 */
function getEnrollmentDetails(int $enrollmentId): ?array {
    $pdo = getDb();
    
    $stmt = $pdo->prepare('
        SELECT 
            e.*,
            s.subject_code,
            s.subject_name,
            s.units,
            c.course_code,
            c.course_name,
            u.full_name as student_name,
            COALESCE(sec.capacity, 30) as max_capacity
        FROM tbl_enrollments e
        JOIN tbl_subjects s ON s.subject_id = e.subject_id
        JOIN tbl_course c ON c.course_id = s.course_id
        JOIN tbl_users u ON u.user_id = e.student_id
        LEFT JOIN tbl_sections sec ON sec.course_id = c.course_id
        WHERE e.enrollment_id = ?
    ');
    $stmt->execute([$enrollmentId]);
    return $stmt->fetch() ?: null;
}

/**
 * Check if a section has available capacity
 * @param int $sectionId The section ID
 * @param int $excludeStudentId Optional student ID to exclude from count
 * @return array ['is_full' => boolean, 'capacity' => int, 'current_count' => int]
 */
function getSectionCapacityInfo(int $sectionId, int $excludeStudentId = 0): array {
    $pdo = getDb();
    
    $query = '
        SELECT s.capacity, COUNT(u.user_id) as student_count
        FROM tbl_sections s
        LEFT JOIN tbl_users u ON u.section_id = s.section_id AND u.role = "student"';
        
    $params = [];
    if ($excludeStudentId > 0) {
        $query .= ' AND u.user_id != ?';
        $params[] = $excludeStudentId;
    }
    
    $query .= ' WHERE s.section_id = ? GROUP BY s.section_id';
    $params[] = $sectionId;

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $section = $stmt->fetch();

    $capacity = (int)($section['capacity'] ?? 0);
    $currentCount = (int)($section['student_count'] ?? 0);
    
    return [
        'is_full' => $section && $currentCount >= $capacity,
        'capacity' => $capacity,
        'current_count' => $currentCount
    ];
}

/**
 * Enroll a student in a subject securely (handles try-catch for status column)
 */
function enrollStudentInSubject(int $studentId, int $subjectId, string $status = 'enrolled'): bool {
    $pdo = getDb();
    
    $exists = $pdo->prepare('SELECT 1 FROM tbl_enrollments WHERE student_id = ? AND subject_id = ?');
    $exists->execute([$studentId, $subjectId]);
    if ($exists->fetch()) {
        return false;
    }
    
    try {
        $ins = $pdo->prepare('INSERT INTO tbl_enrollments (student_id, subject_id, status) VALUES (?, ?, ?)');
        $ins->execute([$studentId, $subjectId, $status]);
        return true;
    } catch (Throwable $e) {
        // Fallback for databases without status column
        $ins = $pdo->prepare('INSERT INTO tbl_enrollments (student_id, subject_id) VALUES (?, ?)');
        $ins->execute([$studentId, $subjectId]);
        return true;
    }
}

/**
 * Update an enrollment status and automatically send a notification to the student
 */
function updateEnrollmentWithNotification(int $enrollmentId, int $studentId, string $status, string $notifTitle, string $notifMessage, string $notifType = 'info'): bool {
    $pdo = getDb();
    
    // Update the enrollment status, and if it's dropped, also sync the academic status
    if ($status === 'dropped') {
        $update = $pdo->prepare('UPDATE tbl_enrollments SET enrollment_status = ?, academic_status = ? WHERE enrollment_id = ? AND student_id = ?');
        $success = $update->execute([$status, 'dropped', $enrollmentId, $studentId]);
    } else {
        $update = $pdo->prepare('UPDATE tbl_enrollments SET enrollment_status = ? WHERE enrollment_id = ? AND student_id = ?');
        $success = $update->execute([$status, $enrollmentId, $studentId]);
    }
    
    if ($success) {
        // Prepare notification creation
        ensureNotificationsTable($pdo);
        try {
            $notifStmt = $pdo->prepare('
                INSERT INTO tbl_notifications (user_id, title, message, type, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ');
            $notifStmt->execute([$studentId, $notifTitle, $notifMessage, $notifType]);
        } catch (Exception $e) {
            error_log("Notification error: " . $e->getMessage());
        }
    }
    
    return $success;
}

/**
 * Update grade for an enrollment
 */
function updateGrade(int $enrollmentId, float $grade): bool {
    $pdo = getDb();
    
    $status = calculateAcademicStatus($grade);
    
    $stmt = $pdo->prepare('UPDATE tbl_enrollments SET grade = ?, academic_status = ? WHERE enrollment_id = ?');
    $result = $stmt->execute([$grade, $status, $enrollmentId]);
    
    // Check warning/drop status after grade update
    if ($result) {
        checkWarningDropStatus($enrollmentId);
    }
    
    return $result;
}
