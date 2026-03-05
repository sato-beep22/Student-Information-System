<?php
/**
 * App config: base path, roles, and helpers.
 */
session_start();

define('BASE_PATH', dirname(__DIR__));
define('ROLE_ADMIN', 'admin');
define('ROLE_STUDENT', 'student');

/** Secret key required to create an admin via browser. Change this and keep it private. */
define('CREATE_ADMIN_KEY', 'change-me-to-a-secret-string');

function base_url(string $path = ''): string {
    $path = ltrim($path, '/');
    return '/sis_system/' . ($path ? $path : '');
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
