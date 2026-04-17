<?php
/**
 * Webhook API Endpoint for Employee Sync
 * URL: POST /api/sync.php
 * Auth: Bearer <api_key>
 *
 * Request body (JSON):
 *   { "action": "create|update|delete", "employee_id": <mysql_id> }
 *
 * Or bulk:
 *   { "action": "sync", "employee_ids": [1, 2, 3] }
 */

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';

// --- Rate Limiting (by IP, 60 requests per minute) ---
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$rateLimitKey = 'api_rate_' . md5($clientIp);
$rateFile = sys_get_temp_dir() . '/' . $rateLimitKey;
$rateLimit = 60;
$rateWindow = 60;

if (file_exists($rateFile)) {
    $rateData = json_decode(file_get_contents($rateFile), true);
    if (time() - ($rateData['start'] ?? 0) > $rateWindow) {
        $rateData = ['start' => time(), 'count' => 0];
    }
} else {
    $rateData = ['start' => time(), 'count' => 0];
}
$rateData['count']++;
file_put_contents($rateFile, json_encode($rateData));

if ($rateData['count'] > $rateLimit) {
    http_response_code(429);
    echo json_encode(['status' => 'error', 'message' => 'Rate limit exceeded. Max 60 requests/minute.']);
    exit;
}

// --- Authenticate via Bearer token ---
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
$token = '';
if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
    $token = $m[1];
}

if (empty($token)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Missing Authorization header']);
    exit;
}

// Constant-time key lookup
$stmt = $pdo->prepare("SELECT id, key_name FROM api_keys WHERE api_key = ? AND active = 1");
$stmt->execute([$token]);
$apiKeyRow = $stmt->fetch();

if (!$apiKeyRow) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Invalid API key']);
    exit;
}

// --- Parse request ---
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON body']);
    exit;
}

$action = $input['action'] ?? '';
$employeeId = isset($input['employee_id']) ? (int)$input['employee_id'] : 0;
$employeeIds = $input['employee_ids'] ?? [];

// --- Helper: sync one employee ---
function syncEmployee(PDO $pdo, OdooAPI $odoo, int $mysqlId, string $action, string $source): array {
    // Fetch employee from MySQL
    $stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
    $stmt->execute([$mysqlId]);
    $emp = $stmt->fetch();

    if (!$emp && $action !== 'delete') {
        return ['status' => 'error', 'employee_id' => $mysqlId, 'message' => 'Employee not found in MySQL'];
    }

    try {
        $odooId = null;
        $msg = '';

        if ($action === 'create' || ($action === 'update' && empty($emp['odoo_employee_id']))) {
            // Create in Odoo
            $odooId = $odoo->createEmployee($emp);
            $pdo->prepare("UPDATE employees SET odoo_employee_id = ?, synced_at = NOW() WHERE id = ?")
                ->execute([$odooId, $mysqlId]);

            // Grant portal access
            if (!empty($emp['work_email'])) {
                try { $odoo->grantPortalAccess($odooId); } catch (Exception $ex) {}
            }
            $msg = "Created in Odoo (ID: $odooId) + portal access granted";

        } elseif ($action === 'update') {
            // Update in Odoo
            $odooId = (int)$emp['odoo_employee_id'];
            $odoo->updateEmployee($odooId, $emp);
            $pdo->prepare("UPDATE employees SET synced_at = NOW() WHERE id = ?")->execute([$mysqlId]);

            // Ensure portal access
            if (!empty($emp['work_email'])) {
                try { $odoo->grantPortalAccess($odooId); } catch (Exception $ex) {}
            }
            $msg = "Updated in Odoo (ID: $odooId)";

        } elseif ($action === 'delete') {
            if ($emp && !empty($emp['odoo_employee_id'])) {
                $odooId = (int)$emp['odoo_employee_id'];
                $odoo->deleteEmployee($odooId);
                $msg = "Deleted from Odoo (ID: $odooId)";
            } else {
                $msg = "No Odoo record to delete";
            }
        } else {
            return ['status' => 'error', 'employee_id' => $mysqlId, 'message' => "Invalid action: $action"];
        }

        // Log success
        $logStmt = $pdo->prepare("INSERT INTO sync_log (employee_id, action, source, status, message) VALUES (?, ?, ?, 'success', ?)");
        $logStmt->execute([$mysqlId, $action, $source, $msg]);

        return ['status' => 'ok', 'employee_id' => $mysqlId, 'odoo_employee_id' => $odooId, 'message' => $msg];

    } catch (Exception $e) {
        // Log error
        $logStmt = $pdo->prepare("INSERT INTO sync_log (employee_id, action, source, status, message) VALUES (?, ?, ?, 'error', ?)");
        $logStmt->execute([$mysqlId, $action, $source, $e->getMessage()]);

        return ['status' => 'error', 'employee_id' => $mysqlId, 'message' => $e->getMessage()];
    }
}

// --- Process request ---
if ($action === 'sync' && !empty($employeeIds)) {
    // Bulk sync
    if (!is_array($employeeIds) || count($employeeIds) > 100) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'employee_ids must be an array of max 100 IDs']);
        exit;
    }
    $results = [];
    foreach ($employeeIds as $eid) {
        $results[] = syncEmployee($pdo, $odoo, (int)$eid, 'update', 'webhook');
    }
    echo json_encode(['status' => 'ok', 'results' => $results]);

} elseif (in_array($action, ['create', 'update', 'delete']) && $employeeId > 0) {
    // Single sync
    $result = syncEmployee($pdo, $odoo, $employeeId, $action, 'webhook');
    http_response_code($result['status'] === 'ok' ? 200 : 500);
    echo json_encode($result);

} else {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid request. Required: {"action": "create|update|delete", "employee_id": <int>} or {"action": "sync", "employee_ids": [...]}'
    ]);
}
