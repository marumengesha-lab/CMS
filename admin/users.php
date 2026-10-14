<?php
/**
 * Users and their scoped role assignments.
 *
 * The assignment panel is where delegation happens. Rbac::assignableRoles()
 * only offers roles the caller is allowed to hand out, and
 * UserController::assignRole() re-checks it server-side.
 */
declare(strict_types=1);

require __DIR__ . '/../config/bootstrap.php';

$user       = Guard::requirePermission('user.view');
$controller = new UserController($user);
$errors     = [];
$editing    = null;
$managing   = null;

if (is_post()) {
    Guard::requirePost();
    try {
        switch ((string) input('action', '')) {
            case 'create':
                $created = $controller->store($_POST);
                Session::flash('success', 'User "' . $created['username'] . '" created.');
                clear_old();
                redirect('admin/users.php?manage=' . (int) $created['id']);

            case 'update':
                $controller->update((int) input('id'), $_POST);
                Session::flash('success', 'User updated.');
                clear_old();
                redirect('admin/users.php?manage=' . (int) input('id'));

            case 'delete':
                $controller->destroy((int) input('id'));
                Session::flash('success', 'User deleted.');
                redirect('admin/users.php');

            case 'assign_role':
                $controller->assignRole(
                    (int) input('user_id'),
                    (int) input('role_id'),
                    (string) input('scope_type') ?: null,
                    (int) input('scope_id') ?: null,
                    (string) input('expires_at') ?: null
                );
                Session::flash('success', 'Role granted.');
                redirect('admin/users.php?manage=' . (int) input('user_id'));

            case 'revoke_role':
                $controller->revokeRole((int) input('assignment_id'));
                Session::flash('success', 'Role revoked.');
                redirect('admin/users.php?manage=' . (int) input('user_id'));
        }
        Session::flash('error', 'Unknown action.');
        redirect('admin/users.php');
    } catch (HttpError $e) {
        if ($e->status === 422) {
            $errors = $e->context ?: [];
            remember_old($_POST);
        } else {
            Session::flash('error', $e->getMessage());
            redirect('admin/users.php');
        }
    }
}

$rows = $controller->index([
    'q'      => (string) input('q', ''),
    'status' => (string) input('status', ''),
]);

if ($editId = (int) input('edit', 0)) {
    $editing = $controller->find($editId);
}
if ($manageId = (int) input('manage', 0)) {
    $managing = $controller->find($manageId);
}

$scope     = $controller->scope();
$canCreate = Rbac::can($user, 'user.create');
$canUpdate = Rbac::can($user, 'user.update');
$canDelete = Rbac::can($user, 'user.delete');
$canAssign = Rbac::can($user, 'user.assign_role');

// Departments the caller may place a user into.
$departments = $scope->visibleDepartments();
$institutions = $scope->isGlobal() ? $scope->visibleInstitutions() : [];

layout_header([
    'title'       => 'Users',
    'subtitle'    => 'Accounts and scoped role assignments inside your scope.',
    'active'      => 'users',
    'actions'     => '',
    'breadcrumbs' => [['label' => 'Dashboard', 'href' => 'index.php'], ['label' => 'Users']],
]);
?>

<?php if ($managing): ?>
<div class="card">
  <div class="card__head">
    Role assignments for <?= e($managing['full_name']) ?> (<?= e($managing['username']) ?>)
    <a class="btn btn--ghost btn--sm" style="margin-left:auto" href="<?= e(url('admin/users.php')) ?>">Close</a>
  </div>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Role</th><th>Scope level</th><th>Granted at</th><th>Expires</th><th></th></tr></thead>
      <tbody>
        <?php $assignments = $controller->assignments((int) $managing['id']); ?>
        <?php if (!$assignments): ?>
          <tr><td colspan="5" class="empty">No roles assigned yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($assignments as $assignment): ?>
          <tr>
            <td><strong><?= e($assignment['role_name']) ?></strong> <code><?= e($assignment['role_code']) ?></code></td>
            <td><?= badge((string) $assignment['scope_level']) ?></td>
            <td><?= e(ucfirst((string) $assignment['scope_type'])) ?>: <?= e($assignment['scope_name'] ?? '—') ?></td>
            <td class="muted"><?= e($assignment['expires_at'] ?? 'never') ?></td>
            <td>
              <?php if ($canAssign): ?>
                <form class="inline-form" method="post" action="<?= e(url('admin/users.php')) ?>" data-confirm="Revoke this role?">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="action" value="revoke_role">
                  <input type="hidden" name="user_id" value="<?= (int) $managing['id'] ?>">
                  <input type="hidden" name="assignment_id" value="<?= (int) $assignment['id'] ?>"
                  ><button class="btn btn--danger btn--sm" type="submit">Revoke</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($canAssign): ?>
    <?php $roles = $controller->availableRoles((int) $managing['id']); ?>
    <form method="post" action="<?= e(url('admin/users.php')) ?>">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="assign_role">
      <input type="hidden" name="user_id" value="<?= (int) $managing['id'] ?>">
      <div class="card__body">
        <div class="form-grid">
          <div class="field">
            <label>Role to grant</label>
            <select name="role_id" required>
              <?php foreach ($roles as $role): ?>
                <option value="<?= (int) $role['id'] ?>"><?= e($role['name']) ?> — <?= e($role['scope_level']) ?> level</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label>Grant at scope</label>
            <select name="scope_type" data-autosubmit>
              <option value="">default (from the role)</option>
              <option value="institution">institution</option>
              <option value="department">department</option>
            </select>
          </div>
          <div class="field">
            <label>Institution id</label>
            <input type="number" name="scope_id" placeholder="leave empty for default">
            <span class="hint">Required for institution scope when you administer several.</span>
          </div>
          <div class="field">
            <label>Expires at (optional)</label>
            <input type="date" name="expires_at">
          </div>
        </div>
      </div>
      <div class="card__foot"><button class="btn" type="submit">Grant role</button></div>
    </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($canCreate): ?>
<div class="card">
  <div class="card__head"><?= $editing ? 'Edit user #' . (int) $editing['id'] : 'Create a user' ?></div>
  <form method="post" action="<?= e(url('admin/users.php')) ?>">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>><?php endif; ?>
    <div class="card__body">
      <div class="form-grid">
        <div class="field">
          <label>Full name <span class="req">*</span></label>
          <input type="text" name="full_name" required value="<?= e($editing['full_name'] ?? old_input('full_name')) ?>">
          <?php if (isset($errors['full_name'])): ?><span class="error"><?= e($errors['full_name']) ?></span><?php endif; ?>
        </div>
        <div class="field">
          <label>Username <span class="req">*</span></label>
          <input type="text" name="username" required value="<?= e($editing['username'] ?? old_input('username')) ?>" <?= $editing ? 'readonly' : '' ?>>
          <?php if (isset($errors['username'])): ?><span class="error"><?= e($errors['username']) ?></span><?php endif; ?>
        </div>
        <div class="field">
          <label>E-mail</label>
          <input type="email" name="email" value="<?= e($editing['email'] ?? old_input('email')) ?>">
          <?php if (isset($errors['email'])): ?><span class="error"><?= e($errors['email']) ?></span><?php endif; ?>
        </div>
        <div class="field">
          <label>Phone</label>
          <input type="tel" name="phone" value="<?= e($editing['phone'] ?? old_input('phone')) ?>">
        </div>
        <div class="field">
          <label>Name (Amharic)</label>
          <input type="text" name="name_am" value="<?= e($editing['name_am'] ?? old_input('name_am')) ?>">
        </div>
        <div class="field">
          <label>Password <?= $editing ? '' : '<span class="req">*</span>' ?></label>
          <input type="password" name="password" <?= $editing ? '' : 'required' ?> autocomplete="new-password">
          <span class="hint"><?= $editing ? 'Leave empty to keep the current password. Minimum 8 characters.' : 'Minimum 8 characters.' ?></span>
          <?php if (isset($errors['password'])): ?><span class="error"><?= e($errors['password']) ?></span><?php endif; ?>
        </div>
        <?php if ($scope->isGlobal()): ?>
          <div class="field">
            <label>Institution</label>
            <select name="institution_id">
              <option value="">— none —</option>
              <?php foreach ($institutions as $inst): ?>
                <option value="<?= (int) $inst['id'] ?>" <?= (int) ($editing['institution_id'] ?? 0) === (int) $inst['id'] ? 'selected' : '' ?>><?= e($inst['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php endif; ?>
        <div class="field">
          <label>Department</label>
          <select name="department_id">
            <option value="">— none —</option>
            <?php foreach ($departments as $dept): ?>
              <option value="<?= (int) $dept['id'] ?>" <?= (int) ($editing['department_id'] ?? 0) === (int) $dept['id'] ? 'selected' : '' ?>>
                <?= e(str_repeat('— ', (int) $dept['depth']) . $dept['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label>Status</label>
          <select name="status">
            <?php foreach (['active', 'inactive', 'pending', 'suspended'] as $status): ?>
              <option value="<?= e($status) ?>" <?= ($editing['status'] ?? 'active') === $status ? 'selected' : '' ?>><?= e($status) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>
    <div class="card__foot">
      <button class="btn" type="submit"><?= $editing ? 'Save changes' : 'Create user' ?></button>
      <?php if ($editing): ?><a class="btn btn--ghost" href="<?= e(url('admin/users.php')) ?>">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <div class="card__head">
    Users in your scope <span class="badge badge--muted"><?= count($rows) ?></span>
    <form class="toolbar" style="margin-left:auto" method="get" action="<?= e(url('admin/users.php')) ?>">
      <div class="field"><input type="search" name="q" placeholder="Search name / username / e-mail" value="<?= e((string) input('q', '')) ?>"></div>
      <button class="btn btn--ghost btn--sm" type="submit">Search</button>
    </form>
  </div>
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr><th>Name</th><th>Username</th><th>Institution</th><th>Department</th>
            <th>Status</th><th>Last login</th><th></th></tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="7" class="empty">No users in your scope.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $row): ?>
          <tr>
            <td>
              <strong><?= e($row['full_name']) ?></strong>
              <?php if (!empty($row['is_super_admin'])): ?> <?= badge('Main Admin', 'primary') ?><?php endif; ?>
              <div class="muted"><?= e($row['email'] ?? '') ?></div>
            </td>
            <td><code><?= e($row['username']) ?></code></td>
            <td class="muted"><?= e($row['institution_name'] ?? '—') ?></td>
            <td class="muted"><?= e($row['department_name'] ?? '—') ?></td>
            <td><?= badge((string) $row['status'], status_tone((string) $row['status'])) ?></td>
            <td class="muted"><?= e($row['last_login_at'] ?? 'never') ?></td>
            <td>
              <div class="actions-cell">
                <?php if ($canAssign): ?>
                  <a class="btn btn--ghost btn--sm" href="<?= e(url('admin/users.php?manage=' . (int) $row['id'])) ?>">Roles</a>
                <?php endif; ?>
                <?php if ($canUpdate): ?>
                  <a class="btn btn--ghost btn--sm" href="<?= e(url('admin/users.php?edit=' . (int) $row['id'])) ?>">Edit</a>
                <?php endif; ?>
                <?php if ($canDelete): ?>
                  <form class="inline-form" method="post" action="<?= e(url('admin/users.php')) ?>" data-confirm="Delete this user?">
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

<?php layout_footer(); ?>
