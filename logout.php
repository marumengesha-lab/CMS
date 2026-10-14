<?php
/**
 * Sign out and destroy the session.
 */
declare(strict_types=1);

require __DIR__ . '/config/bootstrap.php';

Auth::logout();
Session::destroy();

session_start();
Session::flash('info', 'You have been signed out.');
redirect('login.php');
