#!/usr/bin/env php
<?php
/**
 * Cron Fallback Sync — catches any employee records that the webhook missed.
 * Finds rows where updated_at > synced_at (or synced_at IS NULL) and syncs to Odoo.
 *
 * Run via cron: every 5 min * * * * /usr/bin/php /var/www/html/cron_sync.php >> /var/log/odoo_sync.log 2>&1
 */

// Load the same config and Odoo API
require_once __DIR__ . '/config.php';

$startTime = microtime(true);
$logPrefix = date('Y-m-d H:i:s') . ' [CRON]';

echo "$logPrefix Starting sync...\n";

// Find dirty records: never synced OR updated after last sync
$stmt = $pdo->query("
    SELECT * FROM employees
    WHERE synced_at IS NULL
       OR updated_at > synced_at
    ORDER BY id
");
$dirty = $stmt->fetchAll();

if (empty($dirty)) {
    echo "$logPrefix No changes to sync.\n";
    exit(0);
}

echo "$logPrefix Found " . count($dirty) . " record(s) to sync.\n";

$synced = 0;
$errors = 0;

foreach ($dirty as $emp) {
    $mysqlId = $emp['id'];
    $name = $emp['name'];

    try {
        if (empty($emp['odoo_employee_id'])) {
            // New record — create in Odoo
            $odooId = $odoo->createEmployee($emp);
            $pdo->prepare("UPDATE employees SET odoo_employee_id = ?, synced_at = NOW() WHERE id = ?")
                ->execute([$odooId, $mysqlId]);

            // Grant portal access
            if (!empty($emp['work_email'])) {
                try { $odoo->grantPortalAccess($odooId); } catch (Exception $ex) {}
            }

            echo "$logPrefix CREATE '$name' (MySQL:$mysqlId → Odoo:$odooId)\n";

            $pdo->prepare("INSERT INTO sync_log (employee_id, action, source, status, message) VALUES (?, 'create', 'cron', 'success', ?)")
                ->execute([$mysqlId, "Created Odoo ID: $odooId"]);

        } else {
            // Existing record — update in Odoo
            $odooId = (int)$emp['odoo_employee_id'];
            $odoo->updateEmployee($odooId, $emp);
            $pdo->prepare("UPDATE employees SET synced_at = NOW() WHERE id = ?")->execute([$mysqlId]);

            // Ensure portal access
            if (!empty($emp['work_email'])) {
                try { $odoo->grantPortalAccess($odooId); } catch (Exception $ex) {}
            }

            echo "$logPrefix UPDATE '$name' (MySQL:$mysqlId → Odoo:$odooId)\n";

            $pdo->prepare("INSERT INTO sync_log (employee_id, action, source, status, message) VALUES (?, 'update', 'cron', 'success', ?)")
                ->execute([$mysqlId, "Updated Odoo ID: $odooId"]);
        }

        $synced++;

    } catch (Exception $e) {
        $errors++;
        echo "$logPrefix ERROR '$name' (MySQL:$mysqlId): " . $e->getMessage() . "\n";

        $pdo->prepare("INSERT INTO sync_log (employee_id, action, source, status, message) VALUES (?, 'update', 'cron', 'error', ?)")
            ->execute([$mysqlId, $e->getMessage()]);
    }
}

$elapsed = round(microtime(true) - $startTime, 2);
echo "$logPrefix Done. Synced: $synced, Errors: $errors, Time: {$elapsed}s\n";
