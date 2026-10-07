<?php
/**
 * Create the first administrator account (command line only).
 *
 * The schema creates no users ("No default users/passwords are created by
 * this schema"), so this tool lets YOU enter your own account details.
 * It writes to the existing tables only: users + user_roles.
 *
 * Run from the XAMPP shell / terminal:
 *     Windows: C:\xampp\php\php.exe C:\xampp\htdocs\paswipe_me\db\create_admin.php
 *     macOS:   /Applications/XAMPP/xamppfiles/bin/php /Applications/XAMPP/xamppfiles/htdocs/paswipe_me/db/create_admin.php
 *     Linux:   /opt/lampp/bin/php /opt/lampp/htdocs/paswipe_me/db/create_admin.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/config/app.php';
require_once APP_ROOT . '/includes/error_handler.php';
require_once APP_ROOT . '/config/database.php';

function ask(string $question, bool $hidden = false): string
{
    echo $question;

    $canHide = $hidden && DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec')
        && function_exists('stream_isatty') && stream_isatty(STDIN);
    if ($canHide) {
        shell_exec('stty -echo 2>/dev/null');
    }

    $answer = fgets(STDIN);

    if ($canHide) {
        shell_exec('stty echo 2>/dev/null');
        echo PHP_EOL;
    }

    return trim((string) $answer);
}

echo PHP_EOL . '=== ' . APP_NAME . ' - create administrator ===' . PHP_EOL . PHP_EOL;

if (DIRECTORY_SEPARATOR !== '/') {
    echo 'Note: on Windows the password will be visible while you type it.' . PHP_EOL . PHP_EOL;
}

$email = ask('Email (required): ');
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
    exit('A valid email address (max 255 characters) is required.' . PHP_EOL);
}

$username = ask('Username (optional, press Enter to skip): ');
if ($username !== '' && !preg_match('/^[A-Za-z0-9._-]{3,100}$/', $username)) {
    exit('Username must be 3-100 characters: letters, numbers, dot, dash or underscore.' . PHP_EOL);
}

$firstName = ask('First name (optional): ');
$lastName  = ask('Last name (optional): ');
if (mb_strlen($firstName) > 100 || mb_strlen($lastName) > 100) {
    exit('Names must not exceed 100 characters.' . PHP_EOL);
}

$role = strtolower(ask('Role [admin/owner] (default admin): ')) ?: 'admin';
if (!in_array($role, ADMIN_ROLES, true)) {
    exit('Role must be one of: ' . implode(', ', ADMIN_ROLES) . PHP_EOL);
}

$password = ask('Password (min. 8 characters): ', true);
$confirm  = ask('Confirm password: ', true);

if (strlen($password) < 8) {
    exit('Password must be at least 8 characters.' . PHP_EOL);
}
if (!hash_equals($password, $confirm)) {
    exit('Passwords do not match.' . PHP_EOL);
}

$pdo = db();

$check = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = :email OR (:username <> \'\' AND username = :username2)');
$check->execute([':email' => $email, ':username' => $username, ':username2' => $username]);
if ((int) $check->fetchColumn() > 0) {
    exit('A user with this email or username already exists.' . PHP_EOL);
}

try {
    $pdo->beginTransaction();

    $userId = (string) $pdo->query('SELECT UUID()')->fetchColumn();

    $insertUser = $pdo->prepare(
        "INSERT INTO users (id, username, email, password_hash, first_name, last_name, status)
         VALUES (:id, :username, :email, :hash, :first_name, :last_name, 'active')"
    );
    $insertUser->execute([
        ':id'         => $userId,
        ':username'   => $username === '' ? null : $username,
        ':email'      => $email,
        ':hash'       => password_hash($password, PASSWORD_DEFAULT),
        ':first_name' => $firstName === '' ? null : $firstName,
        ':last_name'  => $lastName === '' ? null : $lastName,
    ]);

    $insertRole = $pdo->prepare('INSERT INTO user_roles (user_id, role) VALUES (:user_id, :role)');
    $insertRole->execute([':user_id' => $userId, ':role' => $role]);

    $log = $pdo->prepare(
        "INSERT INTO activity_logs (actor_user_id, action, entity_type, entity_id, description)
         VALUES (NULL, 'create', 'user', :id, :description)"
    );
    $log->execute([':id' => $userId, ':description' => 'Administrator created from the command line (' . $role . ')']);

    $pdo->commit();
} catch (PDOException $e) {
    $pdo->rollBack();
    exit('Could not create the account: ' . $e->getMessage() . PHP_EOL);
}

echo PHP_EOL . 'Done. Sign in with "' . ($username !== '' ? $username : $email) . '" and your password.' . PHP_EOL;
