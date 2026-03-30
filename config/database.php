<?php
/**
 * Database configuration and PDO connection.
 * Uses PDO for prepared statements (SQL injection prevention).
 */
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'sis_db');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

function getDb(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $opts = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $opts);
    }
    return $pdo;
}

function ensureNotificationsTable(PDO $pdo): void {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `tbl_notifications` (
              `notification_id` INT(11) NOT NULL AUTO_INCREMENT,
              `user_id` INT(11) NOT NULL,
              `title` VARCHAR(255) NOT NULL,
              `message` TEXT NOT NULL,
              `type` ENUM('info', 'warning', 'danger', 'success') NOT NULL DEFAULT 'info',
              `is_read` TINYINT(1) NOT NULL DEFAULT 0,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
              PRIMARY KEY (`notification_id`),
              KEY `user_id` (`user_id`),
              KEY `is_read` (`is_read`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    } catch (Exception $e) {
        // Ignore table exists or other minor query issues
    }
}
