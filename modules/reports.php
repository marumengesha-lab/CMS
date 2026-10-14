<?php
/**
 * Reports - the clearest demonstration of the two isolation rules.
 *
 *   Institution Admin: sees EVERY department of its institution aggregated
 *                      into one report (report.view_aggregate).
 *   Department Admin : runs the exact same query, but the Scope predicate
 *                      narrows it to its own department sub-tree, so a
 *                      sibling department's numbers are never returned.
 *
 * Compare the "Rows returned" figure while signed in as bole_admin and then
 * as ss_admin: 3 departments vs 2 departments.
 */
declare(strict_types=1);

require __DIR__ . '/../config/bootstrap.php';

$user = Guard::requirePermission('report.view');
$reports = new ReportController($user);
$scope   = $reports->scope();

$table    = (string) input('table', 'members');
$overview = $reports->overview();
$byDept   = $reports->departmentAggregate();
$byInst   = $reports->institutionAggregate();

$moduleTables = [
    'members'            => 'Members',
    'attendance_records' => 'Attendance',
    'finance_entries'    => 'Finance',
    'library_items'      => 'Library',
    'property_assets'    => 'Property',
    'book_sales'         => 'Book sales',
    'students'           => 'Students',
    'assessments'        => 'Assessments',
    'grades'             => 'Grades',
    'certificates'       => 'Certificates',
];
$totals = $reports->moduleTotals($table);

layout_header([
    'title'       => 'Reports',
    'subtitle'    => 'Scope: ' . ucfirst($scope->type()) . ' — ' . $scope->displayName(),
    'active'      => 'reports',
    'actions'     => '',
    'breadcrumbs' => [['label' => 'Dashboard', 'href' => 'index.php'], ['label' => 'Reports']],
]);
?>

<div class="grid grid--4">
  <div class="card"><div class="stat">
    <div class="stat__label">Scope type</div>
    <div class="stat__value" style="font-size:20px"><?= e(ucfirst($scope->type())) ?></div>
    <div class="stat__hint"><?= e($scope->displayName()) ?></div>
  </div></div>
  <div class="card"><div class="stat">
    <div class="stat__label">Departments returned</div>
    <div class="stat__value"><?= count($byDept) ?></div>
    <div class="stat__hint">
      <?= $scope->isInstitution()
            ? 'Aggregated across the whole institution'
            : 'Own sub-tree only (' . implode(', ', $scope->departmentIds()) . ')' ?>
    </div>
  </div></div>
  <div class="card"><div class="stat">
    <div class="stat__label">Members in scope</div>
    <div class="stat__value"><?= (int) $overview['members'] ?></div>
    <div class="stat__hint">counted with the same predicate</div>
  </div></div>
  <div class="card"><div class="stat">
    <div class="stat__label">Balance in scope</div>
    <div class="stat__value" style="font-size:22px"><?= e(money($overview['finance']['balance'])) ?></div>
    <div class="stat__hint">income − expense</div>
  </div></div>
</div>

<div class="card">
  <div class="card__head">Per-institution roll-up</div>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Institution</th><th>Type</th><th class="num">Active departments</th><th class="num">Active users</th><th class="num">Role assignments</th></tr></thead>
      <tbody>
        <?php foreach ($byInst as $row): ?>
          <tr>
            <td><strong><?= e($row['name']) ?></strong> <code><?= e($row['code']) ?></code></td>
            <td><?= badge((string) $row['type']) ?></td>
            <td class="num"><?= (int) $row['active_departments'] ?></td>
            <td class="num"><?= (int) $row['active_users'] ?></td>
            <td class="num"><?= (int) $row['role_assignments'] ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="card__head">Per-department aggregation</div>
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr><th>Department</th><th>Type</th><th class="num">Members</th><th class="num">Students</th>
            <th class="num">Attendance</th><th class="num">Income</th><th class="num">Expense</th><th class="num">Balance</th></tr>
      </thead>
      <tbody>
        <?php if (!$byDept): ?>
          <tr><td colspan="8" class="empty">Nothing in scope.</td></tr>
        <?php endif; ?>
        <?php foreach ($byDept as $row): ?>
          <tr>
            <td>
              <span class="tree-inline"><?= str_repeat('&nbsp;&nbsp;', (int) $row['depth']) ?></span>
              <strong><?= e($row['name']) ?></strong>
              <?php if ($scope->isGlobal()): ?><div class="muted"><?= e($row['institution_name']) ?></div><?php endif; ?>
            </td>
            <td><?= badge((string) $row['type']) ?></td>
            <td class="num"><?= (int) $row['member_count'] ?></td>
            <td class="num"><?= (int) $row['student_count'] ?></td>
            <td class="num"><?= (int) $row['attendance_count'] ?></td>
            <td class="num"><?= e(money($row['income_total'])) ?></td>
            <td class="num"><?= e(money($row['expense_total'])) ?></td>
            <td class="num"><?= e(money($row['balance'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="card__head">
    Module totals by department
    <form class="toolbar" style="margin-left:auto" method="get" action="<?= e(url('modules/reports.php')) ?>">
      <div class="field">
        <select name="table" data-autosubmit>
          <?php foreach ($moduleTables as $key => $label): ?>
            <option value="<?= e($key) ?>" <?= $table === $key ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn btn--ghost btn--sm" type="submit">Apply</button>
    </form>
  </div>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Department</th><th class="num">Rows</th></tr></thead>
      <tbody>
        <?php if (!$totals): ?>
          <tr><td colspan="2" class="empty">No rows in scope for this module.</td></tr>
        <?php endif; ?>
        <?php foreach ($totals as $row): ?>
          <tr><td><?= e($row['department_name']) ?></td><td class="num"><?= (int) $row['total'] ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<p class="help">
  <strong>Try it:</strong> sign in as <code>bole_admin</code> (Institution Admin) and this page returns the
  institution's 3 departments. Sign in as <code>ss_admin</code> (Department Admin) and the identical query
  returns only its own 2-department sub-tree — <code>youth_admin</code>'s rows never appear.
</p>

<?php layout_footer(); ?>
