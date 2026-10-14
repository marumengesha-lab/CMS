<?php
/**
 * Roles and the permission matrix.
 *
 * A role is a template: scope_level says how wide an assignment of it may be.
 * Roles with institution_id = NULL are shared templates; the rest are
 * institution-specific custom roles.
 */
declare(strict_types=1);

require __DIR__ . '/../config/bootstrap.php';

$user       = Guard::requirePermission('role.view');
$controller = new RoleController($user);
$errors     = [];
$editing    = null;
$matrixRole = null;

if (is_post()) {
    Guard::requirePost();
    try {
        switch ((string) input('action', '')) {
            case 'create':
                $created = $controller->store($_POST);
                Session::flash('success', 'Role "' . $created['name'] . '" created.');
                clear_old();
                redirect('admin/roles.php?matrix=' . (int) $created['id']);

            case 'update':
                $controller->update((int) input('id'), $_POST);
                Session::flash('success', 'Role updated.');
                clear_old();
                redirect('admin/roles.php');

            case 'delete':
                $controller->destroy((int) input('id'));
                Session::flash('success', 'Role deleted.');
                redirect('admin/roles.php');

            case 'set_permissions':
                $result = $controller->setPermissions((int) input('id'), (array) ($_POST['permissions'] ?? []));
                Session::flash('success', 'Permission matrix saved (' . $result['count'] . ' permissions).');
                redirect('admin/roles.php?matrix=' . (int) input('id'));
        }
        Session::flash('error', 'Unknown action.');
        redirect('admin/roles.php');
    } catch (HttpError $e) {
        if ($e->status === 422) {
            $errors = $e->context ?: [];
            remember_old($_POST);
        } else {
            Session::flash('error', $e->getMessage());
            redirect('admin/roles.php');
        }
    }
}

$rows = $controller->index([
    'q'           => (string) input('q', ''),
    'scope_level' => (string) input('scope_level', ''),
]);

if ($editId = (int) input('edit', 0)) {
    $editing = $controller->find($editId);
}
if ($matrixId = (int) input('matrix', 0)) {
    $matrixRole = $controller->find($matrixId);
}

$scope     = $controller->scope();
$canCreate = Rbac::can($user, 'role.create');
$canUpdate = Rbac::can($user, 'role.update');
$canDelete = Rbac::can($user, 'role.delete');
$canMatrix = Rbac::can($user, 'role.assign_permission');

$catalogue    = $canMatrix || Rbac::can($user, 'permission.view') ? $controller->catalogue() : [];
$attached     = $matrixRole ? $controller->permissionIds((int) $matrixRole['id']) : [];

layout_header([
    'title'       => 'Roles',
    'subtitle'    => 'Role templates, their scope level and their permission matrix.',
    'active'      => 'roles',
    'actions'     => '',
    'breadcrumbs' => [['label' => 'Dashboard', 'href' => 'index.php'], ['label' => 'Roles']],
]);
?>

<?php if ($canCreate): ?>
<div class="card">
  <div class="card__head"><?= $editing ? 'Edit role #' . (int) $editing['id'] : 'Create a custom role' ?></div>
  <form method="post" action="<?= e(url('admin/roles.php')) ?>">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>><?php endif; ?>
    <div class="card__body">
      <div class="form-grid">
        <div class="field">
          <label>Name <span class="req">*</span></label>
          <input type="text" name="name" required value="<?= e($editing['name'] ?? old_input('name')) ?>">
          <?php if (isset($errors['name'])): ?><span class="error"><?= e($errors['name']) ?></span><?php endif; ?>
        </div>
        <div class="field">
          <label>Code</label>
          <input type="text" name="code" value="<?= e($editing['code'] ?? old_input('code')) ?>" placeholder="auto from name">
        </div>
        <div class="field">
          <label>Scope level</label>
          <select name="scope_level">
            <?php foreach (['institution', 'department'] as $level): ?>
              <?php if ($scope->isGlobal() || $level === 'department' || $scope->isInstitution()): ?>
                <option value="<?= e($level) ?>"><?= e($level) ?></option>
              <?php endif; ?>
            <?php endforeach; ?>
            <?php if ($scope->isGlobal()): ?><option value="global">global</option><?php endif; ?>
          </select>
          <span class="hint">Caps how widely this role may be granted.</span>
        </div>
        <div class="field">
          <label>Status</label>
          <select name="status">
            <option value="active">active</option>
            <option value="inactive">inactive</option>
          </select>
        </div>
        <div class="field field--full">
          <label>Description</label>
          <input type="text" name="description" value="<?= e($editing['description'] ?? old_input('description')) ?>">
        </div>
      </div>
    </div>
    <div class="card__foot">
      <button class="btn" type="submit"><?= $editing ? 'Save changes' : 'Create role' ?></button>
      <?php if ($editing): ?><a class="btn btn--ghost" href="<?= e(url('admin/roles.php')) ?>">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <div class="card__head">Roles you can manage <span class="badge badge--muted"><?= count($rows) ?></span></div>
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr><th>Role</th><th>Code</th><th>Scope level</th><th>Origin</th>
            <th class="num">Permissions</th><th class="num">Assignments</th><th></th></tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="7" class="empty">No roles available at your scope.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $row): ?>
          <tr>
            <td>
              <strong><?= e($row['name']) ?></strong>
              <?php if (!empty($row['is_system'])): ?> <?= badge('system', 'muted') ?><?php endif; ?>
              <div class="muted"><?= e($row['description'] ?? '') ?></div>
            </td>
            <td><code><?= e($row['code']) ?></code></td>
            <td><?= badge((string) $row['scope_level'], $row['scope_level'] === 'global' ? 'primary' : 'neutral') ?></td>
            <td class="muted"><?= e($row['institution_name'] ?? 'shared template') ?></td>
            <td class="num"><?= (int) $row['permission_count'] ?></td>
            <td class="num"><?= (int) $row['assignment_count'] ?></td>
            <td>
              <div class="actions-cell">
                <?php if ($canMatrix): ?>
                  <a class="btn btn--ghost btn--sm" href="<?= e(url('admin/roles.php?matrix=' . (int) $row['id'])) ?>">Permissions</a>
                <?php endif; ?>
                <?php if ($canUpdate && empty($row['is_system'])): ?>
                  <a class="btn btn--ghost btn--sm" href="<?= e(url('admin/roles.php?edit=' . (int) $row['id'])) ?>">Edit</a>
                <?php endif; ?>
                <?php if ($canDelete && empty($row['is_system'])): ?>
                  <form class="inline-form" method="post" action="<?= e(url('admin/roles.php')) ?>" data-confirm="Delete this role?">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                    <button class="btn btn--danger btn--sm" type="submit">Delete</button>
                  </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($matrixRole && $catalogue): ?>
<div class="card">
  <div class="card__head">
    Permission matrix — <?= e($matrixRole['name']) ?> <code><?= e($matrixRole['code']) ?></code>
    <span class="badge badge--muted"><?= e($matrixRole['scope_level']) ?> level</span>
    <a class="btn btn--ghost btn--sm" style="margin-left:auto" href="<?= e(url('admin/roles.php')) ?>">Close</a>
  </div>
  <form method="post" action="<?= e(url('admin/roles.php')) ?>">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="set_permissions">
    <input type="hidden" name="id" value="<?= (int) $matrixRole['id'] ?>">
    <div class="card__body">
      <div class="matrix">
        <?php foreach ($catalogue as $module => $permissions): ?>
          <div class="matrix__module">
            <h4>
              <label class="matrix__item" style="font-weight:700">
                <input type="checkbox" data-toggle-all="m-<?= e($module) ?>"> <?= e($module) ?>
              </label>
            </h4>
            <?php foreach ($permissions as $permission): ?>
              <?php $checked = in_array((int) $permission['id'], $attached, true); ?>
              <label class="matrix__item">
                <input type="checkbox" name="permissions[]" value="<?= (int) $permission['id'] ?>"
                       data-toggle-target="m-<?= e($module) ?>" <?= $checked ? 'checked' : '' ?>>
                <span>
                  <?= e($permission['name']) ?><br>
                  <code><?= e($permission['code']) ?></code>
                </span>
              </label>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <p class="help" style="margin-top:14px">
        You can only grant permissions you hold yourself — the server re-checks every
        checkbox against your own permission set.
      </p>
    </div>
    <div class="card__foot"><button class="btn" type="submit">Save matrix</button></div>
  </form>
</div>
<?php endif; ?>

<?php layout_footer(); ?>
