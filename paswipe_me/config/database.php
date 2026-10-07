<?php
/**
 * Database configuration and the single shared PDO connection.
 *
 * CONFIGURE THESE VALUES FOR YOUR XAMPP INSTALLATION.
 * The uploaded schema has no CREATE DATABASE statement, so the database name
 * below is the name you choose when you create the database in phpMyAdmin.
 */

defined('APP_ROOT') or exit('No direct access.');

const DB_HOST    = 'localhost';
const DB_PORT    = 3306;
const DB_NAME    = 'pa_swipe_ako';
const DB_USER    = 'root';
const DB_PASS    = '';
const DB_CHARSET = 'utf8mb4';

/**
 * Returns the one PDO connection used by the whole request.
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        DB_HOST,
        DB_PORT,
        DB_NAME,
        DB_CHARSET
    );

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        // Log the real reason; never show connection details to the browser.
        error_log('[' . APP_NAME . '] Database connection failed: ' . $e->getMessage());
        http_response_code(503);
        exit('The system is temporarily unavailable. Please make sure MySQL is running and config/database.php is correct.');
    }

    // Use PHP's time zone (config/app.php) for NOW() and CURRENT_TIMESTAMP,
    // so times saved by the database match times shown by PHP.
    $pdo->exec('SET time_zone = ' . $pdo->quote(date('P')));

    return $pdo;
}
