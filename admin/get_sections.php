<?php
require_once __DIR__ . '/../includes/auth.php';

$pdo = getDb();
$courseId = (int) ($_GET['course_id'] ?? 0);

if (!$courseId) {
    echo json_encode([]);
    exit;
}

$stmt = $pdo->prepare('SELECT section_id, section_name, capacity FROM tbl_sections WHERE course_id = ? ORDER BY section_name');
$stmt->execute([$courseId]);
$sections = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode($sections);
?>
