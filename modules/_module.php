<?php
/**
 * Shared engine behind every business / academic module screen.
 *
 * A page only sets $RESOURCE_KEY (a key from config/resources.php) and
 * includes this file. Everything below - list, search, pagination, create,
 * update, delete - is generated from that declaration and every query goes
 * through ResourceController, which applies the Scope isolation predicate.
 *
 * @var string $RESOURCE_KEY
 */
declare(strict_types=1);

require __DIR__ . '/../config/bootstrap.php';

if (empty($RESOURCE_KEY)) {
    throw new HttpError(500, 'modules/_module.php requires $RESOURCE_KEY.');
}

$user       = Guard::requireAuth();
$resources  = new ResourceController($user);
$definition = ResourceRegistry::get($RESOURCE_KEY);
$errors     = [];
$editing    = null;

// Which actions may this user perform on this resource?
$canView   = Rbac::can($user, ResourceRegistry::permissionFor($definition, 'view'));
$canCreate = Rbac::can($user, ResourceRegistry::permissionFor($definition, 'create'));
$canUpdate = Rbac::can($user, ResourceRegistry::permissionFor($definition, 'update'));
$canDelete = Rbac::can($user, ResourceRegistry::permissionFor($definition, 'delete'));

if (!$canView) {
    throw new HttpError(403, 'You do not have permission to open ' . $definition['plural'] . '.');
}

$self = url(ResourceRegistry::pageUrl($RESOURCE_KEY));

if (is_post()) {
    Guard::requirePost();
    try {
        switch ((string) input('action', '')) {
            case 'create':
                $created = $resources->store($RESOURCE_KEY, $_POST);
                Session::flash('success', $definition['singular'] . ' created.');
                clear_old();
                redirect(ResourceRegistry::pageUrl($RESOURCE_KEY));

            case 'update':
                $resources->update($RESOURCE_KEY, (int) input('id'), $_POST);
                Session::flash('success', $definition['singular'] . ' updated.');
                clear_old();
                redirect(ResourceRegistry::pageUrl($RESOURCE_KEY));

            case 'delete':
                $resources->destroy($RESOURCE_KEY, (int) input('id'));
                Session::flash('success', $definition['singular'] . ' deleted.');
                redirect(ResourceRegistry::pageUrl($RESOURCE_KEY));
        }
        Session::flash('error', 'Unknown action.');
        redirect(ResourceRegistry::pageUrl($RESOURCE_KEY));
    } catch (HttpError $e) {
        if ($e->status === 422) {
            $errors = $e->context ?: [];
            remember_old($_POST);
            Session::flash('warning', $e->getMessage());
        } else {
            Session::flash('error', $e->getMessage());
            redirect(ResourceRegistry::pageUrl($RESOURCE_KEY));
        }
    }
}

$filters = ['q' => (string) input('q', '')];
if ($deptFilter = (int) input('department_id', 0)) {
    $filters['department_id'] = $deptFilter;
}

$page  = max(1, (int) input('page', 1));
$list  = $resources->index($RESOURCE_KEY, $filters, $page);
$rows  = $list['rows'];

if ($editId = (int) input('edit', 0)) {
    $editing = $resources->find($RESOURCE_KEY, $editId);
}

// Reference options for any `ref` field (scope-filtered).
$refOptions = [];
foreach ($definition['fields'] as $name => $field) {
    if (($field['type'] ?? null) === 'ref') {
        $refOptions[$name] = $resources->referenceOptions($RESOURCE_KEY, $name);
    }
}

$scope = $resources->scope();
$title = $RESOURCE_TITLE ?? $definition['plural'];

layout_header([
    'title'       => $title,
    'subtitle'    => $RESOURCE_SUBTITLE ?? ('Rows restricted to ' . $scope->displayName() . '.'),
    'active'      => $RESOURCE_ACTIVE ?? $RESOURCE_KEY,
    'actions'     => '',
    'breadcrumbs' => [
        ['label' => 'Dashboard', 'href' => 'index.php'],
        ['label' => $title],
    ],
]);
?>

<?php if ($canCreate || $editing): ?>
<div class="card">
  <div class="card__head"><?= $editing ? 'Edit ' . e($definition['singular']) . ' #' . (int) $editing['id'] : 'New ' . e($definition['singular']) ?></div>
  <form method="post" action="<?= e($self) ?>">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
    <div class="card__body">
      <div class="form-grid">
        <?php foreach ($definition['fields'] as $name => $field): ?>
          <?php
          $label    = $field['label'] ?? $name;
          $type     = $field['type'] ?? 'text';
          $required = !empty($field['required']);
          $value    = $editing[$name] ?? old_input($name, '');
          $error    = $errors[$name] ?? null;
          ?>
          <div class="field<?= $type === 'textarea' ? ' field--full' : '' ?>">
            <label for="f-<?= e($name) ?>"><?= e($label) ?><?= $required ? ' <span class="req">*</span>' : '' ?></label>

            <?php if ($type === 'select'): ?>
              <select id="f-<?= e($name) ?>" name="<?= e($name) ?>">
                <option value="">— select —</option>
                <?php foreach (($field['options'] ?? []) as $option): ?>
                  <option value="<?= e($option) ?>" <?= (string) $value === (string) $option ? 'selected' : '' ?>><?= e($option) ?></option>
                <?php endforeach; ?>
              </select>

            <?php elseif ($type === 'ref'): ?>
              <select id="f-<?= e($name) ?>" name="<?= e($name) ?>" <?= $required ? 'required' : '' ?>>
                <option value="">— select —</option>
                <?php foreach (($refOptions[$name] ?? []) as $option): ?>
                  <option value="<?= (int) $option['id'] ?>" <?= (int) $value === (int) $option['id'] ? 'selected' : '' ?>>
                    <?= e($option['label']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <span class="hint">Only entries inside your scope are listed.</span>

            <?php elseif ($type === 'textarea'): ?>
              <textarea id="f-<?= e($name) ?>" name="<?= e($name) ?>" rows="3"><?= e((string) $value) ?></textarea>

            <?php else: ?>
              <?php
              $inputType = match ($type) {
                  'number', 'decimal' => 'number',
                  'date'              => 'date',
                  'email'             => 'email',
                  'tel'               => 'tel',
                  default             => 'text',
              };
              $step = $type === 'decimal' ? ' step="0.01"' : '';
              ?>
              <input type="<?= $inputType ?>"<?= $step ?> id="f-<?= e($name) ?>" name="<?= e($name) ?>"
                     value="<?= e((string) $value) ?>" <?= $required ? 'required' : '' ?>>
            <?php endif; ?>

            <?php if ($error): ?><span class="error"><?= e($error) ?></span><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <p class="help" style="margin-top:14px">
        New rows are written to <code><?= e($definition['table']) ?></code> with
        <code>institution_id</code> / <code>department_id</code> taken from your scope
        (<?= e($scope->label()) ?>).
      </p>
    </div>
    <div class="card__foot">
      <button class="btn" type="submit"><?= $editing ? 'Save changes' : 'Create' ?></button>
      <?php if ($editing): ?><a class="btn btn--ghost" href="<?= e($self) ?>">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <div class="card__head">
    <?= e($definition['plural']) ?> in your scope <span class="badge badge--muted"><?= (int) $list['total'] ?></span>
    <form class="toolbar" style="margin-left:auto" method="get" action="<?= e($self) ?>">
      <?php if ($definition['search']): ?>
        <div class="field"><input type="search" name="q" placeholder="Search" value="<?= e($filters['q']) ?>"></div>
      <?php endif; ?>
      <button class="btn btn--ghost btn--sm" type="submit">Filter</button>
    </form>
  </div>
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr>
          <th>#</th>
          <?php foreach ($definition['fields'] as $name => $field): ?>
            <th><?= e($field['label'] ?? $name) ?></th>
          <?php endforeach; ?>
          <th>Department</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="<?= count($definition['fields']) + 3 ?>" class="empty">
            No rows in your scope<?= $filters['q'] ? ' for this search' : '' ?>.
          </td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $row): ?>
          <?php
          // Resolve the department name for context without an extra query per row.
          $deptName = (string) Database::value(
              'SELECT `name` FROM `departments` WHERE `id` = ?',
              [(int) $row['department_id']],
              '—'
          );
          ?>
          <tr>
            <td class="muted"><?= (int) $row['id'] ?></td>
            <?php foreach ($definition['fields'] as $name => $field): ?>
              <?php $value = $row[$name] ?? null; ?>
              <td>
                <?php if (($field['type'] ?? '') === 'ref'): ?>
                  <?php
                  $refLabel = null;
                  foreach (($refOptions[$name] ?? []) as $option) {
                      if ((int) $option['id'] === (int) $value) {
                          $refLabel = $option['label'];
                          break;
                      }
                  }
                  ?>
                  <?= e((string) ($refLabel ?? ($value !== null ? '#' . $value : '—'))) ?>
                <?php elseif ($name === 'status' || $name === 'type' || str_ends_with($name, '_condition')): ?>
                  <?= $value !== null ? badge((string) $value, status_tone((string) $value)) : '—' ?>
                <?php elseif ($value === null || $value === ''): ?>
                  <span class="muted">—</span>
                <?php elseif (($field['type'] ?? '') === 'decimal'): ?>
                  <span class="num"><?= e(money($value)) ?></span>
                <?php else: ?>
                  <?= e((string) $value) ?>
                <?php endif; ?>
              </td>
            <?php endforeach; ?>
            <td class="muted"><?= e($deptName) ?></td>
            <td>
              <div class="actions-cell">
                <?php if ($canUpdate): ?>
                  <a class="btn btn--ghost btn--sm" href="<?= e($self . '?edit=' . (int) $row['id']) ?>">Edit</a>
                <?php endif; ?>
                <?php if ($canDelete): ?>
                  <form class="inline-form" method="post" action="<?= e($self) ?>" data-confirm="Delete this row?">
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
  <?php if ((int) $list['pages'] > 1): ?>
    <div class="card__foot">
      <div class="pagination">
        <?php for ($p = 1; $p <= (int) $list['pages']; $p++): ?>
          <?php if ($p === (int) $list['page']): ?>
            <span class="is-current"><?= $p ?></span>
          <?php else: ?>
            <a href="<?= e($self . '?page=' . $p . ($filters['q'] !== '' ? '&q=' . urlencode($filters['q']) : '')) ?>"><?= $p ?></a>
          <?php endif; ?>
        <?php endfor; ?>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php layout_footer(); ?>
