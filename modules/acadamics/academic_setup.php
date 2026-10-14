<?php
/**
 * Academic setup overview: how complete the academic configuration is for the
 * caller's scope (academic years, levels, classrooms, courses, departments).
 */
declare(strict_types=1);

require __DIR__ . '/../../config/bootstrap.php';

$user      = Guard::requirePermission('academics.view');
$scope     = Auth::scope();
$resources = new ResourceController($user);

$setupKeys = ['academic_years', 'levels', 'classrooms', 'courses', 'academic_departments'];
$status    = [];

foreach ($setupKeys as $key) {
    $definition = ResourceRegistry::get($key);
    $canView    = Rbac::can($user, ResourceRegistry::permissionFor($definition, 'view'));
    $status[$key] = [
        'definition' => $definition,
        'count'      => $canView ? $resources->count($key) : null,
        'can_view'   => $canView,
        'url'        => ResourceRegistry::pageUrl($key),
    ];
}

// Per-department configuration coverage.
$coverage = $scope->visibleDepartments();
foreach ($coverage as &$row) {
    $row['levels']     = (int) Database::value('SELECT COUNT(*) FROM `levels` WHERE `department_id` = ?', [(int) $row['id']], 0);
    $row['classrooms'] = (int) Database::value('SELECT COUNT(*) FROM `classrooms` WHERE `department_id` = ?', [(int) $row['id']], 0);
    $row['courses']    = (int) Database::value('SELECT COUNT(*) FROM `courses` WHERE `department_id` = ?', [(int) $row['id']], 0);
    $row['students']   = (int) Database::value('SELECT COUNT(*) FROM `students` WHERE `department_id` = ?', [(int) $row['id']], 0);
}
unset($row);

layout_header([
    'title'       => 'Academic setup',
    'subtitle'    => 'Configuration coverage for ' . $scope->displayName() . '.',
    'active'      => 'academic',
    'actions'     => '',
    'breadcrumbs' => [
        ['label' => 'Dashboard', 'href' => 'index.php'],
        ['label' => 'Academics', 'href' => 'modules/acadamics/academic.php'],
        ['label' => 'Setup'],
    ],
]);
?>

<div class="grid grid--3">
  <?php foreach ($status as $key => $item): ?>
    <div class="card"><div class="stat">
      <div class="stat__label"><?= e($item['definition']['plural']) ?></div>
      <div class="stat__value"><?= $item['can_view'] ? (int) $item['count'] : '—' ?></div>
      <div class="stat__hint">
        <?php if ($item['can_view']): ?>
          <a href="<?= e(url($item['url'])) ?>">Manage</a>
        <?php else: ?>
          <span class="muted">not visible at your scope</span>
        <?php endif; ?>
      </div>
    </div></div>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="card__head">Per-department coverage (scope-filtered)</div>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Department</th><th class="num">Levels</th><th class="num">Classrooms</th><th class="num">Courses</th><th class="num">Students</th></tr></thead>
      <tbody>
        <?php foreach ($coverage as $row): ?>
          <tr>
            <td>
              <span class="tree-inline"><?= str_repeat('&nbsp;&nbsp;', (int) $row['depth']) ?></span>
              <?= e($row['name']) ?>
            </td>
            <td class="num"><?= (int) $row['levels'] ?></td>
            <td class="num"><?= (int) $row['classrooms'] ?></td>
            <td class="num"><?= (int) $row['courses'] ?></td>
            <td class="num"><?= (int) $row['students'] ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php layout_footer(); ?>
