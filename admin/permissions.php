<?php
/**
 * Read-only view of the permission catalogue (grouped by module).
 * Permissions are code-defined and seeded; they are not editable at runtime.
 */
declare(strict_types=1);

require __DIR__ . '/../config/bootstrap.php';

$user       = Guard::requirePermission('permission.view');
$controller = new PermissionController($user);

$rows    = $controller->index(['module' => (string) input('module', ''), 'q' => (string) input('q', '')]);
$modules = $controller->modules();

layout_header([
    'title'       => 'Permissions',
    'subtitle'    => 'The atomic capability catalogue that roles are built from.',
    'active'      => 'permissions',
    'actions'     => '',
    'breadcrumbs' => [['label' => 'Dashboard', 'href' => 'index.php'], ['label' => 'Permissions']],
]);
?>

<div class="card">
  <div class="card__head">
    Catalogue <span class="badge badge--muted"><?= count($rows) ?> of <?= count($rows) ?></span>
    <form class="toolbar" style="margin-left:auto" method="get" action="<?= e(url('admin/permissions.php')) ?>">
      <div class="field">
        <select name="module" data-autosubmit>
          <option value="">All modules</option>
          <?php foreach ($modules as $module): ?>
            <option value="<?= e($module['module']) ?>" <?= input('module') === $module['module'] ? 'selected' : '' ?>>
              <?= e($module['module']) ?> (<?= (int) $module['total'] ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field"><input type="search" name="q" placeholder="Search code or name" value="<?= e((string) input('q', '')) ?>"></div>
      <button class="btn btn--ghost btn--sm" type="submit">Filter</button>
    </form>
  </div>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Code</th><th>Name</th><th>Module</th><th>Description</th><th class="num">Roles using it</th></tr></thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="5" class="empty">No permissions match the filter.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $row): ?>
          <tr>
            <td><code><?= e($row['code']) ?></code></td>
            <td><?= e($row['name']) ?></td>
            <td><?= badge((string) $row['module']) ?></td>
            <td class="muted"><?= e($row['description'] ?? '') ?></td>
            <td class="num"><?= (int) $row['role_count'] ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php layout_footer(); ?>
