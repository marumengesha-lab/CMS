<?php
/**
 * Role management.
 *
 * Roles are templates: `institution_id IS NULL` means a shared, system-wide
 * role; a non-null value is an institution-specific custom role. Which
 * roles a caller may see / create is derived from its scope, and a caller
 * can never attach a permission it does not itself hold (no escalation).
 */
declare(strict_types=1);

final class RoleController extends Controller
{
    /** Roles visible to the current scope. */
    public function index(array $filters = []): array
    {
        $params = [];
        $sql = "SELECT r.*,
                       (SELECT COUNT(*) FROM `role_permissions` rp WHERE rp.`role_id` = r.`id`) AS `permission_count`,
                       (SELECT COUNT(*) FROM `user_roles` ur WHERE ur.`role_id` = r.`id`)        AS `assignment_count`,
                       i.`name` AS `institution_name`
                  FROM `roles` r
                  LEFT JOIN `institutions` i ON i.`id` = r.`institution_id`
                 WHERE 1 = 1";

        if (!$this->scope->isGlobal()) {
            $sql .= ' AND (r.`institution_id` IS NULL OR r.`institution_id` = ?)';
            $params[] = $this->scope->institutionId();
        }

        // Only roles a caller may actually grant.
        $levels = match ($this->scope->type()) {
            Scope::GLOBAL      => ['global', 'institution', 'department'],
            Scope::INSTITUTION => ['institution', 'department'],
            Scope::DEPARTMENT  => ['department'],
            default            => [],
        };
        if ($levels) {
            $sql .= ' AND r.`scope_level` IN (' . implode(',', array_fill(0, count($levels), '?')) . ')';
            $params = array_merge($params, $levels);
        } else {
            $sql .= ' AND 1 = 0';
        }

        if (!empty($filters['scope_level'])) {
            $sql .= ' AND r.`scope_level` = ?';
            $params[] = $filters['scope_level'];
        }
        if (!empty($filters['q'])) {
            $sql .= ' AND (r.`name` LIKE ? OR r.`code` LIKE ?)';
            $like = '%' . $filters['q'] . '%';
            array_push($params, $like, $like);
        }

        $sql .= ' ORDER BY FIELD(r.`scope_level`,\'global\',\'institution\',\'department\'), r.`name`';
        return Database::all($sql, $params);
    }

    public function find(int $id): ?array
    {
        $role = Database::one(
            'SELECT r.*, i.`name` AS institution_name
               FROM `roles` r LEFT JOIN `institutions` i ON i.`id` = r.`institution_id`
              WHERE r.`id` = ?',
            [$id]
        );
        if (!$role) {
            throw new HttpError(404, 'Role not found.');
        }
        $this->assertVisible($role);
        $role['permissions'] = $this->permissionIds($id);
        return $role;
    }

    public function store(array $data): array
    {
        $this->authorize('role.create');
        validate_required($data, ['name' => 'Role name']);

        $scopeLevel = $data['scope_level'] ?? 'department';
        $this->assertCanCreateLevel($scopeLevel);

        $code = $this->codeFrom($data);
        if ($code === '') {
            throw new HttpError(422, 'A code is required.', ['code' => 'Code is required.']);
        }

        // Non-global callers can only create roles inside their institution.
        $institutionId = $scopeLevel === 'global' || $this->scope->isGlobal()
            ? ($data['institution_id'] ?? null)
            : $this->scope->institutionId();
        if ($scopeLevel === 'global') {
            $institutionId = null;
        }

        $dupe = Database::one(
            'SELECT `id` FROM `roles` WHERE `code` = ? AND IFNULL(`institution_id`,0) = IFNULL(?,0)',
            [$code, $institutionId]
        );
        if ($dupe) {
            throw new HttpError(409, "A role with code '{$code}' already exists.", ['code' => 'Code already used.']);
        }

        $id = Database::insert('roles', [
            'code'           => $code,
            'name'           => trim((string) $data['name']),
            'name_am'        => $data['name_am'] ?? null,
            'scope_level'    => $scopeLevel,
            'institution_id' => $institutionId,
            'is_system'      => 0,
            'description'    => $data['description'] ?? null,
            'status'         => $data['status'] ?? 'active',
            'created_by'     => $this->userId(),
        ]);

        $this->audit('role.create', [
            'entity_type' => 'role',
            'entity_id'   => $id,
            'details'     => ['code' => $code, 'scope_level' => $scopeLevel],
        ]);

        return $this->find($id) ?? [];
    }

    public function update(int $id, array $data): array
    {
        $role = $this->find($id);
        $this->authorize('role.update');

        if (!empty($role['is_system'])) {
            throw new HttpError(403, 'System roles cannot be edited. Duplicate them instead.');
        }

        $updates = [];
        foreach (['name', 'name_am', 'description', 'status'] as $field) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = $data[$field] === '' ? null : $data[$field];
            }
        }
        if (array_key_exists('scope_level', $data) && $data['scope_level'] !== $role['scope_level']) {
            $this->assertCanCreateLevel((string) $data['scope_level']);
            $updates['scope_level'] = $data['scope_level'];
        }
        if (!$updates) {
            throw new HttpError(422, 'Nothing to update.');
        }

        Database::update('roles', $updates, ['id' => $id]);
        $this->audit('role.update', ['entity_type' => 'role', 'entity_id' => $id, 'details' => $updates]);
        return $this->find($id) ?? [];
    }

    public function destroy(int $id): void
    {
        $role = $this->find($id);
        $this->authorize('role.delete');

        if (!empty($role['is_system'])) {
            throw new HttpError(403, 'System roles cannot be deleted.');
        }
        $inUse = (int) Database::value('SELECT COUNT(*) FROM `user_roles` WHERE `role_id` = ?', [$id], 0);
        if ($inUse > 0) {
            throw new HttpError(409, "This role is still assigned to {$inUse} user(s). Revoke those first.");
        }

        Database::delete('roles', ['id' => $id]);
        $this->audit('role.delete', ['entity_type' => 'role', 'entity_id' => $id, 'details' => ['code' => $role['code']]]);
    }

    // -----------------------------------------------------------------
    // Permission matrix
    // -----------------------------------------------------------------

    /** @return int[] permission ids attached to the role */
    public function permissionIds(int $roleId): array
    {
        $rows = Database::all('SELECT `permission_id` FROM `role_permissions` WHERE `role_id` = ?', [$roleId]);
        return array_map(static fn ($r) => (int) $r['permission_id'], $rows);
    }

    /** The full permission catalogue, grouped by module. */
    public function catalogue(): array
    {
        $rows = Database::all('SELECT * FROM `permissions` ORDER BY `module`, `code`');
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row['module']][] = $row;
        }
        return $grouped;
    }

    /**
     * Replace the permission set of a role.
     *
     * Escalation guard: a caller may only grant permissions it holds itself
     * (Main Admin is exempt by design).
     */
    public function setPermissions(int $roleId, array $permissionIds): array
    {
        $this->authorize('role.assign_permission');
        $role = $this->find($roleId);

        $permissionIds = array_values(array_unique(array_map('intval', $permissionIds)));
        if (!$permissionIds) {
            Database::delete('role_permissions', ['role_id' => $roleId]);
            $this->audit('role.set_permissions', ['entity_type' => 'role', 'entity_id' => $roleId, 'details' => []]);
            return ['count' => 0];
        }

        $placeholders = implode(',', array_fill(0, count($permissionIds), '?'));
        $rows = Database::all(
            "SELECT `id`,`code` FROM `permissions` WHERE `id` IN ({$placeholders})",
            $permissionIds
        );
        if (count($rows) !== count($permissionIds)) {
            throw new HttpError(422, 'One or more permissions do not exist.');
        }

        if (empty($this->user['is_super_admin'])) {
            foreach ($rows as $row) {
                if (!Rbac::can($this->user, $row['code'])) {
                    throw new HttpError(403, "You cannot grant the '{$row['code']}' permission because you do not hold it.");
                }
            }
        }

        return Database::transaction(function () use ($roleId, $permissionIds, $role) {
            Database::delete('role_permissions', ['role_id' => $roleId]);
            $stmt = Database::pdo()->prepare(
                'INSERT INTO `role_permissions` (`role_id`,`permission_id`) VALUES (?,?)'
            );
            foreach ($permissionIds as $pid) {
                $stmt->execute([$roleId, $pid]);
            }
            $this->audit('role.set_permissions', [
                'entity_type' => 'role',
                'entity_id'   => $roleId,
                'details'     => ['role' => $role['code'], 'permission_count' => count($permissionIds)],
            ]);
            return ['count' => count($permissionIds)];
        });
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    private function assertVisible(array $role): void
    {
        if ($this->scope->isGlobal()) {
            return;
        }
        if ($role['institution_id'] !== null && (int) $role['institution_id'] !== $this->scope->institutionId()) {
            throw new HttpError(403, 'That role belongs to another institution.');
        }
    }

    private function assertCanCreateLevel(string $scopeLevel): void
    {
        $allowed = match ($this->scope->type()) {
            Scope::GLOBAL      => ['global', 'institution', 'department'],
            Scope::INSTITUTION => ['institution', 'department'],
            Scope::DEPARTMENT  => ['department'],
            default            => [],
        };
        if (!in_array($scopeLevel, $allowed, true)) {
            throw new HttpError(403, "You cannot create a '{$scopeLevel}'-level role from your scope.");
        }
    }
}
