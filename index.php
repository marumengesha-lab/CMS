<?php
/**
 * Dashboard. Every number on this page is produced by the same
 * scope-filtered queries the rest of the application uses, so what a user
 * sees here is exactly what they are allowed to see.
 */
declare(strict_types=1);

require __DIR__ . '/config/bootstrap.php';

$user     = Guard::requireAuth();
$reports  = new ReportController($user);
$overview = $reports->overview();
$scope    = $reports->scope();

$departmentRows = Rbac::can($user, 'report.view') ? $reports->departmentAggregate() : [];
$recentAudit    = Rbac::can($user, 'audit.view') ? Audit::listing($scope, [], 8) : [];

$actions = '';
if (Rbac::can($user, 'department.create')) {
    $actions .= '<a class="btn" href="' . e(url('admin/departments.php')) . '">Manage departments</a>';
}
if (Rbac::can($user, 'user.create')) {
    $actions .= ' <a class="btn btn--ghost" href="' . e(url('admin/users.php')) . '">Manage users</a>';
}

layout_header([
    'title'       => 'Dashboard',
    'subtitle'    => 'Signed in as ' . $user['full_name'] . ' — ' . ucfirst($scope->type()) . ' scope: ' . $scope->displayName(),
    'active'      => 'dashboard',
    'actions'     => $actions,
    'breadcrumbs' => [['label' => 'Dashboard']],
]);
?>

<div class="grid grid--4">
  <div class="card"><div class="stat">
    <div class="stat__label">Institutions in scope</div>
    <div class="stat__value"><?= (int) $overview['institutions'] ?></div>
    <div class="stat__hint"><?= e($scope->isGlobal() ? 'All institutions' : $scope->displayName()) ?></div>
  </div></div>
  <div class="card"><div class="stat">
    <div class="stat__label">Departments in scope</div>
    <div class="stat__value"><?= (int) $overview['departments'] ?></div>
    <div class="stat__hint">
      <?= $scope->isDepartment()
            ? 'Own department + ' . max(0, count($scope->departmentIds()) - 1) . ' sub-department(s)'
            : 'All departments of the scope' ?>
    </div>
  </div></div>
  <div class="card"><div class="stat">
    <div class="stat__label">Users in scope</div>
    <div class="stat__value"><?= (int) $overview['users'] ?></div>
    <div class="stat__hint">Accounts visible to you</div>
  </div></div>
  <div class="card"><div class="stat">
    <div class="stat__label">Members</div>
    <div class="stat__value"><?= (int) $overview['members'] ?></div>
    <div class="stat__hint">Sabbath school register</div>
  </div></div>
</div>

<div class="grid grid--3">
  <div class="card">
    <div class="card__head">Finance (scope total)</div>
    <div class="card__body">
      <table class="data">
        <tbody>
          <tr><td>Income</td><td class="num"><?= e(money($overview['finance']['income'])) ?></td></tr>
          <tr><td>Expense</td><td class="num"><?= e(money($overview['finance']['expense'])) ?></td></tr>
          <tr><td><strong>Balance</strong></td><td class="num"><strong><?= e(money($overview['finance']['balance'])) ?></strong></td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card__head">Academics &amp; library</div>
    <div class="card__body">
      <table class="data">
        <tbody>
          <tr><td>Students</td><td class="num"><?= (int) $overview['students'] ?></td></tr>
          <tr><td>Attendance records</td><td class="num"><?= (int) $overview['attendance'] ?></td></tr>
          <tr><td>Library items</td><td class="num"><?= (int) $overview['library_items'] ?></td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card__head">Your access</div>
    <div class="card__body">
      <p class="help">
        <strong>Scope:</strong> <?= e(ucfirst($scope->type())) ?> &mdash; <?= e($scope->displayName()) ?><br>
        <strong>Role(s):</strong> <?= e(implode(', ', array_map(static fn ($r) => $r['role_name'], $user['roles'] ?? []))) ?><br>
        <strong>Permissions:</strong> <?= empty($user['is_super_admin']) ? count($user['permissions'] ?? []) : 'all (Main Admin)' ?>
      </p>
      <div class="chip-list">
        <?php if ($scope->isGlobal()): ?>
          <?= badge('Sees every institution', 'primary') ?>
        <?php elseif ($scope->isInstitution()): ?>
          <?= badge('Sees every department of the institution', 'primary') ?>
        <?php else: ?>
          <?= badge('Sees only department #' . (int) $scope->departmentId() . ' and below', 'primary') ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php if ($departmentRows): ?>
<div class="card">
  <div class="card__head">
    <?= $scope->isInstitution()
          ? 'Aggregated department report (whole institution)'
          : ($scope->isGlobal() ? 'All departments (global view)' : 'My department sub-tree') ?>
    <span class="badge badge--muted"><?= count($departmentRows) ?> row(s)</span>
  </div>
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr>
          <th>Institution</th><th>Department</th><th>Type</th>
          <th class="num">Members</th><th class="num">Students</th>
          <th class="num">Income</th><th class="num">Expense</th><th class="num">Balance</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($departmentRows as $row): ?>
          <tr>
            <td class="muted"><?= e($row['institution_name']) ?></td>
            <td>
              <span class="tree-inline"><?= str_repeat('&nbsp;&nbsp;', (int) $row['depth']) ?></span>
              <?= e($row['name']) ?> <span class="muted">(<?= e($row['code']) ?>)</span>
            </td>
            <td><?= badge((string) $row['type']) ?></td>
            <td class="num"><?= (int) $row['member_count'] ?></td>
            <td class="num"><?= (int) $row['student_count'] ?></td>
            <td class="num"><?= e(money($row['income_total'])) ?></td>
            <td class="num"><?= e(money($row['expense_total'])) ?></td>
            <td class="num"><?= e(money($row['balance'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if ($recentAudit): ?>
<div class="card">
  <div class="card__head">Recent activity in your scope</div>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>When</th><th>User</th><th>Action</th><th>Entity</th><th>Scope</th></tr></thead>
      <tbody>
        <?php foreach ($recentAudit as $entry): ?>
          <tr>
            <td class="muted"><?= e($entry['created_at']) ?></td>
            <td><?= e($entry['username'] ?? '—') ?></td>
            <td><code><?= e($entry['action']) ?></code></td>
            <td><?= e($entry['entity_type'] ?? '—') ?><?= $entry['entity_id'] ? ' #' . (int) $entry['entity_id'] : '' ?></td>
            <td class="muted"><?= e($entry['scope_type']) ?><?= $entry['scope_id'] ? ' #' . (int) $entry['scope_id'] : '' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php layout_footer(); ?>
