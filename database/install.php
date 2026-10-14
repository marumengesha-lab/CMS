<?php
/**
 * Command-line installer.
 *
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= php database/install.php
 *
 * Creates the database (if needed) and loads integration_schema.sql, which is
 * itself re-runnable: tables use CREATE TABLE IF NOT EXISTS and the seed uses
 * INSERT IGNORE.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run this installer from the command line.\n");
}

$config = require dirname(__DIR__) . '/config/config.php';
$db     = $config['db'];

$dsn = sprintf('mysql:host=%s;port=%s;charset=%s', $db['host'], $db['port'], $db['charset']);

fwrite(STDOUT, sprintf("Connecting to %s:%s as %s ...\n", $db['host'], $db['port'], $db['user']));

try {
    $pdo = new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "Connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

$pdo->exec(sprintf(
    'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
    $db['name']
));
fwrite(STDOUT, "Database `{$db['name']}` is ready.\n");

$pdo->exec(sprintf('USE `%s`', $db['name']));

$schemaFile = __DIR__ . '/integration_schema.sql';
$sql        = file_get_contents($schemaFile);
if ($sql === false) {
    fwrite(STDERR, "Cannot read {$schemaFile}\n");
    exit(1);
}

fwrite(STDOUT, "Loading integration_schema.sql ...\n");
$pdo->exec($sql);

// Report what we ended up with.
$tables = (int) $pdo->query(
    'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()'
)->fetchColumn();
$roles  = (int) $pdo->query('SELECT COUNT(*) FROM `roles`')->fetchColumn();
$perms  = (int) $pdo->query('SELECT COUNT(*) FROM `permissions`')->fetchColumn();
$users  = (int) $pdo->query('SELECT COUNT(*) FROM `users`')->fetchColumn();

fwrite(STDOUT, "\nDone.\n");
fwrite(STDOUT, "  tables      : {$tables}\n");
fwrite(STDOUT, "  roles       : {$roles}\n");
fwrite(STDOUT, "  permissions : {$perms}\n");
fwrite(STDOUT, "  users       : {$users}\n");
fwrite(STDOUT, "\nSign in with main_admin / Admin@123 and change the demo passwords.\n");
