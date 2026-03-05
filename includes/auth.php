<?php
/**
 * Authentication and RBAC.
 * Requires config/app.php (session) and config/database.php.
 */
require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/config/database.php';

function isLoggedIn(): bool {
    return !empty($_SESSION['user_id']);
}

function currentUser(): ?array {
    if (!isLoggedIn()) return null;
    $id = (int) $_SESSION['user_id'];
    $pdo = getDb();
    $stmt = $pdo->prepare('SELECT u.user_id, u.full_name, u.email, u.role, u.created_at, u.course_id, u.section_id, s.section_name FROM tbl_users u LEFT JOIN tbl_sections s ON u.section_id = s.section_id WHERE u.user_id = ?');
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    if (!$user) return null;
    $user['full_name'] = $user['full_name'] ?? '';
    try {
        $p2 = $pdo->prepare('SELECT first_name, last_name, email, phone, address, gender FROM tbl_profiles WHERE user_id = ?');
        $p2->execute([$id]);
        $profile = $p2->fetch();
        if ($profile) {
            $user = array_merge($user, $profile);
            $user['full_name'] = trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? ''));
        }
    } catch (Throwable $e) { /* tbl_profiles may not exist */ }
    return $user;
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        redirect(base_url('login.php'));
    }
}

function requireAdmin(): void {
    requireLogin();
    $user = currentUser();
    if (!$user || $user['role'] !== ROLE_ADMIN) {
        header('HTTP/1.1 403 Forbidden');
        echo 'Access denied. Admin only.';
        exit;
    }
}

function requireStudent(): void {
    requireLogin();
    $user = currentUser();
    if (!$user || $user['role'] !== ROLE_STUDENT) {
        header('HTTP/1.1 403 Forbidden');
        echo 'Access denied. Student only.';
        exit;
    }
}

function login(string $username, string $password): bool {
    $pdo = getDb();
    try {
        $stmt = $pdo->prepare('SELECT user_id, password, role FROM tbl_users WHERE username = ? OR email = ?');
        $stmt->execute([$username, $username]);
    } catch (Throwable $e) {
        $stmt = $pdo->prepare('SELECT user_id, password, role FROM tbl_users WHERE email = ?');
        $stmt->execute([$username]);
    }
    $row = $stmt->fetch();
    if (!$row || !password_verify($password, $row['password'])) {
        return false;
    }
    $_SESSION['user_id'] = $row['user_id'];
    $_SESSION['role'] = $row['role'];
    return true;
}

function logout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
