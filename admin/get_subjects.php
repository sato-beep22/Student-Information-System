<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$pdo = getDb();
$courseId = (int) ($_GET['course_id'] ?? 0);

if (!$courseId) {
    echo json_encode([]);
    exit;
}

$stmt = $pdo->prepare('SELECT subject_id, subject_code, subject_name FROM tbl_subjects WHERE course_id = ? ORDER BY subject_code');
$stmt->execute([$courseId]);
$subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode($subjects);
?>
