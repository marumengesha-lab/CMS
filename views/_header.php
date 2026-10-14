<?php
/**
 * Shared page shell: top bar, permission-filtered navigation, page heading
 * and flash messages. Every page opens with layout_header() and closes with
 * layout_footer().
 *
 * @var string $title
 * @var array  $breadcrumbs
 * @var string $actions      raw HTML for the page-level action buttons
 * @var string $active       key of the active nav entry
 */
$user    = Auth::user();
$scope   = Auth::scope();
$active  = $active ?? '';
$crumbs  = $breadcrumbs ?? [];
$actions = $actions ?? '';

/** Render one nav link. */
$navLink = static function (string $href, string $label, string $icon, string $key, string $active): string {
    $isActive = $key === $active ? ' is-active' : '';
    return '<a class="' . trim($isActive) . '" href="' . e(url($href)) . '">'
        . '<span class="ico">' . $icon . '</span>' . e($label) . '</a>';
};

$resourceGroups = ResourceRegistry::grouped();
?>
<!doctype html>
<html lang="am">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? (string) config('app.name')) ?> &middot; <?= e((string) config('app.name')) ?></title>
<link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
</head>
<body>

<header class="topbar">
  <div class="topbar__brand">
    <span class="topbar__logo">&#10022;</span>
    <span>
      <?= e((string) config('app.name')) ?>
      <small><?= e((string) config('app.name_am')) ?> &middot; Sabbath School Management System</small>
    </span>
  </div>
  <div class="topbar__spacer"></div>
  <div class="topbar__meta">
    <span class="scope-pill" title="Your effective data scope">
      <?= e(ucfirst($scope->type())) ?>: <?= e($scope->displayName()) ?>
    </span>
    <div class="topbar__user">
      <strong><?= e($user['full_name'] ?? '') ?></strong>
      <span><?= e(implode(', ', array_map(static fn ($r) => $r['role_name'], $user['roles'] ?? []))) ?></span>
    </div>
    <a class="topbar__link" href="<?= e(url('logout.php')) ?>">Sign out</a>
  </div>
</header>

<div class="shell">
  <nav class="sidebar">
    <div class="sidebar__group">
      <div class="sidebar__title">Overview</div>
      <?= $navLink('index.php', 'Dashboard', '&#9633;', 'dashboard', $active) ?>
      <?php if (Rbac::can($user, 'report.view')): ?>
        <?= $navLink('modules/reports.php', 'Reports', '&#9684;', 'reports', $active) ?>
      <?php endif; ?>
    </div>

    <?php
    $orgLinks = [];
    if (Rbac::can($user, 'institution.view')) {
        $orgLinks[] = $navLink('admin/institutions.php', 'Institutions', '&#127970;', 'institutions', $active);
    }
    if (Rbac::can($user, 'department.view')) {
        $orgLinks[] = $navLink('admin/departments.php', 'Departments', '&#128194;', 'departments', $active);
    }
    if (Rbac::can($user, 'user.view')) {
        $orgLinks[] = $navLink('admin/users.php', 'Users', '&#128100;', 'users', $active);
    }
    if (Rbac::can($user, 'role.view')) {
        $orgLinks[] = $navLink('admin/roles.php', 'Roles', '&#128273;', 'roles', $active);
    }
    if (Rbac::can($user, 'permission.view')) {
        $orgLinks[] = $navLink('admin/permissions.php', 'Permissions', '&#9878;', 'permissions', $active);
    }
    if (Rbac::can($user, 'audit.view')) {
        $orgLinks[] = $navLink('admin/audit.php', 'Audit trail', '&#128220;', 'audit', $active);
    }
    ?>
    <?php if ($orgLinks): ?>
      <div class="sidebar__group">
        <div class="sidebar__title">Organisation &amp; access</div>
        <?= implode('', $orgLinks) ?>
      </div>
    <?php endif; ?>

    <?php
    $moduleLinks = [];
    foreach ($resourceGroups['modules'] as $key => $def) {
        if (Rbac::can($user, ResourceRegistry::permissionFor($def, 'view'))) {
            $moduleLinks[] = $navLink(ResourceRegistry::pageUrl($key), $def['plural'], '&#9679;', $key, $active);
        }
    }
    ?>
    <?php if ($moduleLinks): ?>
      <div class="sidebar__group">
        <div class="sidebar__title">Modules</div>
        <?= implode('', $moduleLinks) ?>
      </div>
    <?php endif; ?>

    <?php
    $academicLinks = [];
    if (Rbac::can($user, 'academics.view')) {
        $academicLinks[] = $navLink('modules/acadamics/academic.php', 'Academics home', '&#127891;', 'academic', $active);
    }
    foreach ($resourceGroups['academics'] as $key => $def) {
        if (Rbac::can($user, ResourceRegistry::permissionFor($def, 'view'))) {
            $academicLinks[] = $navLink(ResourceRegistry::pageUrl($key), $def['plural'], '&#8226;', $key, $active);
        }
    }
    if (Rbac::can($user, 'academics.view')) {
        $academicLinks[] = $navLink('modules/acadamics/academic_reports.php', 'Academic reports', '&#9684;', 'academic_reports', $active);
    }
    ?>
    <?php if ($academicLinks): ?>
      <div class="sidebar__group">
        <div class="sidebar__title">Academics</div>
        <?= implode('', $academicLinks) ?>
      </div>
    <?php endif; ?>
  </nav>

  <main class="main">
    <?php if ($crumbs): ?>
      <div class="breadcrumbs">
        <?php foreach ($crumbs as $i => $crumb): ?>
          <span>
            <?php if (!empty($crumb['href']) && $i < count($crumbs) - 1): ?>
              <a href="<?= e(url($crumb['href'])) ?>"><?= e($crumb['label']) ?></a>
            <?php else: ?>
              <?= e($crumb['label']) ?>
            <?php endif; ?>
          </span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="page-head">
      <div>
        <h1><?= e($title ?? '') ?></h1>
        <?php if (!empty($subtitle)): ?><div class="sub"><?= e($subtitle) ?></div><?php endif; ?>
      </div>
      <?php if ($actions !== ''): ?>
        <div class="page-head__actions"><?= $actions ?></div>
      <?php endif; ?>
    </div>

    <?= flash_html() ?>
