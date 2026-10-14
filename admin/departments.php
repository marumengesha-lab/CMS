<?php
/**
 * Departments - levels 2 and 3 of the hierarchy.
 *
 * Institution Admin: creates the institution's departments/divisions and
 * appoints their Department Admins.
 * Department Admin: creates sub-departments/teams under its own department
 * and appoints roles inside its sub-tree.
 *
 * The tree is rendered from the scope-filtered rows, so nobody ever sees a
 * department outside their scope.
 */
declare(strict_types=1);

require __DIR__ . '/../config/bootstrap.php';

$user       = Guard::requirePermission('department.view');
$controller = new DepartmentController($user);
$errors     = [];
$editing    = null;

if (is_post()) {
    Guard::requirePost();
    try {
        switch ((string) input('action', '')) {
            case 'create':
                $created = $controller->store($_POST);
                Session::flash('success', 'Department "' . $created['name'] . '" created.');
                clear_old();
                redirect('admin/departments.php');

            case 'update':
                $controller->update((int) input('id'), $_POST);
                Session::flash('success', 'Department updated.');
                clear_old();
                redirect('admin/departments.php');

            case 'delete':
                $controller->destroy((int) input('id'), (bool) input('force'));
                Session::flash('success', 'Department deleted.');
                redirect('admin/departments.php');

            case 'assign_admin':
                $result = $controller->assignAdmin(
                    (int) input('department_id'),
                    (int) input('user_id'),
                    (string) input('role_code', 'department_admin')
                );
                Session::flash('success', $result['user']['full_name'] . ' assigned as ' . $result['role']['name'] . ' of ' . $result['department']['name'] . '.');
                redirect('admin/departments.php');
        }
        Session::flash('error', 'Unknown action.');
        redirect('admin/departments.php');
    } catch (HttpError $e) {
        if ($e->status === 422) {
            $errors = $e->context ?: [];
            remember_old($_POST);
        } else {
            Session::flash('error', $e->getMessage());
            redirect('admin/departments.php');
        }
    }
}

$rows = $controller->index([
    'q'      => (string) input('q', ''),
    'status' => (string) input('status', ''),
    'type'   => (string) input('type', ''),
]);

if ($editId = (int) input('edit', 0)) {
    $editing = $controller->find($editId);
}

$scope     = $controller->scope();
$canCreate = Rbac::can($user, 'department.create');
$canUpdate = Rbac::can($user, 'department.update');
$canDelete = Rbac::can($user, 'department.delete');
$canAssign = Rbac::can($user, 'department.assign_admin');

// Parent options: everything the caller can see, excluding itself and its descendants.
$parentOptions = $controller->selectableParents();
if ($editing) {
    $descendants = Scope::departmentSubtreeIds((int) $editing['id']);
    $parentOptions = array_values(array_filter(
        $parentOptions,
        static fn ($d) => !in_array((int) $d['id'], $descendants, true)
    ));
}

$institutions = $scope->isGlobal() ? $scope->visibleInstitutions() : [];

// Candidates for a department-level appointment, limited to the scope.
$candidates = [];
if ($canAssign) {
    [$clause, $bind] = $scope->where('u', ['institution' => 'institution_id', 'department' => 'department_id']);
    $candidates = Database::all(
        "SELECT u.`id`,`username`,`full_name`, d.`name` AS department_name
           FROM `users` u LEFT JOIN `departments` d ON d.`id` = u.`department_id`
          WHERE u.`status` = 'active' AND u.`is_super_admin` = 0 AND ({$clause})
          ORDER BY u.`full_name`",
        $bind
    );
}

$assignableRoles = $canAssign ? Rbac::assignableRoles($scope) : [];
$assignableRoles = array_values(array_filter(
    $assignableRoles,
    static fn ($r) => $r['scope_level'] === 'department'
));

layout_header([
    'title'       => 'Departments',
    'subtitle'    => $scope->isGlobal()
        ? 'Every department of every institution.'
        : ($scope->isInstitution()
            ? 'All departments of ' . $scope->displayName() . ' — aggregated view.'
            : 'Your department and its sub-departments only.'),
    'active'      => 'departments',
    'actions'     => '',
    'breadcrumbs' => [['label' => 'Dashboard', 'href' => 'index.php'], ['label' => 'Departments']],
]);
?>

<?php if ($canCreate): ?>
<div class="card">
  <div class="card__head"><?= $editing ? 'Edit department #' . (int) $editing['id'] : 'Create a department / division / team' ?></div>
  <form method="post" action="<?= e(url('admin/departments.php')) ?>">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>><?php endif; ?>
    <div class="card__body">
      <div class="form-grid">
        <?php if ($scope->isGlobal()): ?>
          <div class="field">
            <label>Institution <span class="req">*</span></label>
            <select name="institution_id" required>
              <?php foreach ($institutions as $inst): ?>
                <option value="<?= (int) $inst['id'] ?>" <?= (int) ($editing['institution_id'] ?? 0) === (int) $inst['id'] ? 'selected' : '' ?>><?= e($inst['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php endif; ?>
        <div class="field">
          <label>Parent department</label>
          <select name="parent_id">
            <option value="">— none (top level) —</option>
            <?php foreach ($parentOptions as $option): ?>
              <option value="<?= (int) $option['id'] ?>" <?= (int) ($editing['parent_id'] ?? 0) === (int) $option['id'] ? 'selected' : '' ?>>
                <?= e(str_repeat('— ', (int) $option['depth']) . $option['name']) ?>
                <?= $scope->isGlobal() ? ' · ' . e($option['institution_name']) : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
          <span class="hint">Leave empty to create a top-level department. A Department Admin defaults to its own department.</span>
        </div>
        <div class="field">
          <label>Name <span class="req">*</span></label>
          <input type="text" name="name" required value="<?= e($editing['name'] ?? old_input('name')) ?>">
          <?php if (isset($errors['name'])): ?><span class="error"><?= e($errors['name']) ?></span><?php endif; ?>
        </div>
        <div class="field">
          <label>Name (Amharic)</label>
          <input type="text" name="name_am" value="<?= e($editing['name_am'] ?? old_input('name_am')) ?>">
        </div>
        <div class="field">
          <label>Code</label>
          <input type="text" name="code" value="<?= e($editing['code'] ?? old_input('code')) ?>" placeholder="auto from name">
          <?php if (isset($errors['code'])): ?><span class="error"><?= e($errors['code']) ?></span><?php endif; ?>
        </div>
        <div class="field">
          <label>Type</label>
          <select name="type">
            <?php foreach (['department', 'division', 'sub_department', 'team'] as $type): ?>
              <option value="<?= e($type) ?>" <?= ($editing['type'] ?? '') === $type ? 'selected' : '' ?>><?= e($type) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label>Status</label>
          <select name="status">
            <option value="active" <?= ($editing['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>active</option>
            <option value="inactive" <?= ($editing['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>inactive</option>
          </select>
        </div>
        <div class="field field--full">
          <label>Description</label>
          <input type="text" name="description" value="<?= e($editing['description'] ?? old_input('description')) ?>">
        </div>
      </div>
    </div>
    <div class="card__foot">
      <button class="btn" type="submit"><?= $editing ? 'Save changes' : 'Create department' ?></button>
      <?php if ($editing): ?><a class="btn btn--ghost" href="<?= e(url('admin/departments.php')) ?>">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>
<?php endif; ?>

<?php if ($canAssign && $candidates && $assignableRoles): ?>
<div class="card">
  <div class="card__head">Appoint a Department Admin / department role</div>
  <form method="post" action="<?= e(url('admin/departments.php')) ?>">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="assign_admin">
    <div class="card__body">
      <div class="form-grid">
        <div class="field">
          <label>Department</label>
          <select name="department_id" required>
            <?php foreach ($rows as $row): ?>
              <option value="<?= (int) $row['department_id'] ?>">
                <?= e(str_repeat('— ', (int) $row['depth']) . $row['name']) ?>
                <?= $scope->isGlobal() ? ' · ' . e($row['institution_name']) : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label>User</label>
          <select name="user_id" required>
            <?php foreach ($candidates as $candidate): ?>
              <option value="<?= (int) $candidate['id'] ?>">
                <?= e($candidate['full_name']) ?> (<?= e($candidate['username']) ?>)
                <?= $candidate['department_name'] ? ' · ' . e($candidate['department_name']) : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label>Role</label>
          <select name="role_code" required>
            <?php foreach ($assignableRoles as $role): ?>
              <option value="<?= e($role['code']) ?>"><?= e($role['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <p class="help" style="margin-top:12px">
        The role is granted at <strong>department scope</strong>, so it only ever covers that
        department and its sub-departments.
      </p>
    </div>
    <div class="card__foot"><button class="btn" type="submit">Assign role</button></div>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <div class="card__head">
    Departments in your scope <span class="badge badge--muted"><?= count($rows) ?></span>
  </div>
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr><th>Department</th><th>Code</th><th>Type</th><th>Parent</th><th>Path</th><th>Status</th>
            <th class="num">Members</th><th></th></tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="8" class="empty">No departments in your scope yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $row): ?>
          <tr>
            <td>
              <span class="tree-inline"><?= str_repeat('&nbsp;&nbsp;&nbsp;', (int) $row['depth']) ?></span>
              <strong><?= e($row['name']) ?></strong>
              <?php if ($scope->isGlobal()): ?>
                <div class="muted"><?= e($row['institution_name']) ?></div>
              <?php endif; ?>
            </td>
            <td><code><?= e($row['code']) ?></code></td>
            <td><?= badge((string) $row['type']) ?></td>
            <td class="muted"><?= e($row['parent_name'] ?? '—') ?></td>
            <td class="muted"><code><?= e($row['path']) ?></code></td>
            <td><?= badge((string) $row['status'], status_tone((string) $row['status'])) ?></td>
            <td class="num"><?= (int) $row['active_members'] ?></td>
            <td>
              <div class="actions-cell">
                <?php if ($canUpdate): ?>
                  <a class="btn btn--ghost btn--sm" href="<?= e(url('admin/departments.php?edit=' . (int) $row['department_id'])) ?>">Edit</a>
                <?php endif; ?>
                <?php if ($canDelete): ?>
                  <form class="inline-form" method="post" action="<?= e(url('admin/departments.php')) ?>"
                        data-confirm="Delete this department?">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $row['department_id'] ?>">
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

<p class="help">
  <strong>How isolation works here:</strong> an Institution Admin sees every row of
  <code>departments WHERE institution_id = &lt;own&gt;</code>, while a Department Admin sees
  <code>departments WHERE institution_id = &lt;own&gt; AND id IN (&lt;own sub-tree&gt;)</code>.
  The sub-tree is resolved from the materialized <code>path</code> column, so a department can never
  see a sibling branch.
</p>

<?php layout_footer(); ?>
