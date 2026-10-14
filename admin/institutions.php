<?php
/**
 * Institutions - level 1 of the hierarchy.
 *
 * The Main Admin creates institutions and appoints their Institution Admin.
 * An Institution Admin only ever sees and edits their own institution.
 */
declare(strict_types=1);

require __DIR__ . '/../config/bootstrap.php';

$user       = Guard::requirePermission('institution.view');
$controller = new InstitutionController($user);
$errors     = [];
$editing    = null;

if (is_post()) {
    Guard::requirePost();
    try {
        switch ((string) input('action', '')) {
            case 'create':
                $created = $controller->store($_POST);
                Session::flash('success', 'Institution "' . $created['name'] . '" created.');
                clear_old();
                redirect('admin/institutions.php');

            case 'update':
                $controller->update((int) input('id'), $_POST);
                Session::flash('success', 'Institution updated.');
                clear_old();
                redirect('admin/institutions.php');

            case 'delete':
                $controller->destroy((int) input('id'));
                Session::flash('success', 'Institution deleted.');
                redirect('admin/institutions.php');

            case 'assign_admin':
                $result = $controller->assignAdmin((int) input('institution_id'), (int) input('user_id'));
                Session::flash('success', $result['user']['full_name'] . ' is now the Institution Admin of ' . $result['institution']['name'] . '.');
                redirect('admin/institutions.php');
        }
        Session::flash('error', 'Unknown action.');
        redirect('admin/institutions.php');
    } catch (HttpError $e) {
        if ($e->status === 422) {
            $errors = $e->context ?: [];
            remember_old($_POST);
        } else {
            Session::flash('error', $e->getMessage());
            redirect('admin/institutions.php');
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

$canCreate = Rbac::can($user, 'institution.create');
$canUpdate = Rbac::can($user, 'institution.update');
$canDelete = Rbac::can($user, 'institution.delete');
$canAssign = Rbac::can($user, 'institution.assign_admin');

$candidates = $canAssign
    ? Database::all("SELECT `id`,`username`,`full_name` FROM `users` WHERE `status` = 'active' AND `is_super_admin` = 0 ORDER BY `full_name`")
    : [];

layout_header([
    'title'       => 'Institutions',
    'subtitle'    => $canCreate
        ? 'Create institutions and appoint their Institution Admin.'
        : 'You see only the institution you belong to.',
    'active'      => 'institutions',
    'actions'     => '',
    'breadcrumbs' => [['label' => 'Dashboard', 'href' => 'index.php'], ['label' => 'Institutions']],
]);
?>

<?php if ($canCreate): ?>
<div class="card">
  <div class="card__head"><?= $editing ? 'Edit institution #' . (int) $editing['id'] : 'Create a new institution' ?></div>
  <form method="post" action="<?= e(url('admin/institutions.php')) ?>">
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
          <label>Name (Amharic)</label>
          <input type="text" name="name_am" value="<?= e($editing['name_am'] ?? old_input('name_am')) ?>">
        </div>
        <div class="field">
          <label>Code</label>
          <input type="text" name="code" value="<?= e($editing['code'] ?? old_input('code')) ?>" placeholder="auto from name">
          <span class="hint">Unique short code, e.g. BOLE-SDA</span>
          <?php if (isset($errors['code'])): ?><span class="error"><?= e($errors['code']) ?></span><?php endif; ?>
        </div>
        <div class="field">
          <label>Type</label>
          <select name="type">
            <?php foreach (['conference', 'church', 'sabbath_school', 'other'] as $type): ?>
              <option value="<?= e($type) ?>" <?= ($editing['type'] ?? '') === $type ? 'selected' : '' ?>><?= e($type) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label>E-mail</label>
          <input type="email" name="email" value="<?= e($editing['email'] ?? old_input('email')) ?>">
        </div>
        <div class="field">
          <label>Phone</label>
          <input type="tel" name="phone" value="<?= e($editing['phone'] ?? old_input('phone')) ?>">
        </div>
        <div class="field">
          <label>Address</label>
          <input type="text" name="address" value="<?= e($editing['address'] ?? old_input('address')) ?>">
        </div>
        <div class="field">
          <label>Status</label>
          <select name="status">
            <option value="active" <?= ($editing['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>active</option>
            <option value="inactive" <?= ($editing['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>inactive</option>
          </select>
        </div>
      </div>
    </div>
    <div class="card__foot">
      <button class="btn" type="submit"><?= $editing ? 'Save changes' : 'Create institution' ?></button>
      <?php if ($editing): ?><a class="btn btn--ghost" href="<?= e(url('admin/institutions.php')) ?>">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>
<?php endif; ?>

<?php if ($canAssign && $candidates): ?>
<div class="card">
  <div class="card__head">Appoint an Institution Admin</div>
  <form method="post" action="<?= e(url('admin/institutions.php')) ?>">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="assign_admin">
    <div class="card__body">
      <div class="form-grid">
        <div class="field">
          <label>Institution</label>
          <select name="institution_id" required>
            <?php foreach ($controller->index() as $inst): ?>
              <option value="<?= (int) $inst['institution_id'] ?>"><?= e($inst['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label>User</label>
          <select name="user_id" required>
            <?php foreach ($candidates as $candidate): ?>
              <option value="<?= (int) $candidate['id'] ?>"><?= e($candidate['full_name']) ?> (<?= e($candidate['username']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <p class="help" style="margin-top:12px">
        Binds the user to the institution and grants the <code>institution_admin</code> role at
        institution scope. Only the Main Admin can perform this action.
      </p>
    </div>
    <div class="card__foot"><button class="btn" type="submit">Appoint Institution Admin</button></div>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <div class="card__head">Institutions <span class="badge badge--muted"><?= count($rows) ?></span></div>
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr><th>Name</th><th>Code</th><th>Type</th><th>Status</th>
            <th class="num">Departments</th><th class="num">Users</th><th>Contact</th><th></th></tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="8" class="empty">No institutions visible to your scope.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $row): ?>
          <tr>
            <td>
              <strong><?= e($row['name']) ?></strong>
              <?php if (!empty($row['name_am'])): ?><div class="muted"><?= e($row['name_am']) ?></div><?php endif; ?>
            </td>
            <td><code><?= e($row['code']) ?></code></td>
            <td><?= badge((string) $row['type']) ?></td>
            <td><?= badge((string) $row['status'], status_tone((string) $row['status'])) ?></td>
            <td class="num"><?= (int) $row['active_departments'] ?></td>
            <td class="num"><?= (int) $row['active_users'] ?></td>
            <td class="muted"><?= e($row['email'] ?? '-') ?><br><?= e($row['phone'] ?? '') ?></td>
            <td>
              <div class="actions-cell">
                <?php if ($canUpdate): ?>
                  <a class="btn btn--ghost btn--sm" href="<?= e(url('admin/institutions.php?edit=' . (int) $row['institution_id'])) ?>">Edit</a>
                <?php endif; ?>
                <?php if ($canDelete): ?>
                  <form class="inline-form" method="post" action="<?= e(url('admin/institutions.php')) ?>"
                        data-confirm="Delete this institution and everything under it?">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $row['institution_id'] ?>">
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
