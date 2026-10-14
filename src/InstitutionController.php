<?php
/**
 * Institution CRUD.
 *
 * Only the Main Admin creates institutions; the Institution Admin may read
 * and update the single institution they belong to. The read path is
 * scope-filtered, so an Institution Admin can never see a sibling
 * institution.
 */
declare(strict_types=1);

final class InstitutionController extends Controller
{
    /** Institutions visible to the current scope, with roll-up numbers. */
    public function index(array $filters = []): array
    {
        $params = [];
        $sql = 'SELECT r.*, i.`email`, i.`phone`, i.`address`, i.`created_at`
                  FROM `v_institution_reports` r
                  JOIN `institutions` i ON i.`id` = r.`institution_id`';

        if (!$this->scope->isGlobal()) {
            // Institution / department scope: only the own institution.
            $sql = $this->scope->applyTo($sql, 'r', [
                'institution' => 'institution_id',
                'department'  => null,
            ], $params);
        } else {
            $sql .= ' WHERE 1 = 1';
        }

        if (!empty($filters['status'])) {
            $sql .= ' AND r.`status` = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['q'])) {
            $sql .= ' AND (r.`name` LIKE ? OR r.`code` LIKE ?)';
            $like = '%' . $filters['q'] . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $sql .= ' ORDER BY r.`name`';
        return Database::all($sql, $params);
    }

    public function find(int $id): ?array
    {
        if (!$this->scope->canAccessInstitution($id)) {
            throw new HttpError(403, 'That institution is outside your scope.');
        }
        return Database::one('SELECT * FROM `institutions` WHERE `id` = ?', [$id]);
    }

    /** Create an institution. Main Admin only. */
    public function store(array $data): array
    {
        $this->authorize('institution.create');

        validate_required($data, ['name' => 'Institution name']);

        $code = $this->codeFrom($data);
        if ($code === '') {
            throw new HttpError(422, 'A code is required.', ['code' => 'Code is required.']);
        }

        if (Database::one('SELECT `id` FROM `institutions` WHERE `code` = ?', [$code])) {
            throw new HttpError(409, "Institution code '{$code}' already exists.", ['code' => 'Code already used.']);
        }

        $parentId = $this->intParam($data, 'parent_id');
        if ($parentId !== null && !Database::one('SELECT `id` FROM `institutions` WHERE `id` = ?', [$parentId])) {
            throw new HttpError(422, 'Parent institution not found.', ['parent_id' => 'Unknown institution.']);
        }

        $id = Database::insert('institutions', [
            'code'       => $code,
            'name'       => trim((string) $data['name']),
            'name_am'    => $data['name_am'] ?? null,
            'type'       => $data['type'] ?? 'sabbath_school',
            'parent_id'  => $parentId,
            'email'      => $data['email'] ?? null,
            'phone'      => $data['phone'] ?? null,
            'address'    => $data['address'] ?? null,
            'status'     => $data['status'] ?? 'active',
            'created_by' => $this->userId(),
        ]);

        $this->audit('institution.create', [
            'entity_type'    => 'institution',
            'entity_id'      => $id,
            'institution_id' => $id,
            'details'        => ['code' => $code, 'name' => $data['name']],
        ]);

        return $this->find($id) ?? [];
    }

    public function update(int $id, array $data): array
    {
        $existing = $this->find($id);
        if (!$existing) {
            throw new HttpError(404, 'Institution not found.');
        }
        $this->authorize('institution.update');

        $updates = [];
        foreach (['name', 'name_am', 'type', 'email', 'phone', 'address', 'status'] as $field) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = $data[$field] === '' ? null : $data[$field];
            }
        }
        if (isset($data['code']) && trim((string) $data['code']) !== '') {
            $code  = trim((string) $data['code']);
            $clash = Database::one('SELECT `id` FROM `institutions` WHERE `code` = ? AND `id` <> ?', [$code, $id]);
            if ($clash) {
                throw new HttpError(409, "Institution code '{$code}' already exists.", ['code' => 'Code already used.']);
            }
            $updates['code'] = $code;
        }

        if (!$updates) {
            throw new HttpError(422, 'Nothing to update.');
        }

        Database::update('institutions', $updates, ['id' => $id]);
        $this->audit('institution.update', [
            'entity_type'    => 'institution',
            'entity_id'      => $id,
            'institution_id' => $id,
            'details'        => $updates,
        ]);

        return $this->find($id) ?? [];
    }

    public function destroy(int $id): void
    {
        $this->authorize('institution.delete');
        $existing = Database::one('SELECT * FROM `institutions` WHERE `id` = ?', [$id]);
        if (!$existing) {
            throw new HttpError(404, 'Institution not found.');
        }

        Database::delete('institutions', ['id' => $id]);
        $this->audit('institution.delete', [
            'entity_type'    => 'institution',
            'entity_id'      => $id,
            'institution_id' => $id,
            'details'        => ['code' => $existing['code'], 'name' => $existing['name']],
        ]);
    }

    /**
     * Assign (or re-assign) the Institution Admin of an institution.
     * Main Admin only - this is the level 1 -> level 2 delegation.
     */
    public function assignAdmin(int $institutionId, int $userId): array
    {
        $this->authorize('institution.assign_admin');

        $institution = Database::one('SELECT * FROM `institutions` WHERE `id` = ?', [$institutionId]);
        if (!$institution) {
            throw new HttpError(404, 'Institution not found.');
        }
        $user = Database::one('SELECT * FROM `users` WHERE `id` = ?', [$userId]);
        if (!$user) {
            throw new HttpError(404, 'User not found.');
        }

        $role = Database::one("SELECT * FROM `roles` WHERE `code` = 'institution_admin' AND `institution_id` IS NULL");
        if (!$role) {
            throw new HttpError(500, 'The institution_admin role is missing. Run the schema seed.');
        }

        return Database::transaction(function () use ($institutionId, $userId, $role, $institution, $user) {
            // Bind the user to the institution they administer.
            Database::update('users', ['institution_id' => $institutionId], ['id' => $userId]);

            // Drop a previous institution-scope assignment for this user.
            Database::run(
                "DELETE FROM `user_roles` WHERE `user_id` = ? AND `role_id` = ? AND `scope_type` = 'institution'",
                [$userId, (int) $role['id']]
            );

            $assignmentId = Database::insert('user_roles', [
                'user_id'     => $userId,
                'role_id'     => (int) $role['id'],
                'scope_type'  => Scope::INSTITUTION,
                'scope_id'    => $institutionId,
                'assigned_by' => $this->userId(),
            ]);

            $this->audit('institution.assign_admin', [
                'entity_type'    => 'institution',
                'entity_id'      => $institutionId,
                'institution_id' => $institutionId,
                'details'        => ['user_id' => $userId, 'username' => $user['username']],
            ]);

            return ['assignment_id' => $assignmentId, 'institution' => $institution, 'user' => $user];
        });
    }
}
