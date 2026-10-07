<?php
/**
 * Loaded first by every page: configuration, helpers, database and session.
 */

// Never show PHP errors or SQL errors to users; log them instead.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

define('APP_ROOT', dirname(__DIR__));

require_once APP_ROOT . '/config/app.php';
require_once APP_ROOT . '/config/database.php';
require_once APP_ROOT . '/includes/functions.php';
require_once APP_ROOT . '/includes/icons.php';
require_once APP_ROOT . '/includes/csrf.php';
require_once APP_ROOT . '/includes/activity_log.php';

start_secure_session();
