<?php
class OdooAPI {
    private string $url;
    private string $db;
    private string $username;
    private string $password;
    private ?int $uid = null;
    private int $requestId = 0;

    public function __construct(string $url, string $db, string $username, string $password, ?int $uid = null) {
        $this->url = rtrim($url, '/');
        $this->db = $db;
        $this->username = $username;
        $this->password = $password;
        $this->uid = $uid;
    }

    private function jsonrpcCall(string $endpoint, string $service, string $method, array $args): mixed {
        $this->requestId++;
        $payload = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'call',
            'params' => [
                'service' => $service,
                'method' => $method,
                'args' => $args,
            ],
            'id' => $this->requestId,
        ]);

        $ch = curl_init("{$this->url}{$endpoint}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            throw new Exception('Odoo connection failed: ' . curl_error($ch));
        }
        curl_close($ch);

        $result = json_decode($response, true);
        if (!is_array($result)) {
            throw new Exception('Invalid JSON response from Odoo');
        }
        if (isset($result['error'])) {
            $err = $result['error'];
            $msg = $err['data']['message'] ?? $err['message'] ?? 'Unknown error';
            throw new Exception('Odoo error: ' . $msg);
        }
        return $result['result'] ?? null;
    }

    public function authenticate(): int {
        if ($this->uid !== null) return $this->uid;
        $this->uid = $this->jsonrpcCall('/jsonrpc', 'common', 'login', [
            $this->db, $this->username, $this->password,
        ]);
        if (!$this->uid) {
            throw new Exception('Odoo authentication failed');
        }
        return $this->uid;
    }

    public function execute(string $model, string $method, array $args = [], array $kwargs = []): mixed {
        $uid = $this->authenticate();
        $callArgs = [$this->db, $uid, $this->password, $model, $method, $args];
        if (!empty($kwargs)) $callArgs[] = $kwargs;
        return $this->jsonrpcCall('/jsonrpc', 'object', 'execute_kw', $callArgs);
    }

    private function findOrCreate(string $model, string $name): ?int {
        if (empty($name)) return null;
        $ids = $this->execute($model, 'search', [[['name', '=', $name]]]);
        if (!empty($ids)) return $ids[0];
        return $this->execute($model, 'create', [['name' => $name]]);
    }

    public function getElmsEmployeeTypes(): array {
        $ids = $this->execute('elms.employee.type', 'search_read',
            [[['active', '=', true]]],
            ['fields' => ['id', 'name'], 'order' => 'sequence, id']);
        return $ids ?: [];
    }

    public function mapToOdoo(array $emp): array {
        $vals = [
            'name' => $emp['name'],
            'barcode' => isset($emp['employee_id']) && $emp['employee_id'] !== '' ? preg_replace('/[^a-zA-Z0-9]/', '', $emp['employee_id']) : false,
            'job_title' => $emp['job_title'] ?? false,
            'work_email' => $emp['work_email'] ?? false,
            'private_street' => $emp['private_address'] ?? false,
            'private_email' => $emp['private_email'] ?? false,
            'birthday' => $emp['date_of_birth'] ?: false,
            'identification_id' => $emp['identification_no'] ?? false,
            'emergency_contact' => $emp['emergency_contact'] ?? false,
            'emergency_phone' => $emp['emergency_phone'] ?? false,
            'active' => (bool)($emp['active'] ?? true),
        ];

        if (!empty($emp['gender'])) {
            $vals['sex'] = $emp['gender'];
        }
        if (!empty($emp['employee_type'])) {
            $typeMap = ['freelancer' => 'freelance'];
            $vals['employee_type'] = $typeMap[$emp['employee_type']] ?? $emp['employee_type'];
        }
        if (!empty($emp['department'])) {
            $vals['department_id'] = $this->findOrCreate('hr.department', $emp['department']);
        }
        if (!empty($emp['elms_employee_type'])) {
            $ids = $this->execute('elms.employee.type', 'search', [[['name', '=', $emp['elms_employee_type']]]]);
            if (!empty($ids)) $vals['elms_employee_type_id'] = $ids[0];
        }
        if (!empty($emp['nationality'])) {
            $ids = $this->execute('res.country', 'search', [[['name', 'ilike', $emp['nationality']]]]);
            if (!empty($ids)) $vals['country_id'] = $ids[0];
        }
        if (!empty($emp['photo'])) {
            $parts = explode(',', $emp['photo'], 2);
            if (count($parts) === 2) {
                $vals['image_1920'] = $parts[1];
            }
        }

        return $vals;
    }

    public function createEmployee(array $emp): int {
        $vals = $this->mapToOdoo($emp);
        return $this->execute('hr.employee', 'create', [$vals]);
    }

    public function updateEmployee(int $odooId, array $emp): bool {
        $vals = $this->mapToOdoo($emp);
        $result = $this->execute('hr.employee', 'write', [[$odooId], $vals]);

        // Sync active status and profile picture to linked portal user
        if (isset($vals['active']) || isset($vals['image_1920'])) {
            try {
                $empData = $this->execute('hr.employee', 'read', [[$odooId]],
                    ['fields' => ['user_id'], 'context' => ['active_test' => false]]);
                if (!empty($empData[0]['user_id'])) {
                    $userId = $empData[0]['user_id'][0];
                    $userVals = [];
                    if (isset($vals['active'])) $userVals['active'] = $vals['active'];
                    if (isset($vals['image_1920'])) $userVals['image_1920'] = $vals['image_1920'];
                    if ($userVals) {
                        $this->execute('res.users', 'write', [[$userId], $userVals]);
                    }
                }
            } catch (Exception $e) {
                // Don't block employee update if user sync fails
            }
        }

        return $result;
    }

    public function deleteEmployee(int $odooId): bool {
        try {
            // Read linked user before deleting
            $emp = $this->execute('hr.employee', 'read', [[$odooId]],
                ['fields' => ['user_id'], 'context' => ['active_test' => false]]);
            $userId = ($emp && !empty($emp[0]['user_id'])) ? $emp[0]['user_id'][0] : null;

            // Clear user link from employee first (avoids FK constraint)
            if ($userId) {
                $this->execute('hr.employee', 'write', [[$odooId], ['user_id' => false]]);
            }

            // Archive then delete the employee
            $this->execute('hr.employee', 'write', [[$odooId], ['active' => false]]);
            $this->execute('hr.employee', 'unlink', [[$odooId]]);

            // Archive the portal user (don't delete - may have audit trail)
            if ($userId) {
                try {
                    $this->execute('res.users', 'write', [[$userId], ['active' => false]]);
                } catch (Exception $e) {
                    // User may already be archived or have other references
                }
            }

            return true;
        } catch (Exception $e) {
            return true;
        }
    }

    public function grantPortalAccess(int $odooEmployeeId): int {
        $emp = $this->execute('hr.employee', 'read', [[$odooEmployeeId]],
            ['fields' => ['name', 'work_email', 'work_contact_id', 'user_id']]);

        if (empty($emp)) {
            throw new Exception("Employee not found in Odoo (ID: $odooEmployeeId)");
        }
        $emp = $emp[0];

        if (!empty($emp['user_id']) && $emp['user_id']) {
            return $emp['user_id'][0];
        }

        $email = $emp['work_email'] ?? '';
        if (empty($email)) {
            throw new Exception("Employee '{$emp['name']}' has no work email - cannot create portal user");
        }

        $partnerId = $emp['work_contact_id'][0] ?? null;
        if (!$partnerId) {
            throw new Exception("Employee '{$emp['name']}' has no work contact partner");
        }

        $existingUsers = $this->execute('res.users', 'search', [[['login', '=', $email]]], ['context' => ['active_test' => false]]);
        if (!empty($existingUsers)) {
            $userId = $existingUsers[0];
            $this->execute('hr.employee', 'write', [[$odooEmployeeId], ['user_id' => $userId]]);
            return $userId;
        }

        $userId = $this->execute('res.users', 'create', [[
            'name' => $emp['name'],
            'login' => $email,
            'partner_id' => $partnerId,
            'group_ids' => [[6, 0, [10, 61]]],
        ]]);

        $this->execute('hr.employee', 'write', [[$odooEmployeeId], ['user_id' => $userId]]);

        return $userId;
    }
}
