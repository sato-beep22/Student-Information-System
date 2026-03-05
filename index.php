<?php
require_once __DIR__ . '/includes/auth.php';
if (isLoggedIn()) {
    redirect(($_SESSION['role'] ?? 'student') === 'admin' ? base_url('admin/') : base_url('student/'));
}
redirect(base_url('login.php'));
