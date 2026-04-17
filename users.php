<?php
require_once 'auth.php';
requireLogin();
require_once 'config.php';

$me = currentUser();
$error = '';
$success = '';

// Only admin can manage other users
$canManage = isAdmin();

// Handle delete user (admin only)
if ($canManage && isset($_GET['delete'])) {
    $delId = (int)$_GET['delete'];
    if ($delId === $me['id']) {
        $error = 'You cannot delete your own account.';
    } else {
        $pdo->prepare("DELETE FROM authuser WHERE id = ?")->execute([$delId]);
        header("Location: users.php?msg=deleted");
        exit;
    }
}

// Handle toggle active (admin only)
if ($canManage && isset($_GET['toggle'])) {
    $togId = (int)$_GET['toggle'];
    if ($togId === $me['id']) {
        $error = 'You cannot deactivate your own account.';
    } else {
        $pdo->query("UPDATE authuser SET active = NOT active WHERE id = $togId");
        header("Location: users.php?msg=toggled");
        exit;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) { header('Location: users.php'); exit; }
    $action = $_POST['action'] ?? '';

    if ($action === 'profile') {
        // Update own profile
        $fullName = trim($_POST['full_name'] ?? '');
        $newPass = $_POST['new_password'] ?? '';
        $confirmPass = $_POST['confirm_password'] ?? '';

        if ($fullName === '') {
            $error = 'Full name is required.';
        } elseif ($newPass !== '' && strlen($newPass) < 6) {
            $error = 'Password must be at least 6 characters.';
        } elseif ($newPass !== $confirmPass) {
            $error = 'Passwords do not match.';
        } else {
            if ($newPass !== '') {
                $hash = password_hash($newPass, PASSWORD_BCRYPT);
                $pdo->prepare("UPDATE authuser SET full_name = ?, password = ? WHERE id = ?")->execute([$fullName, $hash, $me['id']]);
            } else {
                $pdo->prepare("UPDATE authuser SET full_name = ? WHERE id = ?")->execute([$fullName, $me['id']]);
            }
            $_SESSION['auth_user']['full_name'] = $fullName;
            $success = 'Profile updated successfully.';
        }
    } elseif ($action === 'create_user' && $canManage) {
        $username = trim($_POST['username'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'monitor';

        if ($username === '' || $fullName === '' || $password === '') {
            $error = 'All fields are required.';
        } elseif (strlen($password) < 6) {
            $error = 'Password must be at least 6 characters.';
        } elseif (!in_array($role, ['admin', 'monitor'])) {
            $error = 'Invalid role.';
        } else {
            // Check duplicate
            $stmt = $pdo->prepare("SELECT id FROM authuser WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                $error = 'Username already exists.';
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $pdo->prepare("INSERT INTO authuser (username, password, full_name, role) VALUES (?, ?, ?, ?)")
                    ->execute([$username, $hash, $fullName, $role]);
                $success = "User '$username' created successfully.";
            }
        }
    } elseif ($action === 'edit_user' && $canManage) {
        $editId = (int)($_POST['edit_id'] ?? 0);
        $fullName = trim($_POST['full_name'] ?? '');
        $role = $_POST['role'] ?? 'monitor';
        $newPass = $_POST['password'] ?? '';

        if ($fullName === '') {
            $error = 'Full name is required.';
        } elseif (!in_array($role, ['admin', 'monitor'])) {
            $error = 'Invalid role.';
        } else {
            if ($newPass !== '' && strlen($newPass) < 6) {
                $error = 'Password must be at least 6 characters.';
            } else {
                if ($newPass !== '') {
                    $hash = password_hash($newPass, PASSWORD_BCRYPT);
                    $pdo->prepare("UPDATE authuser SET full_name = ?, role = ?, password = ? WHERE id = ?")
                        ->execute([$fullName, $role, $hash, $editId]);
                } else {
                    $pdo->prepare("UPDATE authuser SET full_name = ?, role = ? WHERE id = ?")
                        ->execute([$fullName, $role, $editId]);
                }
                $success = 'User updated successfully.';
            }
        }
    }
}

// Fetch current profile data
$stmt = $pdo->prepare("SELECT * FROM authuser WHERE id = ?");
$stmt->execute([$me['id']]);
$profile = $stmt->fetch();

// Fetch all users (admin only)
$allUsers = [];
if ($canManage) {
    $allUsers = $pdo->query("SELECT * FROM authuser ORDER BY id")->fetchAll();
}

// Fetch user for editing
$editUser = null;
if ($canManage && isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM authuser WHERE id = ?");
    $stmt->execute([(int)$_GET['edit']]);
    $editUser = $stmt->fetch();
}

$msg = $_GET['msg'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users - API Controller</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background: #f4f6f9; }
        .sidebar { background: #714B67; min-height: 100vh; color: #fff; }
        .sidebar .nav-link { color: rgba(255,255,255,.8); }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: #fff; background: rgba(255,255,255,.1); }
        .card { border: none; box-shadow: 0 1px 3px rgba(0,0,0,.1); }
        .form-section { background: #fff; border-radius: 8px; padding: 20px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        .form-section h5 { color: #714B67; border-bottom: 2px solid #714B67; padding-bottom: 8px; margin-bottom: 16px; }
        .btn-odoo { background: #714B67; border-color: #714B67; color: #fff; }
        .btn-odoo:hover { background: #5a3c53; border-color: #5a3c53; color: #fff; }
        .table th { background: #714B67; color: #fff; font-weight: 500; }
    </style>
</head>
<body>
<div class="d-flex">
    <!-- Sidebar -->
    <div class="sidebar d-flex flex-column p-3" style="width: 250px;">
        <h4 class="mb-4"><i class="bi bi-people-fill me-2"></i>API Controller</h4>
        <nav class="nav flex-column">
            <a class="nav-link" href="index.php"><i class="bi bi-person-lines-fill me-2"></i>Employees</a>
            <a class="nav-link active" href="users.php"><i class="bi bi-shield-lock me-2"></i>Users & Profile</a>
        </nav>
        <div class="mt-auto">
            <div class="small opacity-75 mb-2"><i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($me['full_name']) ?> <span class="badge bg-light text-dark"><?= $me['role'] ?></span></div>
            <a href="logout.php" class="btn btn-sm btn-outline-light w-100"><i class="bi bi-box-arrow-left me-1"></i>Logout</a>
        </div>
    </div>

    <!-- Main -->
    <div class="flex-fill p-4">
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($success) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        <?php if ($msg === 'deleted'): ?>
            <div class="alert alert-warning alert-dismissible fade show"><i class="bi bi-trash me-2"></i>User deleted.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php elseif ($msg === 'toggled'): ?>
            <div class="alert alert-info alert-dismissible fade show"><i class="bi bi-toggle-on me-2"></i>User status toggled.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>

        <!-- My Profile -->
        <div class="form-section">
            <h5><i class="bi bi-person-circle me-2"></i>My Profile</h5>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="profile">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Username</label>
                        <input type="text" class="form-control" value="<?= htmlspecialchars($profile['username']) ?>" disabled>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Full Name</label>
                        <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($profile['full_name']) ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Role</label>
                        <input type="text" class="form-control" value="<?= ucfirst($profile['role']) ?>" disabled>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">New Password <small class="text-muted">(leave blank to keep)</small></label>
                        <input type="password" name="new_password" class="form-control" placeholder="New password" minlength="6">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Confirm Password</label>
                        <input type="password" name="confirm_password" class="form-control" placeholder="Confirm password">
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <button type="submit" class="btn btn-odoo"><i class="bi bi-check-lg me-1"></i>Update Profile</button>
                    </div>
                </div>
            </form>
        </div>

        <?php if ($canManage): ?>
        <!-- Create / Edit User -->
        <div class="form-section">
            <h5><i class="bi bi-person-plus me-2"></i><?= $editUser ? 'Edit User' : 'Create New User' ?></h5>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="<?= $editUser ? 'edit_user' : 'create_user' ?>">
                <?php if ($editUser): ?><input type="hidden" name="edit_id" value="<?= $editUser['id'] ?>"><?php endif; ?>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Username (email)</label>
                        <input type="email" name="username" class="form-control" value="<?= htmlspecialchars($editUser['username'] ?? '') ?>" <?= $editUser ? 'disabled' : 'required' ?> placeholder="user@uttara.ac.bd">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Full Name</label>
                        <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($editUser['full_name'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Password <?= $editUser ? '<small class="text-muted">(blank=keep)</small>' : '' ?></label>
                        <input type="password" name="password" class="form-control" <?= $editUser ? '' : 'required' ?> minlength="6">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Role</label>
                        <select name="role" class="form-select">
                            <option value="admin" <?= ($editUser['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Admin</option>
                            <option value="monitor" <?= ($editUser['role'] ?? 'monitor') === 'monitor' ? 'selected' : '' ?>>Monitor</option>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end gap-2">
                        <button type="submit" class="btn btn-odoo"><i class="bi bi-<?= $editUser ? 'pencil' : 'plus-lg' ?> me-1"></i><?= $editUser ? 'Update' : 'Create' ?></button>
                        <?php if ($editUser): ?>
                            <a href="users.php" class="btn btn-outline-secondary">Cancel</a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>

        <!-- User List -->
        <div class="card">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-shield-lock me-2"></i>All Portal Users</h5>
                <span class="badge bg-secondary"><?= count($allUsers) ?> users</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Username</th>
                            <th>Full Name</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th style="width: 150px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allUsers as $u): ?>
                        <tr>
                            <td><?= $u['id'] ?></td>
                            <td><?= htmlspecialchars($u['username']) ?></td>
                            <td><?= htmlspecialchars($u['full_name']) ?></td>
                            <td><span class="badge <?= $u['role'] === 'admin' ? 'bg-danger' : 'bg-info' ?>"><?= ucfirst($u['role']) ?></span></td>
                            <td><span class="badge <?= $u['active'] ? 'bg-success' : 'bg-secondary' ?>"><?= $u['active'] ? 'Active' : 'Inactive' ?></span></td>
                            <td><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
                            <td>
                                <?php if ($u['id'] !== $me['id']): ?>
                                    <a href="users.php?edit=<?= $u['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="bi bi-pencil"></i></a>
                                    <a href="users.php?toggle=<?= $u['id'] ?>" class="btn btn-sm btn-outline-warning" title="Toggle Active"><i class="bi bi-toggle-<?= $u['active'] ? 'on' : 'off' ?>"></i></a>
                                    <a href="users.php?delete=<?= $u['id'] ?>" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return confirm('Delete this user?')"><i class="bi bi-trash"></i></a>
                                <?php else: ?>
                                    <span class="text-muted small">Current user</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php else: ?>
        <div class="alert alert-info"><i class="bi bi-info-circle me-2"></i>Only administrators can manage users.</div>
        <?php endif; ?>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
