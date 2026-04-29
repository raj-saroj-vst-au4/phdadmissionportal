<?php
require_once __DIR__ . '/db.php';

function current_user() {
    if (!isset($_SESSION['user_id'])) return null;
    $u = one('SELECT * FROM users WHERE id = ? AND active = 1', [$_SESSION['user_id']]);
    return $u ?: null;
}

function require_login(): array {
    $u = current_user();
    if (!$u) {
        header('Location: /phdportal/login.php');
        exit;
    }
    return $u;
}

function require_admin(): array {
    $u = require_login();
    if ($u['role'] !== 'admin') {
        http_response_code(403);
        echo '<p style="padding:2rem;font-family:sans-serif">403 Forbidden - admin access required. <a href="/phdportal/dashboard.php">Back</a></p>';
        exit;
    }
    return $u;
}

function require_panel(): array {
    $u = require_login();
    if ($u['role'] !== 'panel') {
        http_response_code(403);
        echo '<p style="padding:2rem;font-family:sans-serif">403 Forbidden - panel access required. <a href="/phdportal/dashboard.php">Back</a></p>';
        exit;
    }
    return $u;
}

function login(string $username, string $password): bool {
    $u = one('SELECT * FROM users WHERE username = ? AND active = 1', [$username]);
    if (!$u) return false;
    if (!password_verify($password, $u['password_hash'])) return false;
    $_SESSION['user_id'] = (int)$u['id'];
    return true;
}

function logout(): void {
    $_SESSION = [];
    session_destroy();
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function check_csrf(): void {
    $t = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!$t || !hash_equals($_SESSION['csrf'] ?? '', $t)) {
        http_response_code(400);
        die('Invalid CSRF token');
    }
}
