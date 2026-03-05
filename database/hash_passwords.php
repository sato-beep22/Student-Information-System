<?php
/**
 * One-time script: hash plain-text passwords in tbl_users.
 * Run from CLI: php database/hash_passwords.php
 * Or import sis_db.sql then run this once so login works with password_hash.
 */ 
require_once dirname(__DIR__) . '/config/database.php';
$pdo = getDb();
$stmt = $pdo->query('SELECT user_id, password FROM tbl_users');
$updated = 0;
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $hash = $row['password'];
    if (strlen($hash) < 60 || strpos($hash, '$2y$') !== 0) {
        $newHash = password_hash($hash === '' ? 'password123' : $hash, PASSWORD_DEFAULT);
        $pdo->prepare('UPDATE tbl_users SET password = ? WHERE user_id = ?')->execute([$newHash, $row['user_id']]);
        $updated++;
    }
}
echo "Updated $updated password(s).\n";
