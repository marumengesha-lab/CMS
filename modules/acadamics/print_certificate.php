<?php
/**
 * Printable certificate.
 *
 * The certificate is fetched through ResourceController::find(), so a user
 * outside the certificate's department gets HTTP 403 instead of somebody
 * else's document.
 */
declare(strict_types=1);

require __DIR__ . '/../../config/bootstrap.php';

$user      = Guard::requirePermission('academics.view');
$resources = new ResourceController($user);
$scope     = $resources->scope();

$certificateId = (int) input('id', 0);
$certificate   = null;
$student       = null;
$institution   = null;
$department    = null;

if ($certificateId) {
    $certificate = $resources->find('certificates', $certificateId); // 403 outside scope
    $student     = Database::one('SELECT * FROM `students` WHERE `id` = ?', [(int) $certificate['student_id']]);
    $institution = Database::one('SELECT * FROM `institutions` WHERE `id` = ?', [(int) $certificate['institution_id']]);
    $department  = Database::one('SELECT * FROM `departments` WHERE `id` = ?', [(int) $certificate['department_id']]);
}

// When no id is given, list the certificates the caller may print.
$available = $certificate ? [] : $resources->index('certificates', [], 1, 100)['rows'];

layout_header([
    'title'       => 'Print certificate',
    'subtitle'    => 'Certificates inside ' . $scope->displayName() . '.',
    'active'      => 'academic',
    'actions'     => $certificate ? '<button class="btn" onclick="window.print()">Print</button>' : '',
    'breadcrumbs' => [
        ['label' => 'Dashboard', 'href' => 'index.php'],
        ['label' => 'Academics', 'href' => 'modules/acadamics/academic.php'],
        ['label' => 'Print certificate'],
    ],
]);
?>

<?php if (!$certificate): ?>
  <div class="card no-print">
    <div class="card__head">Choose a certificate</div>
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>Serial</th><th>Title</th><th>Type</th><th>Issue date</th><th>Status</th><th></th></tr></thead>
        <tbody>
          <?php if (!$available): ?>
            <tr><td colspan="6" class="empty">No certificates in your scope.</td></tr>
          <?php endif; ?>
          <?php foreach ($available as $row): ?>
            <tr>
              <td><code><?= e($row['serial_no']) ?></code></td>
              <td><?= e($row['title']) ?></td>
              <td><?= badge((string) $row['certificate_type']) ?></td>
              <td class="muted"><?= e($row['issue_date']) ?></td>
              <td><?= badge((string) $row['status'], status_tone((string) $row['status'])) ?></td>
              <td><a class="btn btn--ghost btn--sm" href="<?= e(url('modules/acadamics/print_certificate.php?id=' . (int) $row['id'])) ?>">Open</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php else: ?>
  <div class="certificate">
    <div class="muted"><?= e($institution['name'] ?? '') ?></div>
    <h1><?= e($certificate['title']) ?></h1>
    <p class="muted"><?= e(ucfirst((string) $certificate['certificate_type'])) ?> certificate</p>
    <div class="who"><?= e($student['full_name'] ?? 'Unknown learner') ?></div>
    <p>
      <?= e($department['name'] ?? '') ?><br>
      Serial no: <strong><?= e($certificate['serial_no']) ?></strong><br>
      Issued on <?= e($certificate['issue_date']) ?><br>
      Status: <?= e($certificate['status']) ?>
    </p>
    <p class="muted" style="margin-top:34px">
      Issued by <?= e($user['full_name']) ?> — <?= e($institution['name'] ?? '') ?>
    </p>
  </div>
  <p class="help no-print" style="text-align:center;margin-top:14px">
    <a href="<?= e(url('modules/acadamics/print_certificate.php')) ?>">Back to the list</a>
  </p>
<?php endif; ?>

<?php layout_footer(); ?>
