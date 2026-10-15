<?php
/**
 * Installation check for XAMPP: http://localhost/pa_kaskas/setup_check.php
 *
 * Shows what is working and what still needs to be set up (PHP version,
 * extensions, database connection, imported tables, admin account).
 * For safety it only answers requests from the same computer (localhost).
 */

$remote = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($remote, ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    exit;
}

ini_set('display_errors', '0');

$checks = [];

function add_check(array &$checks, string $label, bool $ok, string $detail): void
{
    $checks[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail];
}

// --- PHP --------------------------------------------------------------
add_check($checks, 'PHP version', PHP_VERSION_ID >= 80000,
    'PHP ' . PHP_VERSION . (PHP_VERSION_ID >= 80000 ? '' : ' — PHP 8.0 or newer is required. Install a newer XAMPP.'));

foreach (['pdo_mysql', 'mbstring'] as $ext) {
    $loaded = extension_loaded($ext);
    add_check($checks, 'PHP extension ' . $ext, $loaded,
        $loaded ? 'Enabled' : 'Disabled — enable extension=' . $ext . ' in xampp/php/php.ini and restart Apache.');
}

$sessionPath = session_save_path() ?: sys_get_temp_dir();
$sessionWritable = is_dir($sessionPath) && is_writable($sessionPath);
add_check($checks, 'Session folder writable', $sessionWritable,
    $sessionWritable ? 'OK' : 'PHP cannot save sessions. Check session.save_path in php.ini (XAMPP default: xampp/tmp).');

// --- Database -----------------------------------------------------------
$canUseApp = PHP_VERSION_ID >= 80000 && extension_loaded('pdo_mysql');

if ($canUseApp) {
    define('APP_ROOT', __DIR__);
    require_once __DIR__ . '/config/app.php';

    // Same settings as config/database.php, but report errors instead of stopping.
    $source = (string) file_get_contents(__DIR__ . '/config/database.php');
    $setting = static function (string $name, string $fallback) use ($source): string {
        return preg_match('/const\s+' . $name . "\s*=\s*'([^']*)'/", $source, $m) ? $m[1] : $fallback;
    };
    $host = $setting('DB_HOST', 'localhost');
    $name = $setting('DB_NAME', 'pa_swipe_ako');
    $user = $setting('DB_USER', 'root');
    $pass = $setting('DB_PASS', '');
    $port = preg_match('/const\s+DB_PORT\s*=\s*(\d+)/', $source, $m) ? (int) $m[1] : 3306;

    $pdo = null;
    try {
        $pdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        $isMaria = stripos($version, 'mariadb') !== false;
        $versionOk = !$isMaria || version_compare($version, '10.6', '>=');
        add_check($checks, 'MySQL / MariaDB server', true, 'Connected as "' . $user . '" — server ' . $version);
        add_check($checks, 'MariaDB 10.6+ (recommended by the schema)', $versionOk,
            $versionOk ? 'OK' : 'Older MariaDB: the schema imports, but its inline foreign keys are not created. The app still checks dependencies itself.');
    } catch (PDOException $e) {
        $code = (int) ($e->errorInfo[1] ?? $e->getCode());
        // No match() here: this page must also run on old PHP to report the version.
        if ($code === 1045) {
            $hint = 'Wrong username/password. Check DB_USER and DB_PASS in config/database.php.';
        } elseif ($code === 2002) {
            $hint = 'MySQL is not running. Start MySQL in the XAMPP Control Panel.';
        } else {
            $hint = $e->getMessage();
        }
        add_check($checks, 'MySQL / MariaDB server', false, $hint);
    }

    if ($pdo) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = ?');
        $stmt->execute([$name]);
        $dbExists = (int) $stmt->fetchColumn() === 1;
        add_check($checks, 'Database "' . $name . '"', $dbExists,
            $dbExists ? 'Exists' : 'Not found. In phpMyAdmin run: CREATE DATABASE ' . $name . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;');

        if ($dbExists) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_type = 'BASE TABLE'");
            $stmt->execute([$name]);
            $tables = (int) $stmt->fetchColumn();
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.views WHERE table_schema = ?');
            $stmt->execute([$name]);
            $views = (int) $stmt->fetchColumn();

            add_check($checks, 'Schema imported', $tables >= 41 && $views >= 4,
                $tables . ' of 41 tables, ' . $views . ' of 4 views'
                . ($tables >= 41 && $views >= 4 ? '' : ' — import db/pa_swipe_ako_mariadb_10_6_v2.sql into "' . $name . '" (phpMyAdmin → select the database → Import).'));

            if ($tables >= 41) {
                $pdo->exec('USE `' . str_replace('`', '', $name) . '`');
                $admins = (int) $pdo->query(
                    "SELECT COUNT(DISTINCT u.id) FROM users u JOIN user_roles r ON r.user_id = u.id
                      WHERE u.status = 'active' AND r.role IN ('owner', 'admin')"
                )->fetchColumn();
                add_check($checks, 'Active administrator account', $admins > 0,
                    $admins > 0 ? $admins . ' found' : 'None yet. Run: php db/create_admin.php (from the XAMPP Shell).');
            }
        }
    }
}

$allOk = !in_array(false, array_column($checks, 'ok'), true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Setup check · PA-KASKAS</title>
    <style>
        body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background: #f4f6fa; color: #172033; margin: 0; padding: 24px 16px; }
        main { max-width: 760px; margin: 0 auto; background: #fff; border: 1px solid #e1e5ee; border-radius: 14px; padding: 24px; }
        h1 { margin: 0 0 6px; font-size: 22px; }
        ul { list-style: none; padding: 0; margin: 20px 0 0; }
        li { display: grid; grid-template-columns: 28px 1fr; gap: 8px; padding: 12px 0; border-top: 1px solid #e1e5ee; }
        .ok { color: #15803d; font-weight: 700; }
        .bad { color: #c62828; font-weight: 700; }
        .detail { color: #5d6880; font-size: 14px; word-break: break-word; }
        a { color: #2556d9; }
    </style>
</head>
<body>
<main>
    <h1>PA-KASKAS — setup check</h1>
    <p class="detail">
        <?= $allOk ? 'Everything is ready. <a href="index.php">Open the system</a>.' : 'Fix the items marked ✕, then reload this page.' ?>
    </p>
    <ul>
        <?php foreach ($checks as $check): ?>
            <li>
                <span class="<?= $check['ok'] ? 'ok' : 'bad' ?>" aria-label="<?= $check['ok'] ? 'OK' : 'Problem' ?>"><?= $check['ok'] ? '✓' : '✕' ?></span>
                <div>
                    <strong><?= htmlspecialchars($check['label'], ENT_QUOTES, 'UTF-8') ?></strong>
                    <div class="detail"><?= htmlspecialchars($check['detail'], ENT_QUOTES, 'UTF-8') ?></div>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
</main>
</body>
</html>
