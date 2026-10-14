<?php
/**
 * Academic reports, always restricted to the caller's scope.
 *
 * An Institution Admin gets every department of the institution in one table;
 * a Department Admin gets only its own sub-tree. The SQL is built by
 * Scope::applyTo(), so there is no way to widen it from the request.
 */
declare(strict_types=1);

require __DIR__ . '/../../config/bootstrap.php';

$user  = Guard::requirePermission('academics.view');
$scope = Auth::scope();

$params = [];
$sql = 'SELECT r.`department_id`, r.`name`, r.`depth`, r.`institution_name`,
               (SELECT COUNT(*) FROM `levels`       l WHERE l.`department_id` = r.`department_id`) AS `levels`,
               (SELECT COUNT(*) FROM `classrooms`   c WHERE c.`department_id` = r.`department_id`) AS `classrooms`,
               (SELECT COUNT(*) FROM `courses`      co WHERE co.`department_id` = r.`department_id`) AS `courses`,
               (SELECT COUNT(*) FROM `students`     s WHERE s.`department_id` = r.`department_id`) AS `students`,
               (SELECT COUNT(*) FROM `assessments`  a WHERE a.`department_id` = r.`department_id`) AS `assessments`,
               (SELECT COUNT(*) FROM `grades`       g WHERE g.`department_id` = r.`department_id`) AS `grades`,
               (SELECT COUNT(*) FROM `certificates` ce WHERE ce.`department_id` = r.`department_id`) AS `certificates`,
               (SELECT COALESCE(AVG(g2.`score`),0) FROM `grades` g2 WHERE g2.`department_id` = r.`department_id`) AS `avg_score`
          FROM `v_department_reports` r';

$sql = $scope->applyTo($sql, 'r', ['institution' => 'institution_id', 'department' => 'department_id'], $params);
$sql .= ' ORDER BY r.`institution_id`, r.`path`';

$rows = Database::all($sql, $params);

$totals = ['students' => 0, 'assessments' => 0, 'grades' => 0, 'certificates' => 0];
foreach ($rows as $row) {
    foreach ($totals as $key => $value) {
        $totals[$key] += (int) $row[$key];
    }
}

layout_header([
    'title'       => 'Academic reports',
    'subtitle'    => 'Aggregated for ' . ucfirst($scope->type()) . ' scope: ' . $scope->displayName(),
    'active'      => 'academic_reports',
    'actions'     => '',
    'breadcrumbs' => [
        ['label' => 'Dashboard', 'href' => 'index.php'],
        ['label' => 'Academics', 'href' => 'modules/acadamics/academic.php'],
        ['label' => 'Academic reports'],
    ],
]);
?>

<div class="grid grid--4">
  <div class="card"><div class="stat"><div class="stat__label">Students</div><div class="stat__value"><?= (int) $totals['students'] ?></div></div></div>
  <div class="card"><div class="stat"><div class="stat__label">Assessments</div><div class="stat__value"><?= (int) $totals['assessments'] ?></div></div></div>
  <div class="card"><div class="stat"><div class="stat__label">Grades recorded</div><div class="stat__value"><?= (int) $totals['grades'] ?></div></div></div>
  <div class="card"><div class="stat"><div class="stat__label">Certificates</div><div class="stat__value"><?= (int) $totals['certificates'] ?></div></div></div>
</div>

<div class="card">
  <div class="card__head">
    Departments in scope <span class="badge badge--muted"><?= count($rows) ?></span>
    <span class="badge badge--primary" style="margin-left:auto">
      <?= $scope->isInstitution() ? 'Institution-wide aggregation' : 'Own sub-tree only' ?>
    </span>
  </div>
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr><th>Department</th><th class="num">Levels</th><th class="num">Classrooms</th><th class="num">Courses</th>
            <th class="num">Students</th><th class="num">Assessments</th><th class="num">Grades</th>
            <th class="num">Certificates</th><th class="num">Avg score</th></tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="9" class="empty">Nothing in scope.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $row): ?>
          <tr>
            <td>
              <span class="tree-inline"><?= str_repeat('&nbsp;&nbsp;', (int) $row['depth']) ?></span>
              <strong><?= e($row['name']) ?></strong>
              <?php if ($scope->isGlobal()): ?><div class="muted"><?= e($row['institution_name']) ?></div><?php endif; ?>
            </td>
            <td class="num"><?= (int) $row['levels'] ?></td>
            <td class="num"><?= (int) $row['classrooms'] ?></td>
            <td class="num"><?= (int) $row['courses'] ?></td>
            <td class="num"><?= (int) $row['students'] ?></td>
            <td class="num"><?= (int) $row['assessments'] ?></td>
            <td class="num"><?= (int) $row['grades'] ?></td>
            <td class="num"><?= (int) $row['certificates'] ?></td>
            <td class="num"><?= e(number_format((float) $row['avg_score'], 1)) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php layout_footer(); ?>
