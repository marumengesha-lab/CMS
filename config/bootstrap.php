<?php
/**
 * Bootstrap: loads configuration, registers the autoloader, starts the
 * session and wires up error handling. Every entry point (page, API or
 * CLI script) must require this file first.
 */
declare(strict_types=1);

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
if (!defined('APP_START')) {
    define('APP_START', microtime(true));
}

/** @var array $config */
$config = require BASE_PATH . '/config/config.php';
$GLOBALS['app_config'] = $config;

date_default_timezone_set($config['app']['timezone']);
mb_internal_encoding('UTF-8');

// ---------------------------------------------------------------------
// Error handling
// ---------------------------------------------------------------------
if ($config['app']['debug']) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
    ini_set('display_errors', '0');
}
ini_set('log_errors', '1');

// ---------------------------------------------------------------------
// PSR-4-ish autoloader for the /src folder (global namespace)
// ---------------------------------------------------------------------
spl_autoload_register(static function (string $class): void {
    $file = BASE_PATH . '/src/' . $class . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

require_once BASE_PATH . '/src/helpers.php';

// ---------------------------------------------------------------------
// Session
// ---------------------------------------------------------------------
Session::start();

// ---------------------------------------------------------------------
// Centralised error / exception -> response mapping
// ---------------------------------------------------------------------
set_exception_handler(static function (Throwable $e): void {
    ErrorReporter::handle($e);
});
