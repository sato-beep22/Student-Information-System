<?php
/**
 * Upload Functions
 * Handles quiz/activity file uploads (DOCX, max 2MB)
 */

require_once dirname(dirname(__DIR__)) . '/config/database.php';
require_once __DIR__ . '/academic.php';

/**
 * Get allowed file types and max size from settings
 */
function getUploadSettings(): array {
    return [
        'max_size' => (int) getSetting('max_upload_size', 2097152), // 2MB default
        'allowed_types' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document', // .docx
            'application/msword', // .doc (legacy)
            'application/pdf', // .pdf (for flexibility)
        ],
        'allowed_extensions' => ['docx', 'doc', 'pdf'],
    ];
}

/**
 * Validate uploaded file
 */
function validateDocxFile(array $file): array {
    $settings = getUploadSettings();
    $errors = [];
    
    // Check for upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        switch ($file['error']) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $errors[] = 'File exceeds maximum size limit.';
                break;
            case UPLOAD_ERR_PARTIAL:
                $errors[] = 'File was only partially uploaded.';
                break;
            case UPLOAD_ERR_NO_FILE:
                $errors[] = 'No file was uploaded.';
                break;
            default:
                $errors[] = 'Upload error occurred.';
        }
        return ['valid' => false, 'errors' => $errors];
    }
    
    // Check file size
    if ($file['size'] > $settings['max_size']) {
        $maxMB = round($settings['max_size'] / 1048576, 2);
        $errors[] = "File exceeds maximum size of {$maxMB}MB.";
    }
    
    // Check file extension
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $settings['allowed_extensions'])) {
        $errors[] = 'Invalid file type. Only DOCX, DOC, and PDF files are allowed.';
    }
    
    // Check MIME type
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);
    
    // Validate MIME type against allowed types
    if (!in_array($mimeType, $settings['allowed_types'])) {
        $errors[] = "Invalid file MIME type: {$mimeType}. Only DOCX, DOC, and PDF files are allowed.";
    }
    
    // For DOCX, also check by looking at the file content
    if ($extension === 'docx') {
        // ZIP signature (DOCX is a ZIP file)
        $handle = fopen($file['tmp_name'], 'rb');
        $header = fread($handle, 4);
        fclose($handle);
        
        // DOCX should start with PK (ZIP signature)
        if ($header !== "\x50\x4b\x03\x04") {
            $errors[] = 'Invalid DOCX file format.';
        }
    }
    
    return [
        'valid' => empty($errors),
        'errors' => $errors,
        'mime_type' => $mimeType,
        'extension' => $extension,
    ];
}

/**
 * Handle activity file upload
 */
function handleActivityUpload(int $enrollmentId, int $studentId, array $file, ?string $description = null): ?int {
    $pdo = getDb();
    
    // Validate enrollment belongs to student
    $stmt = $pdo->prepare('SELECT enrollment_id FROM tbl_enrollments WHERE enrollment_id = ? AND student_id = ?');
    $stmt->execute([$enrollmentId, $studentId]);
    if (!$stmt->fetch()) {
        return null;
    }
    
    // Validate file
    $validation = validateDocxFile($file);
    if (!$validation['valid']) {
        $_SESSION['flash_error'] = implode(' ', $validation['errors']);
        return null;
    }
    
    // Create upload directory if it doesn't exist
    $uploadDir = dirname(__DIR__, 2) . '/uploads/activities/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    // Generate unique filename
    $extension = $validation['extension'];
    $newFilename = sprintf(
        '%d_%d_%s.%s',
        $studentId,
        $enrollmentId,
        bin2hex(random_bytes(8)),
        $extension
    );
    
    $filePath = $uploadDir . $newFilename;
    
    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
        $_SESSION['flash_error'] = 'Failed to save uploaded file.';
        return null;
    }
    
    // Save to database
    $insert = $pdo->prepare('
        INSERT INTO tbl_activity_uploads 
        (enrollment_id, student_id, file_name, file_path, file_type, file_size, description) 
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ');
    
    $insert->execute([
        $enrollmentId,
        $studentId,
        $file['name'],
        'uploads/activities/' . $newFilename,
        $file['type'],
        $file['size'],
        $description
    ]);
    
    return (int)$pdo->lastInsertId();
}

/**
 * Get activity uploads for an enrollment
 */
function getActivityUploads(int $enrollmentId): array {
    $pdo = getDb();
    
    $stmt = $pdo->prepare('
        SELECT * FROM tbl_activity_uploads 
        WHERE enrollment_id = ?
        ORDER BY uploaded_at DESC
    ');
    $stmt->execute([$enrollmentId]);
    return $stmt->fetchAll();
}

/**
 * Get activity uploads for a student
 */
function getStudentActivityUploads(int $studentId): array {
    $pdo = getDb();
    
    $stmt = $pdo->prepare('
        SELECT 
            au.*,
            s.subject_code,
            s.subject_name
        FROM tbl_activity_uploads au
        JOIN tbl_enrollments e ON e.enrollment_id = au.enrollment_id
        JOIN tbl_subjects s ON s.subject_id = e.subject_id
        WHERE au.student_id = ?
        ORDER BY au.uploaded_at DESC
    ');
    $stmt->execute([$studentId]);
    return $stmt->fetchAll();
}

/**
 * Delete an activity upload
 */
function deleteActivityUpload(int $uploadId, int $userId, string $userRole): bool {
    $pdo = getDb();
    
    // Get the upload
    $stmt = $pdo->prepare('SELECT * FROM tbl_activity_uploads WHERE upload_id = ?');
    $stmt->execute([$uploadId]);
    $upload = $stmt->fetch();
    
    if (!$upload) {
        return false;
    }
    
    // Check permission (only owner or admin can delete)
    if ($userRole !== 'admin' && $upload['student_id'] !== $userId) {
        return false;
    }
    
    // Delete file from filesystem
    $filePath = dirname(__DIR__, 2) . '/' . $upload['file_path'];
    if (file_exists($filePath)) {
        unlink($filePath);
    }
    
    // Delete from database
    $delete = $pdo->prepare('DELETE FROM tbl_activity_uploads WHERE upload_id = ?');
    return $delete->execute([$uploadId]);
}

/**
 * Get file download path
 */
function getActivityFilePath(int $uploadId): ?string {
    $pdo = getDb();
    
    $stmt = $pdo->prepare('SELECT file_path FROM tbl_activity_uploads WHERE upload_id = ?');
    $stmt->execute([$uploadId]);
    $upload = $stmt->fetch();
    
    return $upload ? $upload['file_path'] : null;
}
