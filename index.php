<?php
require_once 'auth.php';
requireLogin();
require_once 'config.php';

$authUser = currentUser();
$isAdmin = isAdmin();

$odoo_error = '';
$odoo_success = '';

// Handle Delete
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    // Get odoo_employee_id before deleting
    $stmt = $pdo->prepare("SELECT odoo_employee_id FROM employees WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    // Delete from Odoo first
    if ($row && $row['odoo_employee_id']) {
        try {
            $odoo->deleteEmployee((int)$row['odoo_employee_id']);
        } catch (Exception $e) {
            // Log but don't block local delete
            $odoo_error = 'Odoo sync: ' . $e->getMessage();
        }
    }

    $stmt = $pdo->prepare("DELETE FROM employees WHERE id = ?");
    $stmt->execute([$id]);
    header("Location: index.php?msg=deleted");
    exit;
}


// Handle Sync All to Odoo
if (isset($_GET['sync_all'])) {
    $allEmps = $pdo->query("SELECT * FROM employees")->fetchAll();
    $synced = 0;
    $errors = [];
    foreach ($allEmps as $emp) {
        try {
            if ($emp['odoo_employee_id']) {
                $odoo->updateEmployee((int)$emp['odoo_employee_id'], $emp);
                // Ensure portal access exists
                if (!empty($emp['work_email'])) {
                    try { $odoo->grantPortalAccess((int)$emp['odoo_employee_id']); } catch (Exception $ex) {}
                }
            } else {
                $odooId = $odoo->createEmployee($emp);
                $pdo->prepare("UPDATE employees SET odoo_employee_id = ? WHERE id = ?")->execute([$odooId, $emp['id']]);
                // Grant portal access for new employee
                if (!empty($emp['work_email'])) {
                    try { $odoo->grantPortalAccess($odooId); } catch (Exception $ex) {}
                }
            }
            $synced++;
        } catch (Exception $e) {
            $errors[] = $emp['name'] . ': ' . $e->getMessage();
        }
    }
    $errMsg = '';
    if ($errors) {
        $errMsg = '&odoo_err=' . urlencode(implode(' | ', $errors));
    }
    header("Location: index.php?msg=synced&synced_count=$synced&total_count=" . count($allEmps) . $errMsg);
    exit;
}

// Handle Create / Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fields = [
        'name', 'employee_id', 'job_title', 'department',
        'work_email', 'private_address', 'private_email',
        'date_of_birth', 'gender',
        'nationality', 'identification_no',
        'emergency_contact', 'emergency_phone', 'employee_type',
        'elms_employee_type', 'joining_date', 'active'
    ];

    $data = [];
    foreach ($fields as $f) {
        $val = isset($_POST[$f]) ? trim($_POST[$f]) : '';
        $data[$f] = ($val === '') ? null : $val;
    }
    $data['active'] = isset($_POST['active']) ? 1 : 0;

    // Handle photo upload
    $photo = null;
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $_FILES['photo']['tmp_name']);
        finfo_close($finfo);
        if (in_array($mime, $allowed) && $_FILES['photo']['size'] <= 5 * 1024 * 1024) {
            $photo = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($_FILES['photo']['tmp_name']));
        }
    }

    if (isset($_POST['id']) && $_POST['id'] !== '') {
        // UPDATE
        $id = (int)$_POST['id'];
        $set = implode(', ', array_map(fn($f) => "$f = ?", $fields));
        $params = array_values($data);
        if ($photo !== null) {
            $set .= ', photo = ?';
            $params[] = $photo;
        }
        $params[] = $id;
        $stmt = $pdo->prepare("UPDATE employees SET $set WHERE id = ?");
        $stmt->execute($params);

        // Sync to Odoo
        $stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
        $stmt->execute([$id]);
        $emp = $stmt->fetch();
        if ($emp && $emp['odoo_employee_id']) {
            try {
                $odoo->updateEmployee((int)$emp['odoo_employee_id'], $emp);
            } catch (Exception $e) {
                // Store error in session-like param
                header("Location: index.php?msg=updated&odoo_err=" . urlencode($e->getMessage()));
                exit;
            }
        } elseif ($emp) {
            // No odoo ID yet, create in Odoo
            try {
                $odooId = $odoo->createEmployee($emp);
                $pdo->prepare("UPDATE employees SET odoo_employee_id = ? WHERE id = ?")->execute([$odooId, $id]);
            } catch (Exception $e) {
                header("Location: index.php?msg=updated&odoo_err=" . urlencode($e->getMessage()));
                exit;
            }
        }
        header("Location: index.php?msg=updated");
    } else {
        // CREATE
        $fields_with_photo = $fields;
        $values = array_values($data);
        if ($photo !== null) {
            $fields_with_photo[] = 'photo';
            $values[] = $photo;
        }
        $placeholders = implode(', ', array_fill(0, count($fields_with_photo), '?'));
        $cols = implode(', ', $fields_with_photo);
        $stmt = $pdo->prepare("INSERT INTO employees ($cols) VALUES ($placeholders)");
        $stmt->execute($values);
        $newId = $pdo->lastInsertId();

        // Fetch full record and sync to Odoo
        $stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
        $stmt->execute([$newId]);
        $emp = $stmt->fetch();

        try {
            $odooId = $odoo->createEmployee($emp);
            $pdo->prepare("UPDATE employees SET odoo_employee_id = ? WHERE id = ?")->execute([$odooId, $newId]);
            // Grant portal access and link user to employee
            if (!empty($emp['work_email'])) {
                $odoo->grantPortalAccess($odooId);
            }
        } catch (Exception $e) {
            header("Location: index.php?msg=created&odoo_err=" . urlencode($e->getMessage()));
            exit;
        }
        header("Location: index.php?msg=created");
    }
    exit;
}

// Fetch employees for listing
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
if ($search !== '') {
    $stmt = $pdo->prepare("SELECT * FROM employees WHERE name LIKE ? OR employee_id LIKE ? OR department LIKE ? OR work_email LIKE ? ORDER BY name");
    $like = "%$search%";
    $stmt->execute([$like, $like, $like, $like]);
} else {
    $stmt = $pdo->query("SELECT * FROM employees ORDER BY name");
}
$employees = $stmt->fetchAll();

// Fetch single employee for edit
$edit = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
    $stmt->execute([(int)$_GET['edit']]);
    $edit = $stmt->fetch();
}

$msg = $_GET['msg'] ?? '';
$odoo_err = $_GET['odoo_err'] ?? '';

// Fetch ELMS Employee Types from Odoo for dropdown
$elmsTypes = [];
try { $elmsTypes = $odoo->getElmsEmployeeTypes(); } catch (Exception $e) {}

// Fetch ELMS Employee Types from Odoo for dropdown
$elmsTypes = [];
try { $elmsTypes = $odoo->getElmsEmployeeTypes(); } catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API Controller</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background: #f4f6f9; }
        .sidebar { background: #714B67; min-height: 100vh; color: #fff; }
        .sidebar .nav-link { color: rgba(255,255,255,.8); }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: #fff; background: rgba(255,255,255,.1); }
        .emp-photo { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; }
        .emp-photo-lg { width: 120px; height: 120px; border-radius: 50%; object-fit: cover; border: 3px solid #714B67; }
        .card { border: none; box-shadow: 0 1px 3px rgba(0,0,0,.1); }
        .form-section { background: #fff; border-radius: 8px; padding: 20px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        .form-section h5 { color: #714B67; border-bottom: 2px solid #714B67; padding-bottom: 8px; margin-bottom: 16px; }
        .btn-odoo { background: #714B67; border-color: #714B67; color: #fff; }
        .btn-odoo:hover { background: #5a3c53; border-color: #5a3c53; color: #fff; }
        .table th { background: #714B67; color: #fff; font-weight: 500; }
        .badge-active { background: #28a745; }
        .badge-inactive { background: #dc3545; }
        .badge-synced { background: #17a2b8; }
        .badge-notsync { background: #6c757d; }
    </style>
</head>
<body>
<div class="d-flex">
    <!-- Sidebar -->
    <div class="sidebar d-flex flex-column p-3" style="width: 250px;">
        <h4 class="mb-4"><i class="bi bi-people-fill me-2"></i>API Controller</h4>
        <nav class="nav flex-column">
            <a class="nav-link active" href="index.php"><i class="bi bi-person-lines-fill me-2"></i>Employees</a>
            <a class="nav-link" href="users.php"><i class="bi bi-shield-lock me-2"></i>Users & Profile</a>
        </nav>
        <div class="mt-auto">
            <div class="small opacity-75 mb-2"><i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($authUser['full_name']) ?> <span class="badge bg-light text-dark"><?= $authUser['role'] ?></span></div>
            <a href="logout.php" class="btn btn-sm btn-outline-light w-100"><i class="bi bi-box-arrow-left me-1"></i>Logout</a>
        </div>
    </div>

    <!-- Main -->
    <div class="flex-fill p-4">
        <?php if ($odoo_err): ?>
            <div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle me-2"></i><strong>Odoo Sync Error:</strong> <?= htmlspecialchars($odoo_err) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        <?php if ($msg === 'created'): ?>
            <div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i>Employee created and synced to Odoo.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php elseif ($msg === 'updated'): ?>
            <div class="alert alert-info alert-dismissible fade show"><i class="bi bi-pencil me-2"></i>Employee updated and synced to Odoo.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php elseif ($msg === 'deleted'): ?>
            <div class="alert alert-warning alert-dismissible fade show"><i class="bi bi-trash me-2"></i>Employee deleted (also removed from Odoo).<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php elseif ($msg === 'synced'): ?>
            <div class="alert alert-success alert-dismissible fade show"><i class="bi bi-cloud-check me-2"></i><strong><?= htmlspecialchars($_GET['synced_count'] ?? '0') ?>/<?= htmlspecialchars($_GET['total_count'] ?? '0') ?></strong> employees synced to Odoo successfully.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>

        <?php if (isset($_GET['edit']) || isset($_GET['new'])): ?>
        <!-- CREATE / EDIT FORM -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3><i class="bi bi-person-plus me-2"></i><?= $edit ? 'Edit Employee' : 'New Employee' ?></h3>
            <div>
                <?php if ($edit && $edit['odoo_employee_id']): ?>
                    <span class="badge badge-synced me-2"><i class="bi bi-cloud-check me-1"></i>Odoo ID: <?= $edit['odoo_employee_id'] ?></span>
                <?php endif; ?>
                <a href="index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back to List</a>
            </div>
        </div>

        <form method="POST" enctype="multipart/form-data">
            <?= csrfField() ?>
            <?php if ($edit): ?><input type="hidden" name="id" value="<?= $edit['id'] ?>"><?php endif; ?>

            <div class="form-section">
                <div class="row align-items-center">
                    <div class="col-auto">
                        <?php if ($edit && $edit['photo']): ?>
                            <img src="<?= htmlspecialchars($edit['photo']) ?>" class="emp-photo-lg" alt="Photo">
                        <?php else: ?>
                            <div class="emp-photo-lg bg-light d-flex align-items-center justify-content-center"><i class="bi bi-person-fill" style="font-size:3rem;color:#ccc;"></i></div>
                        <?php endif; ?>
                        <input type="file" name="photo" class="form-control form-control-sm mt-2" accept="image/*">
                    </div>
                    <div class="col">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Employee Name <span class="text-danger">*</span></label>
                                <input type="text" name="name" class="form-control form-control-lg" value="<?= htmlspecialchars($edit['name'] ?? '') ?>" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-bold">Employee ID</label>
                                <input type="text" name="employee_id" class="form-control" value="<?= htmlspecialchars($edit['employee_id'] ?? '') ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-bold">Status</label>
                                <div class="form-check form-switch mt-2">
                                    <input class="form-check-input" type="checkbox" name="active" <?= (!$edit || $edit['active']) ? 'checked' : '' ?>>
                                    <label class="form-check-label">Active</label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h5><i class="bi bi-briefcase me-2"></i>Work Information</h5>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Job Title</label>
                        <input type="text" name="job_title" class="form-control" value="<?= htmlspecialchars($edit['job_title'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Department</label>
                        <input type="text" name="department" class="form-control" value="<?= htmlspecialchars($edit['department'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Work Email</label>
                        <input type="email" name="work_email" class="form-control" value="<?= htmlspecialchars($edit['work_email'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Employee Type</label>
                        <select name="employee_type" class="form-select">
                            <option value="employee" <?= ($edit['employee_type'] ?? 'employee') === 'employee' ? 'selected' : '' ?>>Employee</option>
                            <option value="student" <?= ($edit['employee_type'] ?? '') === 'student' ? 'selected' : '' ?>>Student</option>
                            <option value="freelancer" <?= ($edit['employee_type'] ?? '') === 'freelancer' ? 'selected' : '' ?>>Freelancer</option>
                            <option value="contractor" <?= ($edit['employee_type'] ?? '') === 'contractor' ? 'selected' : '' ?>>Contractor</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">ELMS Employee Type</label>
                        <select name="elms_employee_type" class="form-select">
                            <option value="">--</option>
                            <?php foreach ($elmsTypes as $et): ?>
                            <option value="<?= htmlspecialchars($et['name']) ?>" <?= ($edit['elms_employee_type'] ?? '') === $et['name'] ? 'selected' : '' ?>><?= htmlspecialchars($et['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Joining Date</label>
                        <input type="date" name="joining_date" class="form-control" value="<?= htmlspecialchars($edit['joining_date'] ?? '') ?>">
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h5><i class="bi bi-shield-lock me-2"></i>Private Information</h5>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Private Address</label>
                        <textarea name="private_address" class="form-control" rows="2"><?= htmlspecialchars($edit['private_address'] ?? '') ?></textarea>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Private Email</label>
                        <input type="email" name="private_email" class="form-control" value="<?= htmlspecialchars($edit['private_email'] ?? '') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Date of Birth</label>
                        <input type="date" name="date_of_birth" class="form-control" value="<?= htmlspecialchars($edit['date_of_birth'] ?? '') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Gender</label>
                        <select name="gender" class="form-select">
                            <option value="">--</option>
                            <option value="male" <?= ($edit['gender'] ?? '') === 'male' ? 'selected' : '' ?>>Male</option>
                            <option value="female" <?= ($edit['gender'] ?? '') === 'female' ? 'selected' : '' ?>>Female</option>
                            <option value="other" <?= ($edit['gender'] ?? '') === 'other' ? 'selected' : '' ?>>Other</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Nationality</label>
                        <input type="text" name="nationality" class="form-control" value="<?= htmlspecialchars($edit['nationality'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Identification No (NID)</label>
                        <input type="text" name="identification_no" class="form-control" value="<?= htmlspecialchars($edit['identification_no'] ?? '') ?>">
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h5><i class="bi bi-exclamation-triangle me-2"></i>Emergency Contact</h5>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Emergency Contact Name</label>
                        <input type="text" name="emergency_contact" class="form-control" value="<?= htmlspecialchars($edit['emergency_contact'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Emergency Phone</label>
                        <input type="text" name="emergency_phone" class="form-control" value="<?= htmlspecialchars($edit['emergency_phone'] ?? '') ?>">
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2 mb-4">
                <button type="submit" class="btn btn-odoo btn-lg"><i class="bi bi-check-lg me-1"></i><?= $edit ? 'Update Employee' : 'Create Employee' ?></button>
                <a href="index.php" class="btn btn-outline-secondary btn-lg">Cancel</a>
            </div>
        </form>

        <?php else: ?>
        <!-- EMPLOYEE LIST -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3><i class="bi bi-people me-2"></i>Employees <span class="badge bg-secondary"><?= count($employees) ?></span></h3>
            <div class="d-flex gap-2"><a href="index.php?sync_all=1" class="btn btn-outline-info" onclick="return confirm('Sync all employees to Odoo?')"><i class="bi bi-arrow-repeat me-1"></i>Sync All to Odoo</a><a href="index.php?new=1" class="btn btn-odoo"><i class="bi bi-plus-lg me-1"></i>New Employee</a></div>
        </div>

        <div class="card mb-3">
            <div class="card-body py-2">
                <form method="GET" class="row g-2 align-items-center">
                    <div class="col-auto flex-fill">
                        <input type="text" name="search" class="form-control" placeholder="Search by name, ID, department, or email..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                    <div class="col-auto">
                        <button class="btn btn-odoo"><i class="bi bi-search"></i></button>
                    </div>
                    <?php if ($search): ?>
                    <div class="col-auto">
                        <a href="index.php" class="btn btn-outline-secondary">Clear</a>
                    </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th style="width:50px;"></th>
                            <th>Name</th>
                            <th>Employee ID</th>
                            <th>Job Title</th>
                            <th>Department</th>
                            <th>Work Email</th>
                            <th>Status</th>
                            <th>Odoo</th>
                            <th style="width:120px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($employees)): ?>
                        <tr><td colspan="9" class="text-center py-5 text-muted"><i class="bi bi-inbox" style="font-size:2rem;"></i><br>No employees found.</td></tr>
                        <?php else: ?>
                        <?php foreach ($employees as $emp): ?>
                        <tr>
                            <td>
                                <?php if ($emp['photo']): ?>
                                    <img src="<?= htmlspecialchars($emp['photo']) ?>" class="emp-photo" alt="">
                                <?php else: ?>
                                    <div class="emp-photo bg-light d-flex align-items-center justify-content-center"><i class="bi bi-person-fill text-muted"></i></div>
                                <?php endif; ?>
                            </td>
                            <td class="fw-bold"><?= htmlspecialchars($emp['name']) ?></td>
                            <td><?= htmlspecialchars($emp['employee_id'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($emp['job_title'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($emp['department'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($emp['work_email'] ?? '-') ?></td>
                            <td><span class="badge <?= $emp['active'] ? 'badge-active' : 'badge-inactive' ?>"><?= $emp['active'] ? 'Active' : 'Inactive' ?></span></td>
                            <td>
                                <?php if ($emp['odoo_employee_id']): ?>
                                    <span class="badge badge-synced" title="Odoo Employee ID: <?= $emp['odoo_employee_id'] ?>"><i class="bi bi-cloud-check"></i> #<?= $emp['odoo_employee_id'] ?></span>
                                <?php else: ?>
                                    <span class="badge badge-notsync"><i class="bi bi-cloud-slash"></i></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($isAdmin): ?>
                                <a href="index.php?edit=<?= $emp['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="bi bi-pencil"></i></a>
                                <a href="index.php?delete=<?= $emp['id'] ?>" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return confirm('Are you sure you want to delete this employee?')"><i class="bi bi-trash"></i></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
