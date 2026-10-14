<?php
/**
 * Audit trail, always filtered by the caller's scope: a Department Admin sees
 * the activity of its own sub-tree, an Institution Admin the whole
 * institution, the Main Admin everything.
 */
declare(strict_types=1);

require __DIR__ . '/../config/bootstrap.php';

$user = Guard::requirePermission('audit.view');
$scope = Auth::scope();

$filters = [
    'action'  => (string) input('action', ''),
    'user_id' => (int) input('user_id', 0),
    'from'    => (string) input('from', ''),
    'to'      => (string) input('to', ''),
];

$rows = Audit::listing($scope, array_filter($filters), (int) input('limit', 200));

layout_header([
    'title'       => 'Audit trail',
    'subtitle'    => 'Who did what, where — restricted to ' . $scope->displayName() . '.',
    'active'      => 'audit',
    'actions'     => '',
    'breadcrumbs' => [['label' => 'Dashboard', 'href' => 'index.php'], ['label' => 'Audit trail']],
]);
?>

<div class="card">
  <div class="card__head">
    Entries <span class="badge badge--muted"><?= count($rows) ?></span>
    <form class="toolbar" style="margin-left:auto" method="get" action="<?= e(url('admin/audit.php')) ?>">
      <div class="field"><input type="text" name="action" placeholder="action, e.g. department.create" value="<?= e($filters['action']) ?>"></div>
      <div class="field"><input type="date" name="from" value="<?= e($filters['from']) ?>"></div>
      <div class="field"><input type="date" name="to" value="<?= e($filters['to']) ?>"></div>
      <button class="btn btn--ghost btn--sm" type="submit">Filter</button>
    </form>
  </div>
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr><th>When</th><th>User</th><th>Action</th><th>Entity</th>
            <th>Institution</th><th>Department</th><th>Scope</th><th>Details</th><th>IP</th></tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="9" class="empty">No audit entries in your scope yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $row): ?>
          <tr>
            <td class="muted"><?= e($row['created_at']) ?></td>
            <td><?= e($row['username'] ?? '—') ?></td>
            <td><code><?= e($row['action']) ?></code></td>
            <td class="muted"><?= e($row['entity_type'] ?? '—') ?><?= $row['entity_id'] ? ' #' . (int) $row['entity_id'] : '' ?></td>
            <td class="muted"><?= e($row['institution_name'] ?? '—') ?></td>
            <td class="muted"><?= e($row['department_name'] ?? '—') ?></td>
            <td><?= badge((string) $row['scope_type']) ?></td>
            <td class="muted"><code><?= e(mb_strimwidth((string) ($row['details'] ?? ''), 0, 70, '…')) ?></code></td>
            <td class="muted"><?= e($row['ip_address'] ?? '') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php layout_footer(); ?>
