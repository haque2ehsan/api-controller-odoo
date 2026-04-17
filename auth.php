<?php
session_start();

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Session security: timeout after 30 min of inactivity
$SESSION_TIMEOUT = 1800;
if (!empty($_SESSION['auth_user']) && !empty($_SESSION['last_activity'])) {
    if (time() - $_SESSION['last_activity'] > $SESSION_TIMEOUT) {
        $_SESSION = [];
        session_destroy();
        session_start();
    }
}
if (!empty($_SESSION['auth_user'])) {
    $_SESSION['last_activity'] = time();
}

function requireLogin() {
    if (empty($_SESSION['auth_user'])) {
        header('Location: login.php');
        exit;
    }
}

function isAdmin(): bool {
    return ($_SESSION['auth_user']['role'] ?? '') === 'admin';
}

function isMonitor(): bool {
    return ($_SESSION['auth_user']['role'] ?? '') === 'monitor';
}

function currentUser(): array {
    return $_SESSION['auth_user'] ?? [];
}

function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken()) . '">';
}

function verifyCsrf(): bool {
    $token = $_POST['csrf_token'] ?? '';
    if (empty($token) || empty($_SESSION['csrf_token'])) return false;
    return hash_equals($_SESSION['csrf_token'], $token);
}
