<?php
/**
 * Senbet Maderaja - application configuration.
 *
 * Every value can be overridden with an environment variable so the same
 * code base runs on XAMPP (defaults) and on a production server.
 */
declare(strict_types=1);

return [
    'db' => [
        'host'    => getenv('DB_HOST') ?: '127.0.0.1',
        'port'    => getenv('DB_PORT') ?: '3306',
        'name'    => getenv('DB_NAME') ?: 'senbet_maderaja',
        'user'    => getenv('DB_USER') ?: 'root',
        // XAMPP's MySQL root account has an empty password by default.
        'pass'    => getenv('DB_PASS') !== false ? getenv('DB_PASS') : '',
        'charset' => 'utf8mb4',
    ],

    'app' => [
        'name'         => 'Senbet Maderaja',
        'name_am'      => 'ሰንበት ማደራጃ',
        // Leave empty to auto-detect the sub-folder the app is served from
        // (e.g. "/senbet_maderaja" on XAMPP). Set APP_BASE_URL only if your
        // layout is unusual (an Apache Alias, a reverse proxy, …).
        'base_url'     => getenv('APP_BASE_URL') ?: '',
        'debug'        => (bool) (getenv('APP_DEBUG') ?: false),
        'timezone'     => 'Africa/Addis_Ababa',
        'session_name' => 'senbet_sid',
        // Brute-force protection for login.php
        'max_login_attempts' => 5,
        'lockout_seconds'    => 300,
    ],
];
