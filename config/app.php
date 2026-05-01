<?php
/**
 * App config: base path, roles, and helpers.
 */
// Enable error reporting to find issues on InfinityFree (Disable for production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('BASE_PATH', dirname(__DIR__));
define('ROLE_ADMIN', 'admin');
define('ROLE_STUDENT', 'student');

/** 
 * Automatically detect the project root URL accurately.
 * Works from any directory (root, /admin, /student) by stripping 
 * the relative filesystem path from the current URL.
 */
function base_url(string $path = ''): string {
    static $appRoot = null;
    
    if ($appRoot === null) {
        $scriptName = $_SERVER['SCRIPT_NAME']; // e.g. /sis_system/admin/index.php
        $scriptPath = realpath($_SERVER['SCRIPT_FILENAME']); // e.g. C:\xampp\htdocs\sis_system\admin\index.php
        $basePath = realpath(BASE_PATH); // e.g. C:\xampp\htdocs\sis_system
        
        // Find the relative portion (e.g. \admin\index.php)
        $relative = str_replace($basePath, '', $scriptPath);
        $relative = str_replace('\\', '/', $relative); // Normalize to URL slashes
        
        // Strip the relative portion from the URL to get the root
        $detectedRoot = str_replace($relative, '', $scriptName);
        $appRoot = rtrim($detectedRoot, '/') . '/';
    }
    
    $path = ltrim($path, '/');
    return $appRoot . $path;
}

function asset_url(string $path): string {
    return base_url('assets/' . ltrim($path, '/'));
}

function redirect(string $url, int $code = 302): void {
    header('Location: ' . $url, true, $code);
    exit;
}

function csrf_field(): string {
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return '<input type="hidden" name="_csrf" value="' . htmlspecialchars($_SESSION['_csrf']) . '">';
}

function csrf_verify(): bool {
    $token = $_POST['_csrf'] ?? '';
    return !empty($_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], $token);
}

/**
 * Escape for HTML (XSS prevention).
 */
function e(?string $s): string {
    return $s === null ? '' : htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
