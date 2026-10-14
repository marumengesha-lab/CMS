<?php
/**
 * Login screen. Brute-force attempts are throttled via the login_attempts
 * table (see Auth::isLockedOut()).
 */
declare(strict_types=1);

require __DIR__ . '/config/bootstrap.php';

if (Auth::check()) {
    redirect('index.php');
}

$error = null;
$username = '';

if (is_post()) {
    Csrf::verifyRequest();
    $username = (string) input('username', '');
    $result   = Auth::attempt($username, (string) input('password', ''));

    if ($result['ok']) {
        Session::flash('success', $result['message']);
        redirect('index.php');
    }
    $error = $result['message'];
}
?>
<!doctype html>
<html lang="am">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in &middot; <?= e((string) config('app.name')) ?></title>
<link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
</head>
<body>
<div class="login-wrap">
  <div class="login-card">
    <h1><?= e((string) config('app.name')) ?></h1>
    <p class="lead"><?= e((string) config('app.name_am')) ?> &middot; Sabbath School Management System</p>

    <?= flash_html() ?>
    <?php if ($error !== null): ?>
      <div class="flash flash--error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('login.php')) ?>">
      <?= Csrf::field() ?>
      <div class="field">
        <label for="username">Username or e-mail</label>
        <input type="text" id="username" name="username" value="<?= e($username) ?>" required autofocus autocomplete="username">
      </div>
      <div class="field">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required autocomplete="current-password">
      </div>
      <button class="btn" type="submit">Sign in</button>
    </form>

    <div class="demo-creds">
      <strong>Demo accounts</strong> (from the seed data):<br>
      <code>main_admin</code> / <code>Admin@123</code> &mdash; Main System Admin<br>
      <code>bole_admin</code> / <code>Admin@123</code> &mdash; Institution Admin<br>
      <code>ss_admin</code> / <code>Senbet@123</code> &mdash; Department Admin<br>
      <code>primary_teacher</code> / <code>Senbet@123</code> &mdash; Teacher
    </div>
  </div>
</div>
</body>
</html>
