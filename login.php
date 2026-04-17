<?php
session_start();
require_once 'config.php';

$error = '';

// Already logged in
if (!empty($_SESSION['auth_user'])) {
    header('Location: index.php');
    exit;
}

// --- Security Config ---
$MAX_ATTEMPTS_IP    = 20;  // max failed attempts per IP in window
$MAX_ATTEMPTS_USER  = 5;   // max failed attempts per username in window
$LOCKOUT_WINDOW     = 900; // 15 minutes
$COOLDOWN_SECONDS   = 2;   // delay after failed login

// Get client IP
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

// --- CSRF Token ---
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// --- Cleanup old attempts (older than lockout window) ---
$pdo->prepare("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL ? SECOND)")
    ->execute([$LOCKOUT_WINDOW]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $error = 'Invalid request. Please try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === '' || $password === '') {
            $error = 'Please enter both username and password.';
        } else {
            // Check IP rate limit
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL ? SECOND)");
            $stmt->execute([$clientIp, $LOCKOUT_WINDOW]);
            $ipAttempts = (int)$stmt->fetchColumn();

            // Check per-user rate limit
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE username = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL ? SECOND)");
            $stmt->execute([$username, $LOCKOUT_WINDOW]);
            $userAttempts = (int)$stmt->fetchColumn();

            if ($ipAttempts >= $MAX_ATTEMPTS_IP) {
                $remaining = ceil($LOCKOUT_WINDOW / 60);
                $error = "Too many login attempts from this IP. Try again in $remaining minutes.";
            } elseif ($userAttempts >= $MAX_ATTEMPTS_USER) {
                $remaining = ceil($LOCKOUT_WINDOW / 60);
                $error = "This account is temporarily locked. Try again in $remaining minutes.";
            } else {
                $stmt = $pdo->prepare("SELECT * FROM authuser WHERE username = ? AND active = 1");
                $stmt->execute([$username]);
                $user = $stmt->fetch();

                if ($user && password_verify($password, $user['password'])) {
                    // Clear failed attempts for this user+IP on success
                    $pdo->prepare("DELETE FROM login_attempts WHERE ip_address = ? AND username = ?")
                        ->execute([$clientIp, $username]);

                    // Regenerate session ID to prevent fixation
                    session_regenerate_id(true);

                    $_SESSION['auth_user'] = [
                        'id' => $user['id'],
                        'username' => $user['username'],
                        'full_name' => $user['full_name'],
                        'role' => $user['role'],
                    ];
                    // Regenerate CSRF token
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                    header('Location: index.php');
                    exit;
                } else {
                    // Record failed attempt
                    $pdo->prepare("INSERT INTO login_attempts (ip_address, username) VALUES (?, ?)")
                        ->execute([$clientIp, $username]);

                    // Constant-time delay to slow brute force
                    sleep($COOLDOWN_SECONDS);

                    // Generic message — don't reveal if user exists
                    $error = 'Invalid username or password.';
                }
            }
        }
    }
    // Rotate CSRF token after every POST
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - API Controller</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background: #714B67; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .login-card { width: 100%; max-width: 420px; }
        .btn-odoo { background: #714B67; border-color: #714B67; color: #fff; }
        .btn-odoo:hover { background: #5a3c53; border-color: #5a3c53; color: #fff; }
    </style>
</head>
<body>
<div class="login-card">
    <div class="card shadow">
        <div class="card-body p-5">
            <div class="text-center mb-4">
                <i class="bi bi-people-fill" style="font-size: 3rem; color: #714B67;"></i>
                <h3 class="mt-2" style="color: #714B67;">API Controller</h3>
                <p class="text-muted">Sign in to your account</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger py-2"><i class="bi bi-exclamation-circle me-1"></i><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <div class="mb-3">
                    <label class="form-label">Username</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                        <input type="text" name="username" class="form-control" placeholder="email@uttara.ac.bd" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required autofocus>
                    </div>
                </div>
                <div class="mb-4">
                    <label class="form-label">Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" name="password" class="form-control" placeholder="Enter password" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-odoo w-100 py-2"><i class="bi bi-box-arrow-in-right me-2"></i>Sign In</button>
            </form>
        </div>
    </div>
    <p class="text-center text-white-50 mt-3 small">&copy; <?= date('Y') ?> Uttara University</p>
</div>
</body>
</html>
