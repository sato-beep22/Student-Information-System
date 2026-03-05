<?php
require_once __DIR__ . '/includes/auth.php';
requireStudent();

$user = currentUser();
$pdo = getDb();

// Get upload_id from URL
$uploadId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$uploadId) {
    header('HTTP/1.1 400 Bad Request');
    die('Invalid request.');
}

// Verify the student has access to this activity (enrolled in the subject and activity is posted by admin)
// Admin activities have enrollment_id = NULL, and subject_id is embedded in file_name as [subjectId]
$stmt = $pdo->prepare('
    SELECT au.file_path, au.file_name, au.file_size, s.subject_id
    FROM tbl_activity_uploads au
    JOIN tbl_subjects s ON s.subject_id = (
        CAST(SUBSTRING_INDEX(SUBSTRING(au.file_name, 2), \']\', 1) AS UNSIGNED)
    )
    JOIN tbl_users u ON u.user_id = au.student_id AND u.role = "admin"
    JOIN tbl_enrollments e ON e.subject_id = s.subject_id 
    WHERE au.upload_id = ? 
    AND e.student_id = ? 
    AND e.status = "enrolled"
    AND au.enrollment_id IS NULL
    LIMIT 1
');
$stmt->execute([$uploadId, $user['user_id']]);
$activity = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$activity) {
    header('HTTP/1.1 403 Forbidden');
    die('You do not have access to this file.');
}

$filePath = __DIR__ . '/' . $activity['file_path'];

if (!file_exists($filePath)) {
    header('HTTP/1.1 404 Not Found');
    die('File not found.');
}

// Determine MIME type based on file extension
$fileExtension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
$mimeType = 'application/octet-stream'; // default

if ($fileExtension === 'docx') {
    $mimeType = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
} elseif ($fileExtension === 'doc') {
    $mimeType = 'application/msword';
} elseif ($fileExtension === 'pdf') {
    $mimeType = 'application/pdf';
}

// Get the actual file extension from the stored file path
$fileExtension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

// Create proper filename with extension
$baseName = basename($activity['file_name']);
// Remove any existing extension to avoid .docx.docx
$nameWithoutExt = preg_replace('/\.(docx|doc|pdf)$/i', '', $baseName);
$downloadFilename = $nameWithoutExt . '.' . $fileExtension;

// Serve the file
header('Content-Type: ' . $mimeType);
header('Content-Disposition: attachment; filename="' . $downloadFilename . '"');
header('Content-Length: ' . $activity['file_size']);
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

readfile($filePath);
exit;
?>
