<?php
/**
 * Grade Request Functions
 * Handles grade review/appeal workflow
 */

require_once dirname(dirname(__DIR__)) . '/config/database.php';

/**
 * Submit a grade review request
 */
function submitGradeRequest(int $enrollmentId, int $studentId, string $reason): ?int {
    $pdo = getDb();
    
    // Verify enrollment belongs to student
    $stmt = $pdo->prepare('SELECT enrollment_id FROM tbl_enrollments WHERE enrollment_id = ? AND student_id = ?');
    $stmt->execute([$enrollmentId, $studentId]);
    if (!$stmt->fetch()) {
        return null;
    }
    
    // Check if there's already a pending request
    $stmt = $pdo->prepare('SELECT request_id FROM tbl_grade_requests WHERE enrollment_id = ? AND status = "pending"');
    $stmt->execute([$enrollmentId]);
    if ($stmt->fetch()) {
        return null; // Already has pending request
    }
    
    $insert = $pdo->prepare('INSERT INTO tbl_grade_requests (enrollment_id, student_id, reason) VALUES (?, ?, ?)');
    $insert->execute([$enrollmentId, $studentId, $reason]);
    
    return (int)$pdo->lastInsertId();
}

/**
 * Get grade requests with optional filtering
 */
function getGradeRequests(?string $status = null, ?int $studentId = null): array {
    $pdo = getDb();
    
    $sql = '
        SELECT 
            gr.*,
            s.subject_code,
            s.subject_name,
            u.full_name as student_name,
            u.email as student_email,
            c.course_code,
            c.course_name,
            e.grade,
            e.academic_status,
            admin.full_name as processed_by_name
        FROM tbl_grade_requests gr
        JOIN tbl_enrollments e ON e.enrollment_id = gr.enrollment_id
        JOIN tbl_subjects s ON s.subject_id = e.subject_id
        JOIN tbl_users u ON u.user_id = gr.student_id
        JOIN tbl_course c ON c.course_id = s.course_id
        LEFT JOIN tbl_users admin ON admin.user_id = gr.processed_by
        WHERE 1=1
    ';
    
    $params = [];
    
    if ($status) {
        $sql .= ' AND gr.status = ?';
        $params[] = $status;
    }
    
    if ($studentId) {
        $sql .= ' AND gr.student_id = ?';
        $params[] = $studentId;
    }
    
    $sql .= ' ORDER BY gr.created_at DESC';
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Get a single grade request by ID
 */
function getGradeRequest(int $requestId): ?array {
    $pdo = getDb();
    
    $stmt = $pdo->prepare('
        SELECT 
            gr.*,
            s.subject_code,
            s.subject_name,
            s.units,
            u.full_name as student_name,
            u.email as student_email,
            c.course_code,
            c.course_name,
            e.grade as current_grade,
            e.academic_status,
            admin.full_name as processed_by_name
        FROM tbl_grade_requests gr
        JOIN tbl_enrollments e ON e.enrollment_id = gr.enrollment_id
        JOIN tbl_subjects s ON s.subject_id = e.subject_id
        JOIN tbl_users u ON u.user_id = gr.student_id
        JOIN tbl_course c ON c.course_id = s.course_id
        LEFT JOIN tbl_users admin ON admin.user_id = gr.processed_by
        WHERE gr.request_id = ?
    ');
    $stmt->execute([$requestId]);
    return $stmt->fetch() ?: null;
}

/**
 * Process a grade request (approve/reject)
 */
function processGradeRequest(int $requestId, int $adminId, string $status, ?string $notes = null): bool {
    $pdo = getDb();
    
    // Verify request exists and is pending
    $stmt = $pdo->prepare('SELECT status FROM tbl_grade_requests WHERE request_id = ?');
    $stmt->execute([$requestId]);
    $request = $stmt->fetch();
    
    if (!$request || $request['status'] !== 'pending') {
        return false;
    }
    
    if (!in_array($status, ['approved', 'rejected'])) {
        return false;
    }
    
    $update = $pdo->prepare('
        UPDATE tbl_grade_requests 
        SET status = ?, admin_notes = ?, processed_by = ?, processed_at = NOW() 
        WHERE request_id = ?
    ');
    $result = $update->execute([$status, $notes, $adminId, $requestId]);
    
    // If approved, you might want to notify the student or trigger a grade update
    // This can be extended based on requirements
    
    return $result;
}

/**
 * Get pending grade request count
 */
function getPendingGradeRequestCount(): int {
    $pdo = getDb();
    
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM tbl_grade_requests WHERE status = "pending"');
    $stmt->execute();
    return (int)$stmt->fetchColumn();
}

/**
 * Check if student has pending grade request for an enrollment
 */
function hasPendingGradeRequest(int $enrollmentId, int $studentId): bool {
    $pdo = getDb();
    
    $stmt = $pdo->prepare('
        SELECT 1 FROM tbl_grade_requests 
        WHERE enrollment_id = ? AND student_id = ? AND status = "pending"
    ');
    $stmt->execute([$enrollmentId, $studentId]);
    return (bool)$stmt->fetch();
}
