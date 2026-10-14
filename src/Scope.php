<?php
/**
 * Scope - the data-isolation engine of the multi-level RBAC.
 *
 * RBAC answers "what may this user DO" (permission codes).
 * Scope answers "on WHICH ROWS may they do it" (institution / department).
 *
 * The three organisational levels map to three scopes:
 *
 *   global      -> Main Admin        : no row restriction at all
 *   institution -> Institution Admin : every row of ONE institution,
 *                                      including all of its departments
 *   department  -> Department Admin  : only the rows of ONE department
 *                                      and its sub-departments (sub-tree)
 *
 * Controllers never write hand-made WHERE clauses for isolation; they call
 * Scope::where() / Scope::applyTo() and the correct predicate is produced.
 */
declare(strict_types=1);

final class Scope
{
    public const GLOBAL      = 'global';
    public const INSTITUTION = 'institution';
    public const DEPARTMENT  = 'department';
    public const NONE        = 'none';

    /** @var array<int,array<int,int>> per-request cache of department sub-trees */
    private static array $subtreeCache = [];

    private function __construct(private array $data)
    {
    }

    /**
     * Resolve the effective scope of a user.
     *
     * @param array|null $user Row from Auth::user() including its `roles` list.
     */
    public static function forUser(?array $user): self
    {
        if (!$user) {
            return new self(self::empty(self::NONE));
        }

        if (!empty($user['is_super_admin'])) {
            return new self(self::empty(self::GLOBAL) + [
                'role_codes' => self::extractRoleCodes($user),
                'label'      => 'Global (Main Admin)',
            ]);
        }

        $roles = $user['roles'] ?? [];
        $codes = self::extractRoleCodes($user);

        // Broadest granted scope wins.
        foreach ($roles as $r) {
            if (($r['scope_type'] ?? null) === self::GLOBAL) {
                return new self(self::empty(self::GLOBAL) + [
                    'role_codes' => $codes,
                    'label'      => 'Global',
                ]);
            }
        }

        foreach ($roles as $r) {
            if (($r['scope_type'] ?? null) === self::INSTITUTION && !empty($r['scope_id'])) {
                $institutionId = (int) $r['scope_id'];
                return new self([
                    'type'           => self::INSTITUTION,
                    'institution_id' => $institutionId,
                    'department_id'  => null,
                    'department_ids' => [],
                    'role_codes'     => $codes,
                    'label'          => 'Institution #' . $institutionId,
                ]);
            }
        }

        foreach ($roles as $r) {
            if (($r['scope_type'] ?? null) === self::DEPARTMENT && !empty($r['scope_id'])) {
                $departmentId = (int) $r['scope_id'];
                $info         = self::departmentInfo($departmentId);
                return new self([
                    'type'           => self::DEPARTMENT,
                    'institution_id' => $info['institution_id'] ?? null,
                    'department_id'  => $departmentId,
                    'department_ids' => self::departmentSubtreeIds($departmentId),
                    'role_codes'     => $codes,
                    'label'          => 'Department #' . $departmentId,
                ]);
            }
        }

        return new self(self::empty(self::NONE) + ['role_codes' => $codes]);
    }

    private static function empty(string $type): array
    {
        return [
            'type'           => $type,
            'institution_id' => null,
            'department_id'  => null,
            'department_ids' => [],
            'role_codes'     => [],
            'label'          => $type === self::NONE ? 'No scope' : $type,
        ];
    }

    private static function extractRoleCodes(array $user): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn ($r) => (string) ($r['role_code'] ?? $r['code'] ?? ''),
            $user['roles'] ?? []
        ))));
    }

    // -----------------------------------------------------------------
    // Accessors
    // -----------------------------------------------------------------
    public function toArray(): array
    {
        return $this->data;
    }

    public function type(): string
    {
        return $this->data['type'];
    }

    public function isGlobal(): bool
    {
        return $this->data['type'] === self::GLOBAL;
    }

    public function isInstitution(): bool
    {
        return $this->data['type'] === self::INSTITUTION;
    }

    public function isDepartment(): bool
    {
        return $this->data['type'] === self::DEPARTMENT;
    }

    public function isNone(): bool
    {
        return $this->data['type'] === self::NONE;
    }

    public function institutionId(): ?int
    {
        return $this->data['institution_id'];
    }

    /** The anchor department (only for department scope). */
    public function departmentId(): ?int
    {
        return $this->data['department_id'];
    }

    /**
     * The anchor department plus every sub-department below it.
     *
     * Recomputed (from the cached tree lookup) on each call rather than
     * returning the snapshot taken when the scope was built, so a
     * sub-department created earlier in the same request is immediately
     * inside the scope. Scope::flushCache() invalidates the underlying cache.
     *
     * @return int[]
     */
    public function departmentIds(): array
    {
        if ($this->data['type'] !== self::DEPARTMENT || !$this->data['department_id']) {
            return $this->data['department_ids'];
        }
        return self::departmentSubtreeIds((int) $this->data['department_id']);
    }

    public function roleCodes(): array
    {
        return $this->data['role_codes'];
    }

    public function label(): string
    {
        return (string) $this->data['label'];
    }

    /** Human readable name of the scope target (institution / department). */
    public function displayName(): string
    {
        if ($this->isGlobal()) {
            return 'All institutions';
        }
        if ($this->isInstitution() && $this->institutionId()) {
            return (string) Database::value(
                'SELECT name FROM institutions WHERE id = ?',
                [$this->institutionId()],
                'Institution'
            );
        }
        if ($this->isDepartment() && $this->departmentId()) {
            return (string) Database::value(
                'SELECT name FROM departments WHERE id = ?',
                [$this->departmentId()],
                'Department'
            );
        }
        return 'No scope';
    }

    // -----------------------------------------------------------------
    // The isolation predicate
    // -----------------------------------------------------------------

    /**
     * Build the SQL predicate that isolates rows for this scope.
     *
     * @param string $alias table alias, e.g. 'd' (may be '')
     * @param array  $map   which column carries the institution / department,
     *                      e.g. ['institution' => 'institution_id',
     *                            'department'  => 'department_id'].
     *                      Pass ['department' => 'id'] when querying the
     *                      departments table itself.
     * @return array{0:string,1:array} [sql, bindings]
     */
    public function where(string $alias = '', array $map = []): array
    {
        $map    = $map ?: ['institution' => 'institution_id', 'department' => 'department_id'];
        $prefix = $alias !== '' ? $alias . '.' : '';

        switch ($this->type()) {
            case self::GLOBAL:
                // Main Admin: no restriction whatsoever.
                return ['1 = 1', []];

            case self::NONE:
                return ['1 = 0', []];

            case self::INSTITUTION:
                // Institution Admin: every row of the institution.
                if (isset($map['institution'])) {
                    return ["{$prefix}{$map['institution']} = ?", [$this->institutionId()]];
                }
                if (isset($map['department'])) {
                    return [
                        "{$prefix}{$map['department']} IN (SELECT `id` FROM `departments` WHERE `institution_id` = ?)",
                        [$this->institutionId()],
                    ];
                }
                return ['1 = 0', []];

            case self::DEPARTMENT:
            default:
                // Department Admin: own department + every sub-department.
                $clauses = [];
                $params  = [];

                if (isset($map['institution']) && $this->institutionId()) {
                    $clauses[] = "{$prefix}{$map['institution']} = ?";
                    $params[]  = $this->institutionId();
                }

                if (isset($map['department'])) {
                    $ids = $this->departmentIds();
                    if (!$ids) {
                        return ['1 = 0', []];
                    }
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $clauses[]    = "{$prefix}{$map['department']} IN ({$placeholders})";
                    $params       = array_merge($params, $ids);
                }

                return $clauses ? [implode(' AND ', $clauses), $params] : ['1 = 0', []];
        }
    }

    /**
     * Append the isolation predicate to an existing query.
     *
     * @param array $params   bindings collected so far (appended in place)
     * @param bool  $hasWhere set true when $sql already contains a WHERE
     */
    public function applyTo(
        string $sql,
        string $alias = '',
        array $map = [],
        array &$params = [],
        bool $hasWhere = false
    ): string {
        [$clause, $bind] = $this->where($alias, $map);
        $params = array_merge($params, $bind);
        return $sql . ($hasWhere ? ' AND ' : ' WHERE ') . '(' . $clause . ')';
    }

    // -----------------------------------------------------------------
    // Target checks (used before write operations)
    // -----------------------------------------------------------------

    public function canAccessInstitution(?int $institutionId): bool
    {
        if ($this->isGlobal()) {
            return true;
        }
        if ($institutionId === null) {
            return false;
        }
        return $this->institutionId() !== null && $this->institutionId() === $institutionId;
    }

    public function canAccessDepartment(?int $departmentId): bool
    {
        if ($this->isGlobal()) {
            return true;
        }
        if ($departmentId === null) {
            return false;
        }
        if ($this->isInstitution()) {
            $info = self::departmentInfo($departmentId);
            return $info !== null && (int) $info['institution_id'] === $this->institutionId();
        }
        return in_array($departmentId, $this->departmentIds(), true);
    }

    /** Throws unless the current scope covers the given department. */
    public function assertDepartment(?int $departmentId): void
    {
        if (!$this->canAccessDepartment($departmentId)) {
            throw new HttpError(403, 'That department is outside your scope.');
        }
    }

    public function assertInstitution(?int $institutionId): void
    {
        if (!$this->canAccessInstitution($institutionId)) {
            throw new HttpError(403, 'That institution is outside your scope.');
        }
    }

    // -----------------------------------------------------------------
    // Department tree helpers (materialized-path based)
    // -----------------------------------------------------------------

    /** @var array<int,array|null> */
    private static array $infoCache = [];

    /** Drop the memoized tree lookups (call after mutating departments). */
    public static function flushCache(): void
    {
        self::$infoCache    = [];
        self::$subtreeCache = [];
    }

    public static function departmentInfo(int $departmentId): ?array
    {
        if (!array_key_exists($departmentId, self::$infoCache)) {
            self::$infoCache[$departmentId] = Database::one(
                'SELECT `id`,`institution_id`,`parent_id`,`name`,`path`,`depth` FROM `departments` WHERE `id` = ?',
                [$departmentId]
            );
        }
        return self::$infoCache[$departmentId];
    }

    /**
     * The department itself plus every descendant, resolved from the
     * materialized path ('/1/5/' -> children are '/1/5/%').
     *
     * @return int[]
     */
    public static function departmentSubtreeIds(int $departmentId): array
    {
        if (isset(self::$subtreeCache[$departmentId])) {
            return self::$subtreeCache[$departmentId];
        }

        $info = self::departmentInfo($departmentId);
        if (!$info) {
            return self::$subtreeCache[$departmentId] = [];
        }

        $rows = Database::all(
            'SELECT `id` FROM `departments` WHERE `institution_id` = ? AND `path` LIKE ?',
            [(int) $info['institution_id'], $info['path'] . '%']
        );

        $ids = array_map(static fn ($r) => (int) $r['id'], $rows);
        if (!$ids) {
            $ids = [$departmentId];
        }

        return self::$subtreeCache[$departmentId] = $ids;
    }

    /** Chain from the root department down to the given one. */
    public static function departmentAncestors(int $departmentId): array
    {
        $info = self::departmentInfo($departmentId);
        if (!$info) {
            return [];
        }
        $ids = array_values(array_filter(array_map('intval', explode('/', trim($info['path'], '/')))));
        if (!$ids) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = Database::all(
            "SELECT `id`,`name`,`depth` FROM `departments` WHERE `id` IN ({$placeholders}) ORDER BY `depth`",
            $ids
        );
        return $rows;
    }

    /**
     * Compute depth + materialized path for a department that is about to
     * be inserted. Called after the id is known (path embeds the id).
     *
     * @return array{depth:int,path:string}
     */
    public static function computePath(?array $parent, int $newId): array
    {
        if ($parent) {
            return [
                'depth' => ((int) $parent['depth']) + 1,
                'path'  => rtrim((string) $parent['path'], '/') . '/' . $newId . '/',
            ];
        }
        return ['depth' => 0, 'path' => '/' . $newId . '/'];
    }

    /** Departments visible to this scope, as a flat list ordered by path. */
    public function visibleDepartments(): array
    {
        $params = [];
        $sql = 'SELECT d.`id`, d.`institution_id`, d.`parent_id`, d.`code`, d.`name`, d.`type`,
                       d.`depth`, d.`path`, d.`status`, i.`name` AS institution_name
                  FROM `departments` d
                  JOIN `institutions` i ON i.`id` = d.`institution_id`';
        $sql = $this->applyTo($sql, 'd', ['institution' => 'institution_id', 'department' => 'id'], $params);
        $sql .= ' ORDER BY d.`institution_id`, d.`path`';
        return Database::all($sql, $params);
    }

    /** Institutions visible to this scope. */
    public function visibleInstitutions(): array
    {
        $params = [];
        $sql    = 'SELECT i.* FROM `institutions` i';
        if (!$this->isGlobal()) {
            $sql = $this->applyTo($sql, 'i', ['institution' => 'id', 'department' => null], $params);
        }
        $sql .= ' ORDER BY i.`name`';
        return Database::all($sql, $params);
    }
}
