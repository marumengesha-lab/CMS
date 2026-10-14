<?php
/**
 * User CRUD + scoped role assignment.
 *
 * Role assignment is where privilege escalation is prevented: a caller may
 * never grant a role whose scope is wider than the caller's own scope.
 */
declare(strict_types=1);

final class UserController extends Controller
{
    public function index(array $filters = []): array
    {
        $params = [];
        $sql = 'SELECT u.`id`, u.`username`, u.`email`, u.`full_name`, u.`name_am`, u.`phone`,
                       u.`is_super_admin`, u.`status`, u.`last_login_at`, u.`created_at`,
                       u.`institution_id`, u.`department_id`,
                       i.`name` AS institution_name, d.`name` AS department_name
                  FROM `users` u
                  LEFT JOIN `institutions` i ON i.`id` = u.`institution_id`
                  LEFT JOIN `departments`  d ON d.`id` = u.`department_id`';

        $sql = $this->scope->applyTo($sql, 'u', [
            'institution' => 'institution_id',
            'department'  => 'department_id',
        ], $params);

        if (!empty($filters['status'])) {
            $sql .= ' AND u.`status` = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['q'])) {
            $sql .= ' AND (u.`full_name` LIKE ? OR u.`username` LIKE ? OR u.`email` LIKE ?)';
            $like = '%' . $filters['q'] . '%';
            array_push($params, $like, $like, $like);
        }

        $sql .= ' ORDER BY u.`full_name`';
        return Database::all($sql, $params);
    }

    public function find(int $id): ?array
    {
        $user = Database::one(
            'SELECT u.*, i.`name` AS institution_name, d.`name` AS department_name
               FROM `users` u
               LEFT JOIN `institutions` i ON i.`id` = u.`institution_id`
               LEFT JOIN `departments`  d ON d.`id` = u.`department_id`
              WHERE u.`id` = ?',
            [$id]
        );
        if (!$user) {
            throw new HttpError(404, 'User not found.');
        }
        $this->assertVisible($user);
        $user['roles'] = Auth::rolesFor($id);
        return $user;
    }

    public function store(array $data): array
    {
        $this->authorize('user.create');
        validate_required($data, ['username' => 'Username', 'full_name' => 'Full name', 'password' => 'Password']);

        $username = trim((string) $data['username']);
        if (Database::one('SELECT `id` FROM `users` WHERE `username` = ?', [$username])) {
            throw new HttpError(409, 'That username is already taken.', ['username' => 'Already taken.']);
        }
        $email = trim((string) ($data['email'] ?? ''));
        if ($email !== '' && Database::one('SELECT `id` FROM `users` WHERE `email` = ?', [$email])) {
            throw new HttpError(409, 'That e-mail is already registered.', ['email' => 'Already registered.']);
        }
        if (strlen((string) $data['password']) < 8) {
            throw new HttpError(422, 'The password must be at least 8 characters.', ['password' => 'Too short.']);
        }

        // Where does the new user live? Callers below global cannot place a
        // user outside their own institution.
        $institutionId = $this->scope->isGlobal()
            ? $this->intParam($data, 'institution_id')
            : $this->scope->institutionId();

        $departmentId = $this->intParam($data, 'department_id');
        if ($departmentId !== null) {
            $this->scope->assertDepartment($departmentId);
            $info = Scope::departmentInfo($departmentId);
            $institutionId = (int) ($info['institution_id'] ?? $institutionId);
        }

        $id = Database::insert('users', [
            'institution_id' => $institutionId,
            'department_id'  => $departmentId,
            'username'       => $username,
            'email'          => $email !== '' ? $email : null,
            'password_hash'  => Auth::hash((string) $data['password']),
            'full_name'      => trim((string) $data['full_name']),
            'name_am'        => $data['name_am'] ?? null,
            'phone'          => $data['phone'] ?? null,
            'is_super_admin' => 0,
            'status'         => $data['status'] ?? 'active',
            'must_change_password' => !empty($data['must_change_password']) ? 1 : 0,
            'created_by'     => $this->userId(),
        ]);

        $this->audit('user.create', [
            'entity_type'   => 'user',
            'entity_id'     => $id,
            'department_id' => $departmentId,
            'details'       => ['username' => $username],
        ]);

        return $this->find($id) ?? [];
    }

    public function update(int $id, array $data): array
    {
        $existing = $this->find($id);
        $this->authorize('user.update');

        $updates = [];
        foreach (['full_name', 'name_am', 'phone', 'status'] as $field) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = $data[$field] === '' ? null : $data[$field];
            }
        }
        if (array_key_exists('email', $data)) {
            $email = trim((string) $data['email']);
            if ($email !== '') {
                $clash = Database::one('SELECT `id` FROM `users` WHERE `email` = ? AND `id` <> ?', [$email, $id]);
                if ($clash) {
                    throw new HttpError(409, 'That e-mail is already registered.', ['email' => 'Already registered.']);
                }
            }
            $updates['email'] = $email !== '' ? $email : null;
        }
        if (!empty($data['password'])) {
            if (strlen((string) $data['password']) < 8) {
                throw new HttpError(422, 'The password must be at least 8 characters.', ['password' => 'Too short.']);
            }
            $updates['password_hash'] = Auth::hash((string) $data['password']);
        }
        if (array_key_exists('department_id', $data)) {
            $departmentId = $this->intParam($data, 'department_id');
            if ($departmentId !== null) {
                $this->scope->assertDepartment($departmentId);
            }
            $updates['department_id'] = $departmentId;
        }

        if (!$updates) {
            throw new HttpError(422, 'Nothing to update.');
        }

        Database::update('users', $updates, ['id' => $id]);
        $this->audit('user.update', [
            'entity_type' => 'user',
            'entity_id'   => $id,
            'details'     => array_keys($updates),
        ]);

        return $this->find($id) ?? [];
    }

    public function destroy(int $id): void
    {
        $existing = $this->find($id);
        $this->authorize('user.delete');

        if ((int) $id === (int) $this->userId()) {
            throw new HttpError(409, 'You cannot delete your own account.');
        }
        if (!empty($existing['is_super_admin']) && empty($this->user['is_super_admin'])) {
            throw new HttpError(403, 'Only a Main Admin can remove another Main Admin.');
        }

        Database::delete('users', ['id' => $id]);
        $this->audit('user.delete', [
            'entity_type' => 'user',
            'entity_id'   => $id,
            'details'     => ['username' => $existing['username']],
        ]);
    }

    // -----------------------------------------------------------------
    // Role assignment (the delegation step)
    // -----------------------------------------------------------------

    /** All role assignments of a user, with their scope. */
    public function assignments(int $userId): array
    {
        $this->find($userId); // scope check + existence
        return Database::all(
            "SELECT ur.`id`, ur.`scope_type`, ur.`scope_id`, ur.`created_at`, ur.`expires_at`,
                    r.`id` AS `role_id`, r.`code` AS `role_code`, r.`name` AS `role_name`, r.`scope_level`,
                    CASE ur.`scope_type`
                      WHEN 'department'  THEN (SELECT `name` FROM `departments`  WHERE `id` = ur.`scope_id`)
                      WHEN 'institution' THEN (SELECT `name` FROM `institutions` WHERE `id` = ur.`scope_id`)
                      ELSE 'Global' END AS `scope_name`
               FROM `user_roles` ur
               JOIN `roles` r ON r.`id` = ur.`role_id`
              WHERE ur.`user_id` = ?
              ORDER BY ur.`scope_type`, r.`name`",
            [$userId]
        );
    }

    /** Roles the current caller is allowed to grant to this user. */
    public function availableRoles(int $userId): array
    {
        $target = $this->find($userId);
        $instId = $target['institution_id'] !== null ? (int) $target['institution_id'] : null;
        return Rbac::assignableRoles($this->scope, $instId);
    }

    /**
     * Grant a role at a scope.
     *
     * Escalation guard: the role's scope_level must be within the caller's
     * scope, and the target scope object must be inside the caller's scope.
     */
    public function assignRole(int $userId, int $roleId, ?string $scopeType = null, ?int $scopeId = null, ?string $expiresAt = null): array
    {
        $this->authorize('user.assign_role');
        $target = $this->find($userId);

        $role = Database::one('SELECT * FROM `roles` WHERE `id` = ?', [$roleId]);
        if (!$role) {
            throw new HttpError(404, 'Role not found.');
        }
        if ($role['status'] !== 'active') {
            throw new HttpError(422, 'That role is inactive.', ['role_id' => 'Inactive role.']);
        }

        // The scope level of the role dictates the scope type it is granted at.
        $scopeType = $scopeType ?: Rbac::scopeTypeForRole($role);

        // A caller may never grant a scope wider than its own.
        $this->assertCanGrant($role);

        // Resolve the concrete scope target.
        $scopeId = match ($scopeType) {
            Scope::GLOBAL      => null,
            Scope::INSTITUTION => $scopeId ?? ($this->scope->isGlobal()
                                    ? $target['institution_id']
                                    : $this->scope->institutionId()),
            default            => $scopeId ?? $target['department_id'],
        };

        if ($scopeType === Scope::INSTITUTION) {
            $this->scope->assertInstitution($scopeId !== null ? (int) $scopeId : null);
        } elseif ($scopeType === Scope::DEPARTMENT) {
            $this->scope->assertDepartment($scopeId !== null ? (int) $scopeId : null);
        }

        return Database::transaction(function () use ($userId, $role, $scopeType, $scopeId, $target, $expiresAt) {
            $exists = Database::one(
                'SELECT `id` FROM `user_roles`
                  WHERE `user_id` = ? AND `role_id` = ? AND `scope_type` = ?
                    AND IFNULL(`scope_id`,0) = IFNULL(?,0)',
                [$userId, (int) $role['id'], $scopeType, $scopeId]
            );
            if ($exists) {
                throw new HttpError(409, 'That role is already granted at this scope.');
            }

            $assignmentId = Database::insert('user_roles', [
                'user_id'     => $userId,
                'role_id'     => (int) $role['id'],
                'scope_type'  => $scopeType,
                'scope_id'    => $scopeId,
                'assigned_by' => $this->userId(),
                'expires_at'  => $expiresAt ?: null,
            ]);

            // Keep the user's home institution/department aligned with the grant.
            $sync = [];
            if ($scopeType === Scope::INSTITUTION && $scopeId) {
                $sync['institution_id'] = (int) $scopeId;
            }
            if ($scopeType === Scope::DEPARTMENT && $scopeId) {
                $info = Scope::departmentInfo((int) $scopeId);
                $sync['department_id']  = (int) $scopeId;
                $sync['institution_id'] = (int) ($info['institution_id'] ?? $target['institution_id']);
            }
            if ($sync) {
                Database::update('users', $sync, ['id' => $userId]);
            }

            $this->audit('user.assign_role', [
                'entity_type' => 'user',
                'entity_id'   => $userId,
                'details'     => ['role' => $role['code'], 'scope_type' => $scopeType, 'scope_id' => $scopeId],
            ]);

            return ['assignment_id' => $assignmentId];
        });
    }

    public function revokeRole(int $assignmentId): void
    {
        $this->authorize('user.assign_role');

        $row = Database::one(
            'SELECT ur.*, r.`code` AS role_code, r.`scope_level`
               FROM `user_roles` ur JOIN `roles` r ON r.`id` = ur.`role_id`
              WHERE ur.`id` = ?',
            [$assignmentId]
        );
        if (!$row) {
            throw new HttpError(404, 'Assignment not found.');
        }
        $this->assertCanGrant(['code' => $row['role_code'], 'scope_level' => $row['scope_level']]);

        if ($row['scope_type'] === Scope::DEPARTMENT) {
            $this->scope->assertDepartment((int) $row['scope_id']);
        } elseif ($row['scope_type'] === Scope::INSTITUTION) {
            $this->scope->assertInstitution((int) $row['scope_id']);
        }

        Database::delete('user_roles', ['id' => $assignmentId]);
        $this->audit('user.revoke_role', [
            'entity_type' => 'user',
            'entity_id'   => (int) $row['user_id'],
            'details'     => ['role' => $row['role_code'], 'scope_type' => $row['scope_type'], 'scope_id' => $row['scope_id']],
        ]);
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /** Row-level visibility check for a single user record. */
    private function assertVisible(array $user): void
    {
        if ($this->scope->isGlobal()) {
            return;
        }
        if ($this->scope->isInstitution()) {
            if ((int) ($user['institution_id'] ?? 0) !== $this->scope->institutionId()) {
                throw new HttpError(403, 'That user belongs to another institution.');
            }
            return;
        }
        $deptId = $user['department_id'] !== null ? (int) $user['department_id'] : null;
        if ($deptId === null || !in_array($deptId, $this->scope->departmentIds(), true)) {
            throw new HttpError(403, 'That user is outside your department.');
        }
    }

    /**
     * Guard against privilege escalation.
     *
     * Policy (mirrors the organisational rules):
     *   - the `main_admin` role is never grantable from the UI
     *   - appointing an `institution_admin` is a Main-Admin-only action
     *   - Main Admin        may grant global / institution / department roles
     *   - Institution Admin may grant institution and department roles
     *   - Department Admin  may grant department roles only
     */
    private function assertCanGrant(array $role): void
    {
        if (($role['code'] ?? '') === 'main_admin') {
            throw new HttpError(403, 'The Main Admin role cannot be granted through this screen.');
        }
        if (($role['code'] ?? '') === 'institution_admin' && !$this->scope->isGlobal()) {
            throw new HttpError(403, 'Only the Main Admin can appoint an Institution Admin.');
        }

        $allowed = match ($this->scope->type()) {
            Scope::GLOBAL      => ['global', 'institution', 'department'],
            Scope::INSTITUTION => ['institution', 'department'],
            Scope::DEPARTMENT  => ['department'],
            default            => [],
        };
        if (!in_array($role['scope_level'], $allowed, true)) {
            throw new HttpError(403, "You cannot grant a '{$role['scope_level']}'-level role from your scope.");
        }
    }
}
