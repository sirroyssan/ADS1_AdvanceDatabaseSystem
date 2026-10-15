<?php
/**
 * Application-wide settings.
 */

defined('APP_ROOT') or exit('No direct access.');

// System name shown in the header, sidebar, login page, footer and printouts.
// (The database is still pa_swipe_ako; only the displayed name changed.)
const APP_NAME    = 'PA-KASKAS';
const APP_TAGLINE = 'Credit card, borrower and repayment tracking';

/*
 * Base URL of the application, WITHOUT a trailing slash.
 * Leave empty ('') to auto-detect (works for htdocs/pa_kaskas, a sub-folder,
 * or a virtual host). Only set it if auto-detection fails, e.g. '/pa_kaskas'.
 */
const APP_BASE_URL = '';

/*
 * Show error details on screen (true) or only a friendly message (false).
 * Turn on temporarily while setting up XAMPP; keep false otherwise.
 */
const APP_DEBUG = false;

// Roles from the user_roles.role ENUM that may use the admin dashboard.
const ADMIN_ROLES = ['owner', 'admin'];

// Log out after this many seconds without activity.
const SESSION_IDLE_TIMEOUT = 1800;

// Login protection, using users.failed_login_attempts and users.locked_until.
const MAX_FAILED_LOGINS = 5;
const LOCKOUT_MINUTES   = 15;

// Time zone used for dates shown and printed by PHP.
date_default_timezone_set('Asia/Manila');
