<?php
/**
 * Reporting.
 *
 * This is where the two isolation rules become visible:
 *
 *   Institution Admin -> aggregated report over EVERY department of the
 *                        institution (report.view_aggregate).
 *   Department Admin  -> the same query, but the Scope predicate narrows it
 *                        to the own department sub-tree only, so a sibling
 *                        department's numbers are never returned.
 *
 * The SQL is identical; only the Scope predicate differs.
 */
declare(strict_types=1);

final class ReportController extends Controller
{
    /** Headline numbers for the dashboard, always scope-filtered. */
    public function overview(): array
    {
        $scope = $this->scope;

        return [
            'scope_type'    => $scope->type(),
            'scope_label'   => $scope->label(),
            'scope_name'    => $scope->displayName(),
            'institutions'  => count($scope->visibleInstitutions()),
            'departments'   => count($scope->visibleDepartments()),
            'users'         => $this->scopedCount('users'),
            'members'       => $this->scopedCount('members'),
            'students'      => $this->scopedCount('students'),
            'attendance'    => $this->scopedCount('attendance_records'),
            'library_items' => $this->scopedCount('library_items'),
            'finance'       => $this->financeTotals(),
        ];
    }

    /**
     * Aggregated, per-department report.
     *
     * Institution Admin  -> one row per department of the institution.
     * Department Admin   -> one row per department of the own sub-tree.
     * Main Admin         -> one row per department of every institution.
     */
    public function departmentAggregate(array $filters = []): array
    {
        if (!$this->can('report.view')) {
            throw new HttpError(403, 'Missing permission: report.view');
        }

        $params = [];
        $sql = 'SELECT r.`department_id`, r.`code`, r.`name`, r.`type`, r.`depth`, r.`path`,
                       r.`status`, r.`institution_id`, r.`institution_name`, r.`parent_name`,
                       (SELECT COUNT(*) FROM `members` m WHERE m.`department_id` = r.`department_id`) AS `member_count`,
                       (SELECT COUNT(*) FROM `students` s WHERE s.`department_id` = r.`department_id`) AS `student_count`,
                       (SELECT COUNT(*) FROM `attendance_records` a WHERE a.`department_id` = r.`department_id`) AS `attendance_count`,
                       (SELECT COALESCE(SUM(CASE WHEN f.`type` = \'income\'  THEN f.`amount` ELSE 0 END),0)
                          FROM `finance_entries` f WHERE f.`department_id` = r.`department_id`) AS `income_total`,
                       (SELECT COALESCE(SUM(CASE WHEN f.`type` = \'expense\' THEN f.`amount` ELSE 0 END),0)
                          FROM `finance_entries` f WHERE f.`department_id` = r.`department_id`) AS `expense_total`
                  FROM `v_department_reports` r';

        // <<< THE ISOLATION LINE >>>
        $sql = $this->scope->applyTo($sql, 'r', [
            'institution' => 'institution_id',
            'department'  => 'department_id',
        ], $params);

        if (!empty($filters['institution_id']) && $this->scope->isGlobal()) {
            $sql .= ' AND r.`institution_id` = ?';
            $params[] = (int) $filters['institution_id'];
        }

        $sql .= ' ORDER BY r.`institution_id`, r.`path`';
        $rows = Database::all($sql, $params);

        foreach ($rows as &$row) {
            $row['balance'] = (float) $row['income_total'] - (float) $row['expense_total'];
        }
        unset($row);

        return $rows;
    }

    /** Per-institution roll-up (aggregated view for the Institution Admin). */
    public function institutionAggregate(): array
    {
        if (!$this->can('report.view')) {
            throw new HttpError(403, 'Missing permission: report.view');
        }

        $params = [];
        $sql    = 'SELECT * FROM `v_institution_reports` r';

        if (!$this->scope->isGlobal()) {
            $sql = $this->scope->applyTo($sql, 'r', ['institution' => 'institution_id', 'department' => null], $params);
        }

        $sql .= ' ORDER BY r.`name`';
        return Database::all($sql, $params);
    }

    /**
     * Totals of one business module, grouped by department and restricted
     * to the current scope.
     */
    public function moduleTotals(string $table): array
    {
        $allowed = [
            'members', 'attendance_records', 'finance_entries',
            'library_items', 'property_assets', 'book_sales',
            'students', 'assessments', 'grades', 'certificates',
        ];
        if (!in_array($table, $allowed, true)) {
            throw new HttpError(422, 'Unknown module table.');
        }

        $params = [];
        $sql = "SELECT t.`department_id`, d.`name` AS `department_name`, COUNT(*) AS `total`
                  FROM `{$table}` t
                  JOIN `departments` d ON d.`id` = t.`department_id`";
        $sql = $this->scope->applyTo($sql, 't', [
            'institution' => 'institution_id',
            'department'  => 'department_id',
        ], $params);
        $sql .= ' GROUP BY t.`department_id`, d.`name` ORDER BY d.`name`';

        return Database::all($sql, $params);
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    private function scopedCount(string $table): int
    {
        $params = [];
        $sql    = "SELECT COUNT(*) FROM `{$table}` t";
        $sql    = $this->scope->applyTo($sql, 't', [
            'institution' => 'institution_id',
            'department'  => 'department_id',
        ], $params);

        return (int) Database::value($sql, $params, 0);
    }

    private function financeTotals(): array
    {
        $params = [];
        $sql = "SELECT
                  COALESCE(SUM(CASE WHEN t.`type` = 'income'  THEN t.`amount` ELSE 0 END),0) AS income,
                  COALESCE(SUM(CASE WHEN t.`type` = 'expense' THEN t.`amount` ELSE 0 END),0) AS expense
                FROM `finance_entries` t";
        $sql = $this->scope->applyTo($sql, 't', [
            'institution' => 'institution_id',
            'department'  => 'department_id',
        ], $params);

        $row = Database::one($sql, $params) ?: ['income' => 0, 'expense' => 0];
        return [
            'income'  => (float) $row['income'],
            'expense' => (float) $row['expense'],
            'balance' => (float) $row['income'] - (float) $row['expense'],
        ];
    }
}
