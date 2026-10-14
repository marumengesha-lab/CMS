<?php
/**
 * Generic, scope-aware CRUD engine for every resource declared in
 * config/resources.php.
 *
 * One implementation serves all ~16 module screens, which guarantees that
 * the isolation rules are applied identically everywhere:
 *
 *   list   -> Scope predicate appended to the WHERE clause
 *   read   -> Scope::assertDepartment() on the row before returning it
 *   create -> institution/department forced from the scope
 *   update -> row fetched through the scoped read path first
 *   delete -> same, plus the delete permission
 */
declare(strict_types=1);

final class ResourceController extends Controller
{
    /** Resolve the permission code required for an action on a resource. */
    public function permissionFor(array $def, string $action): string
    {
        if (!empty($def['manage_suffix'])) {
            return $def['permission'] . '.' . $def['manage_suffix'];
        }
        return $def['permission'] . '.' . $action;
    }

    /**
     * Paginated, scope-filtered, searchable listing.
     *
     * @return array{rows:array,total:int,page:int,per_page:int,pages:int}
     */
    public function index(string $key, array $filters = [], int $page = 1, ?int $perPage = null): array
    {
        $def = ResourceRegistry::get($key);
        $this->authorize($this->permissionFor($def, 'view'));

        $table   = $def['table'];
        $perPage = max(1, min($perPage ?: (int) $def['page_size'], 200));
        $page    = max(1, $page);

        [$whereSql, $params] = $this->buildWhere($def, $filters);

        $total = (int) Database::value("SELECT COUNT(*) FROM `{$table}` t{$whereSql}", $params, 0);

        $order = $this->safeColumn($def['order'] ?? 'id');
        $dir   = strtoupper((string) ($def['order_dir'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
        $offset = ($page - 1) * $perPage;

        $rows = Database::all(
            "SELECT t.* FROM `{$table}` t{$whereSql} ORDER BY t.`{$order}` {$dir} LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return [
            'rows'     => $rows,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => max(1, (int) ceil($total / $perPage)),
            'resource' => $def,
        ];
    }

    /** Fetch one row, scope-checked. */
    public function find(string $key, int $id): array
    {
        $def = ResourceRegistry::get($key);
        $this->authorize($this->permissionFor($def, 'view'));

        $table = $def['table'];
        $row   = Database::one("SELECT t.* FROM `{$table}` t WHERE t.`id` = ?", [$id]);
        if (!$row) {
            throw new HttpError(404, $def['singular'] . ' not found.');
        }
        $this->assertRowVisible($def, $row);
        return $row;
    }

    /** Create a row. institution_id / department_id come from the scope. */
    public function store(string $key, array $data): array
    {
        $def = ResourceRegistry::get($key);
        $this->authorize($this->permissionFor($def, 'create'));

        $required = [];
        foreach ($def['fields'] as $name => $field) {
            if (!empty($field['required'])) {
                $required[$name] = $field['label'] ?? $name;
            }
        }
        validate_required($data, $required);

        $values = $this->filterFields($def, $data);

        // <<< scope anchoring >>>
        [$institutionId, $departmentId] = $this->resolveScopeTargets($def, $data);
        $values['institution_id'] = $institutionId;
        $values['department_id']  = $departmentId;

        // Housekeeping columns that exist on every module table.
        if ($this->tableHasColumn($def['table'], 'created_by')) {
            $values['created_by'] = $this->userId();
        }
        if ($this->tableHasColumn($def['table'], 'recorded_by')) {
            $values['recorded_by'] = $this->userId();
        }
        if ($this->tableHasColumn($def['table'], 'graded_by')) {
            $values['graded_by'] = $this->userId();
        }
        if ($this->tableHasColumn($def['table'], 'issued_by')) {
            $values['issued_by'] = $this->userId();
        }
        if ($def['table'] === 'book_sales') {
            $values['total_amount'] = $values['total_amount']
                ?? ((float) ($values['quantity'] ?? 1) * (float) ($values['unit_price'] ?? 0));
        }

        $id = Database::insert($def['table'], $values);

        $this->audit($def['key'] . '.create', [
            'entity_type'    => $def['key'],
            'entity_id'      => $id,
            'institution_id' => $institutionId,
            'department_id'  => $departmentId,
            'details'        => array_keys($values),
        ]);

        return $this->find($key, $id);
    }

    public function update(string $key, int $id, array $data): array
    {
        $def = ResourceRegistry::get($key);
        $this->authorize($this->permissionFor($def, 'update'));

        $existing = $this->find($key, $id); // scoped existence check
        $values   = $this->filterFields($def, $data);

        if (array_key_exists('department_id', $data)) {
            $departmentId = $this->intParam($data, 'department_id');
            if ($departmentId !== null) {
                $this->scope->assertDepartment($departmentId);
                $values['department_id'] = $departmentId;
                $info = Scope::departmentInfo($departmentId);
                if ($info) {
                    $values['institution_id'] = (int) $info['institution_id'];
                }
            }
        }
        if ($def['table'] === 'book_sales') {
            $values['total_amount'] = ((float) ($values['quantity'] ?? $existing['quantity'])
                * (float) ($values['unit_price'] ?? $existing['unit_price']));
        }

        if (!$values) {
            throw new HttpError(422, 'Nothing to update.');
        }

        Database::update($def['table'], $values, ['id' => $id]);
        $this->audit($def['key'] . '.update', [
            'entity_type' => $def['key'],
            'entity_id'   => $id,
            'details'     => array_keys($values),
        ]);

        return $this->find($key, $id);
    }

    public function destroy(string $key, int $id): void
    {
        $def = ResourceRegistry::get($key);
        $this->authorize($this->permissionFor($def, 'delete'));

        $existing = $this->find($key, $id); // scoped existence check

        Database::delete($def['table'], ['id' => $id]);
        $this->audit($def['key'] . '.delete', [
            'entity_type' => $def['key'],
            'entity_id'   => $id,
            'details'     => ['deleted' => true],
        ]);
    }

    /** Row count for a resource inside the current scope (dashboard tiles). */
    public function count(string $key): int
    {
        $def = ResourceRegistry::get($key);
        $this->authorize($this->permissionFor($def, 'view'));

        [$whereSql, $params] = $this->buildWhere($def, []);
        return (int) Database::value("SELECT COUNT(*) FROM `{$def['table']}` t{$whereSql}", $params, 0);
    }

    /** Options for a `ref` (foreign key) field, scope-filtered. */
    public function referenceOptions(string $key, string $field): array
    {
        $def   = ResourceRegistry::get($key);
        $field = $def['fields'][$field] ?? null;
        if (!$field || ($field['type'] ?? null) !== 'ref') {
            throw new HttpError(422, 'That field is not a reference.');
        }

        $table = $this->safeIdentifier($field['ref']['table']);
        $label = $this->safeIdentifier($field['ref']['label']);

        $params = [];
        $sql    = "SELECT t.`id`, t.`{$label}` AS `label` FROM `{$table}` t";

        $map = [];
        if ($this->tableHasColumn($table, 'institution_id')) {
            $map['institution'] = 'institution_id';
        }
        if ($this->tableHasColumn($table, 'department_id')) {
            $map['department'] = 'department_id';
        }
        if ($map) {
            $sql = $this->scope->applyTo($sql, 't', $map, $params);
        }
        $sql .= " ORDER BY t.`{$label}` LIMIT 500";

        return Database::all($sql, $params);
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /** @return array{0:string,1:array} [where-sql, bindings] */
    private function buildWhere(array $def, array $filters): array
    {
        $clauses = [];
        $params  = [];

        [$clause, $bind] = $this->scope->where('t', $def['scope_map']);
        $clauses[] = $clause;
        $params    = array_merge($params, $bind);

        if (!empty($filters['q']) && !empty($def['search'])) {
            $parts = [];
            foreach ($def['search'] as $column) {
                $parts[]  = 't.`' . $this->safeIdentifier($column) . '` LIKE ?';
                $params[] = '%' . $filters['q'] . '%';
            }
            $clauses[] = '(' . implode(' OR ', $parts) . ')';
        }

        if (!empty($filters['department_id']) && $this->tableHasColumn($def['table'], 'department_id')) {
            $clauses[] = 't.`department_id` = ?';
            $params[]  = (int) $filters['department_id'];
        }

        return [' WHERE ' . implode(' AND ', $clauses), $params];
    }

    /** Row-level check for a fetched row. */
    private function assertRowVisible(array $def, array $row): void
    {
        $map = $def['scope_map'];

        if (isset($map['department']) && array_key_exists($map['department'], $row)) {
            $deptId = $row[$map['department']] !== null ? (int) $row[$map['department']] : null;
            $this->scope->assertDepartment($deptId);
            return;
        }
        if (isset($map['institution']) && array_key_exists($map['institution'], $row)) {
            $instId = $row[$map['institution']] !== null ? (int) $row[$map['institution']] : null;
            $this->scope->assertInstitution($instId);
        }
    }

    /**
     * Decide which institution + department a new row belongs to.
     * Never trusts a forged institution_id from a non-global caller.
     *
     * @return array{0:int,1:int}
     */
    private function resolveScopeTargets(array $def, array $data): array
    {
        $institutionId = $this->scope->isGlobal()
            ? $this->intParam($data, 'institution_id')
            : $this->scope->institutionId();

        $departmentId = $this->intParam($data, 'department_id');

        if ($departmentId !== null) {
            $this->scope->assertDepartment($departmentId);
            $info          = Scope::departmentInfo($departmentId);
            $institutionId = (int) ($info['institution_id'] ?? $institutionId);
        } elseif ($this->scope->isDepartment()) {
            // A Department Admin writes into its own department by default.
            $departmentId = $this->scope->departmentId();
        }

        if ($departmentId === null) {
            throw new HttpError(422, 'department_id is required.', ['department_id' => 'Required.']);
        }
        if ($institutionId === null) {
            throw new HttpError(422, 'institution_id is required.', ['institution_id' => 'Required.']);
        }

        return [$institutionId, $departmentId];
    }

    /** Keep only the declared fields and cast them to the right type. */
    private function filterFields(array $def, array $data): array
    {
        $values = [];

        foreach ($def['fields'] as $name => $field) {
            if (!array_key_exists($name, $data)) {
                continue;
            }
            $value = $data[$name];
            if (is_string($value)) {
                $value = trim($value);
            }
            if ($value === '' || $value === null) {
                $values[$name] = null;
                continue;
            }

            $values[$name] = match ($field['type'] ?? 'text') {
                'number'   => (int) $value,
                'decimal'  => (float) $value,
                'checkbox' => $value ? 1 : 0,
                'ref'      => (int) $value,
                default    => is_scalar($value) ? (string) $value : null,
            };
        }

        return $values;
    }

    private function safeColumn(string $name): string
    {
        return $this->safeIdentifier($name);
    }

    private function safeIdentifier(string $name): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            throw new HttpError(500, "Unsafe SQL identifier: {$name}");
        }
        return $name;
    }

    /** @var array<string,bool> */
    private static array $columnCache = [];

    private function tableHasColumn(string $table, string $column): bool
    {
        $table = $this->safeIdentifier($table);
        $key   = $table . '.' . $column;
        if (!array_key_exists($key, self::$columnCache)) {
            $rows = Database::all(
                'SELECT `COLUMN_NAME` FROM `information_schema`.`COLUMNS`
                  WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = ? AND `COLUMN_NAME` = ?',
                [$table, $column]
            );
            self::$columnCache[$key] = $rows !== [];
        }
        return self::$columnCache[$key];
    }
}
