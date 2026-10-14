<?php
/**
 * Read-only access to the permission catalogue.
 * Permissions are code-defined (seeded), not user-editable at runtime.
 */
declare(strict_types=1);

final class PermissionController extends Controller
{
    public function index(array $filters = []): array
    {
        $this->authorize('permission.view');

        $params = [];
        $sql    = 'SELECT p.*,
                          (SELECT COUNT(*) FROM `role_permissions` rp WHERE rp.`permission_id` = p.`id`) AS `role_count`
                     FROM `permissions` p WHERE 1 = 1';

        if (!empty($filters['module'])) {
            $sql .= ' AND p.`module` = ?';
            $params[] = $filters['module'];
        }
        if (!empty($filters['q'])) {
            $sql .= ' AND (p.`code` LIKE ? OR p.`name` LIKE ?)';
            $like = '%' . $filters['q'] . '%';
            array_push($params, $like, $like);
        }

        $sql .= ' ORDER BY p.`module`, p.`code`';
        return Database::all($sql, $params);
    }

    /** Same rows, keyed by module - what the permission matrix UI consumes. */
    public function catalogue(): array
    {
        $this->authorize('permission.view');

        $grouped = [];
        foreach ($this->index() as $row) {
            $grouped[$row['module']][] = $row;
        }
        return $grouped;
    }

    public function modules(): array
    {
        $this->authorize('permission.view');
        return Database::all('SELECT `module`, COUNT(*) AS `total` FROM `permissions` GROUP BY `module` ORDER BY `module`');
    }
}
