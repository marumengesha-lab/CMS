<?php
/**
 * Unit tests for base_path_url() - the helper that lets the application live
 * either at the web root or inside a sub-folder such as /senbet_maderaja.
 *
 *   php tests/base_path_test.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

// Bootstrap the config + helpers without touching the database.
define('BASE_PATH', dirname(__DIR__));
$GLOBALS['app_config'] = require BASE_PATH . '/config/config.php';
$GLOBALS['app_config']['app']['base_url'] = '';   // auto-detect mode
require BASE_PATH . '/src/helpers.php';

$pass = 0;
$fail = 0;

function check(string $name, string $expected, string $actual): void
{
    global $pass, $fail;
    if ($expected === $actual) {
        $pass++;
        echo "  [PASS] {$name}\n";
    } else {
        $fail++;
        echo "  [FAIL] {$name} — expected '{$expected}', got '{$actual}'\n";
    }
}

function scenario(array $server, callable $body): void
{
    $_SERVER = $server + ['REQUEST_METHOD' => 'GET'];
    $body();
}

$here = rtrim(str_replace('\\', '/', BASE_PATH), '/');

// ---------------------------------------------------------------------
echo "base_path_url() — a real XAMPP layout\n";

scenario(['DOCUMENT_ROOT' => 'C:/xampp/htdocs'], function () {
    check(
        'htdocs/senbet_maderaja -> /senbet_maderaja',
        '/senbet_maderaja',
        base_path_url('C:/xampp/htdocs/senbet_maderaja')
    );
    check(
        'Windows backslashes are normalised',
        '/senbet_maderaja',
        base_path_url('C:\\xampp\\htdocs\\senbet_maderaja')
    );
    check(
        'case-insensitive drive/path comparison',
        '/senbet_maderaja',
        base_path_url('c:/XAMPP/htdocs/senbet_maderaja')
    );
});

scenario(['DOCUMENT_ROOT' => 'C:/xampp/htdocs'], function () {
    check(
        'app placed directly in htdocs -> empty prefix',
        '',
        base_path_url('C:/xampp/htdocs')
    );
});

scenario(['DOCUMENT_ROOT' => '/var/www/html'], function () {
    check(
        'deeper nesting -> /apps/senbet_maderaja',
        '/apps/senbet_maderaja',
        base_path_url('/var/www/html/apps/senbet_maderaja')
    );
});

scenario([], function () use ($here) {
    check(
        'CLI / unknown layout falls back to the web root',
        '',
        base_path_url($here)
    );
});

// ---------------------------------------------------------------------
echo "\nbase_path_url() — fallback and override\n";

scenario([
    'DOCUMENT_ROOT'   => '/nonexistent',
    'SCRIPT_NAME'     => '/myapp/admin/users.php',
    'SCRIPT_FILENAME' => $here . '/admin/users.php',
], function () use ($here) {
    check(
        'derives the prefix from SCRIPT_NAME when DOCUMENT_ROOT does not match',
        '/myapp',
        base_path_url($here)
    );
});

$GLOBALS['app_config']['app']['base_url'] = '/explicit/path/';
scenario(['DOCUMENT_ROOT' => 'C:/xampp/htdocs'], function () {
    check(
        'APP_BASE_URL override wins and is normalised',
        '/explicit/path',
        base_path_url('C:/xampp/htdocs/senbet_maderaja')
    );
});
$GLOBALS['app_config']['app']['base_url'] = '';

// ---------------------------------------------------------------------
echo "\nurl() and asset()\n";

// Drive url()/asset() through the SCRIPT_NAME branch (fully deterministic).
$prefixed = [
    'DOCUMENT_ROOT'   => '/nonexistent',
    'SCRIPT_NAME'     => '/myapp/index.php',
    'SCRIPT_FILENAME' => $here . '/index.php',
];
// NB: the outer closure must also import $here, otherwise the nested closure
// below would resolve $here against the outer scope and capture null.
scenario($prefixed, function () use ($here) {
    check('url() prefixes a page', '/myapp/login.php', url('login.php'));
    check('url() prefixes a nested page', '/myapp/admin/roles.php', url('admin/roles.php'));
    check('url() normalises a leading slash', '/myapp/index.php', url('/index.php'));
    check('url() with no argument returns the app root', '/myapp/', url());
    check('asset() prefixes the stylesheet', '/myapp/assets/css/style.css', asset('css/style.css'));
    check('current_path() strips the prefix', 'admin/users.php', (static function () use ($here) {
        // Keep SCRIPT_NAME and SCRIPT_FILENAME consistent with each other.
        $_SERVER['SCRIPT_NAME']     = '/myapp/admin/users.php';
        $_SERVER['SCRIPT_FILENAME'] = $here . '/admin/users.php';
        return current_path();
    })());
});

scenario(['DOCUMENT_ROOT' => $here], function () {
    check('url() at the web root', '/login.php', url('login.php'));
    check('asset() at the web root', '/assets/js/app.js', asset('js/app.js'));
    check('url() with no argument at the web root', '/', url());
});

// ---------------------------------------------------------------------
echo "\n" . str_repeat('=', 60) . "\n";
echo sprintf("base_path: %d passed, %d failed\n", $pass, $fail);
echo str_repeat('=', 60) . "\n";

exit($fail === 0 ? 0 : 1);
