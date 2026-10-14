<?php
/**
 * Department CRUD - the middle and bottom layer of the hierarchy.
 *
 *  Institution Admin : creates departments/divisions under the institution
 *                      and assigns their Department Admins.
 *  Department Admin   : creates sub-departments/teams under its own
 *                      department and assigns roles to its members.
 *
 * Every read is filtered by Scope, every write is validated against Scope,
 * so a Department Admin can never touch a sibling department.
 */
declare(strict_types=1);

final class DepartmentController extends Controller
{
    /** Flat, scope-filtered list (used by the admin table). */
    public function index(array $filters = []): array
    {
        $params = [];
        $sql = 'SELECT r.*, i.`name` AS institution_name
                  FROM `v_department_reports` r
                  JOIN `institutions` i ON i.`id` = r.`institution_id`';

        // Map the scope onto the view's own columns: `department_id` is the
        // department's own id, so both keys point at it.
        $sql = $this->scope->applyTo($sql, 'r', [
            'institution' => 'institution_id',
            'department'  => 'department_id',
        ], $params);

        if (!empty($filters['status'])) {
            $sql .= ' AND r.`status` = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['type'])) {
            $sql .= ' AND r.`type` = ?';
            $params[] = $filters['type'];
        }
        if (!empty($filters['q'])) {
            $sql .= ' AND (r.`name` LIKE ? OR r.`code` LIKE ?)';
            $like = '%' . $filters['q'] . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $sql .= ' ORDER BY r.`institution_id`, r.`path`';
        return Database::all($sql, $params);
    }

    /** The same rows arranged as a nested tree for the UI. */
    public function tree(array $filters = []): array
    {
        $rows  = $this->index($filters);
        $byId  = [];
        foreach ($rows as $row) {
            $row['children'] = [];
            $byId[(int) $row['department_id']] = $row;
        }

        $roots = [];
        foreach ($byId as $id => $row) {
            $parentId = $row['parent_id'] !== null ? (int) $row['parent_id'] : null;
            if ($parentId !== null && isset($byId[$parentId])) {
                $byId[$parentId]['children'][] = &$byId[$id];
            } else {
                $roots[] = &$byId[$id];
            }
        }
        unset($row);

        return array_values($roots);
    }

    public function find(int $id): ?array
    {
        if (!$this->scope->canAccessDepartment($id)) {
            throw new HttpError(403, 'That department is outside your scope.');
        }
        return Database::one(
            'SELECT d.*, i.`name` AS institution_name, p.`name` AS parent_name
               FROM `departments` d
               JOIN `institutions` i ON i.`id` = d.`institution_id`
               LEFT JOIN `departments` p ON p.`id` = d.`parent_id`
              WHERE d.`id` = ?',
            [$id]
        );
    }

    /** Departments the current user may pick as a parent. */
    public function selectableParents(?int $institutionId = null): array
    {
        return $this->scope->visibleDepartments();
    }

    /**
     * Create a department / division / sub-department / team.
     *
     * The institution is taken from the caller's scope - a Department Admin
     * cannot create a department in somebody else's institution even if it
     * posts a forged institution_id.
     */
    public function store(array $data): array
    {
        $this->authorize('department.create');
        validate_required($data, ['name' => 'Department name']);

        $institutionId = $this->resolveTargetInstitution($data);
        $parentId      = $this->intParam($data, 'parent_id');
        $parent        = null;

        // A Department Admin creates sub-departments UNDER its own department,
        // so when no parent is given we default to the scope anchor.
        if ($parentId === null && $this->scope->isDepartment()) {
            $parentId = $this->scope->departmentId();
        }

        if ($parentId !== null) {
            $parent = Scope::departmentInfo($parentId);
            if (!$parent) {
                throw new HttpError(422, 'Parent department not found.', ['parent_id' => 'Unknown department.']);
            }
            if ((int) $parent['institution_id'] !== $institutionId) {
                throw new HttpError(422, 'The parent belongs to another institution.', ['parent_id' => 'Cross-institution parent.']);
            }
            // A Department Admin may only nest under its own sub-tree.
            $this->scope->assertDepartment($parentId);
        }

        $code = $this->codeFrom($data);
        if ($code === '') {
            throw new HttpError(422, 'A code is required.', ['code' => 'Code is required.']);
        }
        if (Database::one(
            'SELECT `id` FROM `departments` WHERE `institution_id` = ? AND `code` = ?',
            [$institutionId, $code]
        )) {
            throw new HttpError(409, "Department code '{$code}' already exists in this institution.", ['code' => 'Code already used.']);
        }

        $type = $this->normaliseType($data['type'] ?? null, $parent);

        return Database::transaction(function () use ($data, $institutionId, $parent, $code, $type) {
            // Insert first (the materialized path embeds the generated id),
            // then patch depth + path.
            $id = Database::insert('departments', [
                'institution_id' => $institutionId,
                'parent_id'      => $parent['id'] ?? null,
                'code'           => $code,
                'name'           => trim((string) $data['name']),
                'name_am'        => $data['name_am'] ?? null,
                'type'           => $type,
                'depth'          => 0,
                'path'           => '',
                'description'    => $data['description'] ?? null,
                'status'         => $data['status'] ?? 'active',
                'created_by'     => $this->userId(),
            ]);

            $path = Scope::computePath($parent, $id);
            Database::update('departments', ['depth' => $path['depth'], 'path' => $path['path']], ['id' => $id]);
            Scope::flushCache();

            $this->audit('department.create', [
                'entity_type'    => 'department',
                'entity_id'      => $id,
                'institution_id' => $institutionId,
                'department_id'  => $id,
                'details'        => ['code' => $code, 'name' => $data['name'], 'parent_id' => $parent['id'] ?? null],
            ]);

            return $this->find($id) ?? [];
        });
    }

    public function update(int $id, array $data): array
    {
        $existing = $this->find($id);
        if (!$existing) {
            throw new HttpError(404, 'Department not found.');
        }
        $this->authorize('department.update');

        $updates = [];
        foreach (['name', 'name_am', 'description', 'status'] as $field) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = $data[$field] === '' ? null : $data[$field];
            }
        }
        if (array_key_exists('type', $data) && $data['type'] !== null) {
            $updates['type'] = $this->normaliseType($data['type'], null);
        }
        if (isset($data['code']) && trim((string) $data['code']) !== '') {
            $code  = trim((string) $data['code']);
            $clash = Database::one(
                'SELECT `id` FROM `departments` WHERE `institution_id` = ? AND `code` = ? AND `id` <> ?',
                [(int) $existing['institution_id'], $code, $id]
            );
            if ($clash) {
                throw new HttpError(409, "Department code '{$code}' already exists.", ['code' => 'Code already used.']);
            }
            $updates['code'] = $code;
        }

        // Re-parenting (move) is a separate, carefully validated step.
        $newParentId = null;
        $moving      = false;
        if (array_key_exists('parent_id', $data)) {
            $newParentId   = $this->intParam($data, 'parent_id');
            $currentParent = $existing['parent_id'] !== null ? (int) $existing['parent_id'] : null;
            if ($newParentId !== $currentParent) {
                $this->assertValidMove($id, $newParentId, (int) $existing['institution_id']);
                $moving = true;
            }
        }

        if (!$updates && !$moving) {
            throw new HttpError(422, 'Nothing to update.');
        }

        return Database::transaction(function () use ($id, $updates, $moving, $newParentId) {
            if ($updates) {
                Database::update('departments', $updates, ['id' => $id]);
            }
            if ($moving) {
                Database::update('departments', ['parent_id' => $newParentId], ['id' => $id]);
                $this->rebuildSubtree($id);
            }
            Scope::flushCache();

            $this->audit('department.update', [
                'entity_type'   => 'department',
                'entity_id'     => $id,
                'department_id' => $id,
                'details'       => ['updates' => $updates, 'moved_to_parent' => $moving ? $newParentId : null],
            ]);

            return $this->find($id) ?? [];
        });
    }

    /**
     * Delete a department. Refuses while sub-departments exist so a whole
     * branch is never silently orphaned.
     */
    public function destroy(int $id, bool $force = false): void
    {
        $existing = $this->find($id);
        if (!$existing) {
            throw new HttpError(404, 'Department not found.');
        }
        $this->authorize('department.delete');

        $children = (int) Database::value('SELECT COUNT(*) FROM `departments` WHERE `parent_id` = ?', [$id], 0);
        if ($children > 0 && !$force) {
            throw new HttpError(409, "This department still has {$children} sub-department(s). Remove or move them first.");
        }

        Database::delete('departments', ['id' => $id]);
        Scope::flushCache();

        $this->audit('department.delete', [
            'entity_type'   => 'department',
            'entity_id'     => $id,
            'department_id' => $id,
            'details'       => ['code' => $existing['code'], 'name' => $existing['name']],
        ]);
    }

    /**
     * Assign a Department Admin (or any department-level role) to a
     * department. This is the level 2 -> level 3 delegation.
     */
    public function assignAdmin(int $departmentId, int $userId, ?string $roleCode = null): array
    {
        $this->authorize('department.assign_admin');

        $department = $this->find($departmentId);
        if (!$department) {
            throw new HttpError(404, 'Department not found.');
        }
        $user = Database::one('SELECT * FROM `users` WHERE `id` = ?', [$userId]);
        if (!$user) {
            throw new HttpError(404, 'User not found.');
        }

        $roleCode = $roleCode ?: 'department_admin';
        $role     = Database::one('SELECT * FROM `roles` WHERE `code` = ? AND `institution_id` IS NULL', [$roleCode]);
        if (!$role) {
            throw new HttpError(422, 'Unknown role.', ['role_code' => 'Role not found.']);
        }
        if ($role['scope_level'] !== 'department') {
            throw new HttpError(422, 'Only department-level roles can be assigned to a department.', ['role_code' => 'Wrong scope level.']);
        }

        return Database::transaction(function () use ($departmentId, $userId, $role, $department, $user) {
            // Keep the user's home institution / department in sync.
            Database::update('users', [
                'institution_id' => (int) $department['institution_id'],
                'department_id'  => $departmentId,
            ], ['id' => $userId]);

            Database::run(
                "DELETE FROM `user_roles` WHERE `user_id` = ? AND `role_id` = ? AND `scope_type` = 'department' AND `scope_id` = ?",
                [$userId, (int) $role['id'], $departmentId]
            );

            $assignmentId = Database::insert('user_roles', [
                'user_id'     => $userId,
                'role_id'     => (int) $role['id'],
                'scope_type'  => Scope::DEPARTMENT,
                'scope_id'    => $departmentId,
                'assigned_by' => $this->userId(),
            ]);

            $this->audit('department.assign_admin', [
                'entity_type'    => 'department',
                'entity_id'      => $departmentId,
                'institution_id' => (int) $department['institution_id'],
                'department_id'  => $departmentId,
                'details'        => ['user_id' => $userId, 'username' => $user['username'], 'role' => $role['code']],
            ]);

            return ['assignment_id' => $assignmentId, 'department' => $department, 'user' => $user, 'role' => $role];
        });
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Which institution does this new department belong to?
     * Only a global (Main Admin) caller may choose freely; everybody else
     * is pinned to their own institution regardless of what they post.
     */
    private function resolveTargetInstitution(array $data): int
    {
        if ($this->scope->isGlobal()) {
            $institutionId = $this->intParam($data, 'institution_id');
            if ($institutionId === null) {
                throw new HttpError(422, 'institution_id is required for a global admin.', ['institution_id' => 'Required.']);
            }
            if (!Database::one('SELECT `id` FROM `institutions` WHERE `id` = ?', [$institutionId])) {
                throw new HttpError(422, 'Institution not found.', ['institution_id' => 'Unknown institution.']);
            }
            return $institutionId;
        }

        $institutionId = $this->scope->institutionId();
        if ($institutionId === null) {
            throw new HttpError(403, 'Your account has no institution scope.');
        }
        return $institutionId;
    }

    private function normaliseType(?string $type, ?array $parent): string
    {
        $allowed = ['department', 'division', 'sub_department', 'team'];
        if ($type !== null && $type !== '' && in_array($type, $allowed, true)) {
            return $type;
        }
        // Sensible default derived from the nesting level.
        if ($parent === null) {
            return 'department';
        }
        return ((int) $parent['depth'] === 0) ? 'sub_department' : 'team';
    }

    /** A department may not be moved into itself or into its own sub-tree. */
    private function assertValidMove(int $id, ?int $newParentId, int $institutionId): void
    {
        if ($newParentId === null) {
            return; // promote to a root department
        }
        if ($newParentId === $id) {
            throw new HttpError(422, 'A department cannot be its own parent.', ['parent_id' => 'Invalid parent.']);
        }

        $parent = Scope::departmentInfo($newParentId);
        if (!$parent) {
            throw new HttpError(422, 'Parent department not found.', ['parent_id' => 'Unknown department.']);
        }
        if ((int) $parent['institution_id'] !== $institutionId) {
            throw new HttpError(422, 'The parent belongs to another institution.', ['parent_id' => 'Cross-institution parent.']);
        }
        $this->scope->assertDepartment($newParentId);

        $self = Scope::departmentInfo($id);
        if ($self && str_starts_with((string) $parent['path'], (string) $self['path'])) {
            throw new HttpError(422, 'A department cannot be moved inside its own sub-tree.', ['parent_id' => 'Circular move.']);
        }
    }

    /**
     * Recompute `depth` and `path` for a department and every descendant
     * after it has been re-parented. One prefix-replacement UPDATE does the
     * whole branch.
     */
    private function rebuildSubtree(int $id): void
    {
        $node = Database::one('SELECT * FROM `departments` WHERE `id` = ?', [$id]);
        if (!$node) {
            return;
        }
        $parent = $node['parent_id']
            ? Database::one('SELECT * FROM `departments` WHERE `id` = ?', [$node['parent_id']])
            : null;

        $newDepth = $parent ? ((int) $parent['depth'] + 1) : 0;
        $newPath  = ($parent ? rtrim((string) $parent['path'], '/') : '') . '/' . $id . '/';
        $oldPath  = (string) $node['path'];
        $delta    = $newDepth - (int) $node['depth'];

        Database::update('departments', ['depth' => $newDepth, 'path' => $newPath], ['id' => $id]);

        if ($oldPath !== '' && $oldPath !== $newPath) {
            Database::run(
                'UPDATE `departments`
                    SET `path` = CONCAT(?, SUBSTRING(`path`, ?)), `depth` = `depth` + ?
                  WHERE `path` LIKE ? AND `id` <> ?',
                [$newPath, strlen($oldPath) + 1, $delta, $oldPath . '%', $id]
            );
        }
    }
}
