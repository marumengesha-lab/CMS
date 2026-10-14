<?php
/**
 * Audit trail. Every meaningful write goes through here so the audit.php
 * screen (and compliance reviews) can reconstruct who did what, where.
 */
declare(strict_types=1);

final class Audit
{
    /**
     * @param string $action      e.g. 'department.create'
     * @param array  $opts        entity_type, entity_id, institution_id,
     *                            department_id, details (array), user_id
     */
    public static function log(string $action, array $opts = []): void
    {
        try {
            $user  = Auth::user();
            $scope = $user ? Scope::forUser($user) : null;

            $details = $opts['details'] ?? null;
            if (is_array($details)) {
                $details = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            Database::insert('audit_logs', [
                'user_id'        => $opts['user_id'] ?? ($user['id'] ?? null),
                'username'       => $opts['username'] ?? ($user['username'] ?? null),
                'action'         => $action,
                'entity_type'    => $opts['entity_type'] ?? null,
                'entity_id'      => $opts['entity_id'] ?? null,
                'institution_id' => $opts['institution_id'] ?? ($scope?->institutionId()),
                'department_id'  => $opts['department_id'] ?? ($scope?->departmentId()),
                'scope_type'     => $scope?->type() ?? Scope::NONE,
                'scope_id'       => $scope?->isGlobal() ? null : ($scope?->isDepartment() ? $scope->departmentId() : $scope?->institutionId()),
                'details'        => $details,
                'ip_address'     => client_ip(),
                'user_agent'     => user_agent(),
            ]);
        } catch (Throwable $e) {
            // Auditing must never break the business operation.
            error_log('Audit log failed: ' . $e->getMessage());
        }
    }

    /** Scope-filtered audit listing. */
    public static function listing(Scope $scope, array $filters = [], int $limit = 200): array
    {
        $params = [];
        $sql = 'SELECT a.*, i.`name` AS institution_name, d.`name` AS department_name
                  FROM `audit_logs` a
                  LEFT JOIN `institutions` i ON i.`id` = a.`institution_id`
                  LEFT JOIN `departments`  d ON d.`id` = a.`department_id`';

        // Global scope sees everything (including rows without a scope).
        if (!$scope->isGlobal()) {
            [$clause, $bind] = $scope->where('a', [
                'institution' => 'institution_id',
                'department'  => 'department_id',
            ]);
            $sql .= ' WHERE (' . $clause . ')';
            $params = array_merge($params, $bind);
        } else {
            $sql .= ' WHERE 1 = 1';
        }

        if (!empty($filters['action'])) {
            $sql .= ' AND a.`action` = ?';
            $params[] = $filters['action'];
        }
        if (!empty($filters['user_id'])) {
            $sql .= ' AND a.`user_id` = ?';
            $params[] = (int) $filters['user_id'];
        }
        if (!empty($filters['from'])) {
            $sql .= ' AND a.`created_at` >= ?';
            $params[] = $filters['from'] . ' 00:00:00';
        }
        if (!empty($filters['to'])) {
            $sql .= ' AND a.`created_at` <= ?';
            $params[] = $filters['to'] . ' 23:59:59';
        }

        $sql .= ' ORDER BY a.`id` DESC LIMIT ' . max(1, min($limit, 1000));
        return Database::all($sql, $params);
    }
}
