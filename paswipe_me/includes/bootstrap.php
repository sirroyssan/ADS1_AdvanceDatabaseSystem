<?php
/**
 * Loaded first by every page: configuration, helpers, database and session.
 */

// Never show raw PHP errors or SQL errors to users; log them instead.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// This check must stay before any file that uses PHP 8 syntax.
if (PHP_VERSION_ID < 80000) {
    http_response_code(500);
    exit('This system needs PHP 8.0 or newer. Your XAMPP runs PHP ' . PHP_VERSION
        . '. Please install a newer XAMPP from apachefriends.org.');
}

foreach (['pdo_mysql', 'mbstring'] as $extension) {
    if (!extension_loaded($extension)) {
        http_response_code(500);
        exit('The PHP extension "' . $extension . '" is not enabled. Enable extension=' . $extension
            . ' in xampp/php/php.ini, then restart Apache.');
    }
}

define('APP_ROOT', dirname(__DIR__));

require_once APP_ROOT . '/config/app.php';
require_once APP_ROOT . '/includes/error_handler.php';
require_once APP_ROOT . '/config/database.php';
require_once APP_ROOT . '/includes/functions.php';
require_once APP_ROOT . '/includes/icons.php';
require_once APP_ROOT . '/includes/csrf.php';
require_once APP_ROOT . '/includes/activity_log.php';
require_once APP_ROOT . '/includes/db_helpers.php';
require_once APP_ROOT . '/includes/form_fields.php';
require_once APP_ROOT . '/includes/list_helpers.php';
require_once APP_ROOT . '/includes/lookups.php';

start_secure_session();
