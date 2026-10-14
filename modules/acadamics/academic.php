<?php
/**
 * Academics landing page. Shows how many setup entities and learners exist
 * inside the caller's scope, and links to each screen the caller may open.
 */
declare(strict_types=1);

require __DIR__ . '/../../config/bootstrap.php';

$user      = Guard::requirePermission('academics.view');
$scope     = Auth::scope();
$resources = new ResourceController($user);

$cards = [];
foreach (ResourceRegistry::grouped()['academics'] as $key => $definition) {
    $canView = Rbac::can($user, ResourceRegistry::permissionFor($definition, 'view'));
    $cards[] = [
        'key'     => $key,
        'title'   => $definition['plural'],
        'table'   => $definition['table'],
        'url'     => ResourceRegistry::pageUrl($key),
        'can'     => $canView,
        'count'   => $canView ? $resources->count($key) : null,
        'group'   => $definition['group'],
    ];
}

layout_header([
    'title'       => 'Academics',
    'subtitle'    => 'Levels, classrooms, courses, learners, assessments and certificates — scoped to ' . $scope->displayName() . '.',
    'active'      => 'academic',
    'actions'     => Rbac::can($user, 'academics.setup')
        ? '<a class="btn btn--ghost" href="' . e(url('modules/acadamics/academic_setup.php')) . '">Setup overview</a>'
        : '',
    'breadcrumbs' => [['label' => 'Dashboard', 'href' => 'index.php'], ['label' => 'Academics']],
]);
?>

<div class="grid grid--4">
  <?php foreach ($cards as $card): ?>
    <div class="card">
      <div class="stat">
        <div class="stat__label"><?= e($card['title']) ?></div>
        <div class="stat__value"><?= $card['can'] ? (int) $card['count'] : '—' ?></div>
        <div class="stat__hint">
          <?php if ($card['can']): ?>
            <a href="<?= e(url($card['url'])) ?>">Open <?= e($card['title']) ?></a>
          <?php else: ?>
            <span class="muted">no access</span>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="card__head">Other academic screens</div>
  <div class="card__body">
    <div class="chip-list">
      <a class="btn btn--ghost btn--sm" href="<?= e(url('modules/acadamics/academic_reports.php')) ?>">Academic reports</a>
      <?php if (Rbac::can($user, 'academics.reportcard.view')): ?>
        <a class="btn btn--ghost btn--sm" href="<?= e(url('modules/acadamics/report_cards.php')) ?>">Report cards</a>
      <?php endif; ?>
      <?php if (Rbac::can($user, 'academics.certificate.manage')): ?>
        <a class="btn btn--ghost btn--sm" href="<?= e(url('modules/acadamics/certificates.php')) ?>">Certificates</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php layout_footer(); ?>
