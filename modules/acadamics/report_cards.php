<?php
/**
 * Report cards: one learner's results, assembled from the scope-filtered
 * students / assessments / grades tables.
 *
 * The learner picker only offers students inside the caller's scope, and the
 * per-student query is re-checked with Scope::assertDepartment().
 */
declare(strict_types=1);

require __DIR__ . '/../../config/bootstrap.php';

$user      = Guard::requirePermission('academics.reportcard.view');
$scope     = Auth::scope();
$resources = new ResourceController($user);

$students = $resources->index('students', [], 1, 200)['rows'];
$studentId = (int) input('student_id', 0);
$student   = null;
$lines     = [];
$average   = null;

if ($studentId) {
    $student = $resources->find('students', $studentId); // 403 if outside scope

    $params = [];
    $sql = 'SELECT g.`id`, g.`score`, g.`letter_grade`, g.`remark`,
                   a.`title` AS assessment_title, a.`assessment_type`, a.`max_score`, a.`assessment_date`,
                   c.`title` AS course_title, c.`code` AS course_code
              FROM `grades` g
              JOIN `assessments` a ON a.`id` = g.`assessment_id`
              LEFT JOIN `courses` c ON c.`id` = a.`course_id`
             WHERE g.`student_id` = ?';
    $params[] = $studentId;
    $sql = $scope->applyTo($sql, 'g', ['institution' => 'institution_id', 'department' => 'department_id'], $params, true);
    $sql .= ' ORDER BY a.`assessment_date`, a.`id`';

    $lines = Database::all($sql, $params);

    if ($lines) {
        $pct = [];
        foreach ($lines as $line) {
            $max = (float) $line['max_score'] ?: 100.0;
            $pct[] = ((float) $line['score'] / $max) * 100;
        }
        $average = array_sum($pct) / count($pct);
    }
}

layout_header([
    'title'       => 'Report cards',
    'subtitle'    => 'Learner results inside ' . $scope->displayName() . '.',
    'active'      => 'academic_reports',
    'actions'     => '',
    'breadcrumbs' => [
        ['label' => 'Dashboard', 'href' => 'index.php'],
        ['label' => 'Academics', 'href' => 'modules/acadamics/academic.php'],
        ['label' => 'Report cards'],
    ],
]);
?>

<div class="card no-print">
  <div class="card__head">Choose a learner</div>
  <form method="get" action="<?= e(url('modules/acadamics/report_cards.php')) ?>">
    <div class="card__body">
      <div class="toolbar">
        <div class="field">
          <label>Student</label>
          <select name="student_id" required>
            <option value="">— select —</option>
            <?php foreach ($students as $row): ?>
              <option value="<?= (int) $row['id'] ?>" <?= $studentId === (int) $row['id'] ? 'selected' : '' ?>>
                <?= e($row['student_code']) ?> — <?= e($row['full_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <button class="btn" type="submit">Show report card</button>
      </div>
      <p class="help" style="margin-top:10px">Only learners inside your scope are listed.</p>
    </div>
  </form>
</div>

<?php if ($student): ?>
<div class="card">
  <div class="card__head">
    Report card — <?= e($student['full_name']) ?> <code><?= e($student['student_code']) ?></code>
    <?php if ($average !== null): ?>
      <span class="badge badge--primary" style="margin-left:auto">Average <?= e(number_format($average, 1)) ?>%</span>
    <?php endif; ?>
  </div>
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr><th>Date</th><th>Assessment</th><th>Type</th><th>Course</th>
            <th class="num">Score</th><th class="num">Out of</th><th>Letter</th><th>Remark</th></tr>
      </thead>
      <tbody>
        <?php if (!$lines): ?>
          <tr><td colspan="8" class="empty">No grades recorded for this learner in your scope.</td></tr>
        <?php endif; ?>
        <?php foreach ($lines as $line): ?>
          <tr>
            <td class="muted"><?= e($line['assessment_date'] ?? '—') ?></td>
            <td><?= e($line['assessment_title']) ?></td>
            <td><?= badge((string) $line['assessment_type']) ?></td>
            <td class="muted"><?= e($line['course_title'] ?? '—') ?><?= $line['course_code'] ? ' (' . e($line['course_code']) . ')' : '' ?></td>
            <td class="num"><?= e(number_format((float) $line['score'], 2)) ?></td>
            <td class="num"><?= e(number_format((float) $line['max_score'], 2)) ?></td>
            <td><?= e($line['letter_grade'] ?? '—') ?></td>
            <td class="muted"><?= e($line['remark'] ?? '') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php layout_footer(); ?>
