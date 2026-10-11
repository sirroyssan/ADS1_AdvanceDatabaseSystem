<?php
/**
 * Writes audit entries to the schema's activity_logs table.
 * The schema notes say activity logs are append-only, so this only INSERTs.
 */

defined('APP_ROOT') or exit('No direct access.');

/** Actor of entries written by background jobs (stored as NULL, shown as "System"). */
const SYSTEM_ACTOR = '';

/**
 * @param string      $action     e.g. login, logout, create, update, delete
 * @param string      $entityType e.g. user, category
 * @param string|null $entityId   CHAR(36) id of the affected record
 */
function log_activity(
    string $action,
    string $entityType,
    ?string $entityId = null,
    ?string $description = null,
    ?array $oldValues = null,
    ?array $newValues = null,
    ?string $actorUserId = null
): void {
    $actorUserId ??= $_SESSION['user_id'] ?? null;
    // SYSTEM_ACTOR: background jobs, which act for no particular user.
    $actorUserId = $actorUserId === SYSTEM_ACTOR ? null : $actorUserId;

    try {
        $stmt = db()->prepare(
            'INSERT INTO activity_logs
                (actor_user_id, action, entity_type, entity_id, description,
                 old_values, new_values, ip_address, user_agent)
             VALUES
                (:actor, :action, :entity_type, :entity_id, :description,
                 :old_values, :new_values, :ip, :agent)'
        );
        $stmt->execute([
            ':actor'       => $actorUserId,
            ':action'      => substr($action, 0, 100),
            ':entity_type' => substr($entityType, 0, 100),
            ':entity_id'   => $entityId,
            ':description' => $description,
            ':old_values'  => $oldValues === null ? null : json_encode($oldValues, JSON_UNESCAPED_UNICODE),
            ':new_values'  => $newValues === null ? null : json_encode($newValues, JSON_UNESCAPED_UNICODE),
            ':ip'          => client_ip(),
            ':agent'       => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    } catch (PDOException $e) {
        // Logging must never break the user's action.
        error_log('[' . APP_NAME . '] Activity log failed: ' . $e->getMessage());
    }
}

/* ------------------------------------------------------------------
 * "Already done" markers for upgrades and the daily jobs. They are files in
 * storage/; when the web server may not write there (common on XAMPP for
 * macOS/Linux), the marker is kept as an activity log entry instead, so a
 * one-time upgrade never runs again and the daily jobs run once a day.
 * ------------------------------------------------------------------ */

/** Is the marker $file present (with this $content, when given)? */
function marker_exists(string $file, ?string $content = null): bool
{
    static $logged = null;
    $path = APP_ROOT . '/' . $file;
    if (is_file($path) && ($content === null || trim((string) @file_get_contents($path)) === $content)) {
        return true;
    }
    if ($logged === null) {
        $logged = [];
        try {
            foreach (db()->query("SELECT description FROM activity_logs WHERE action = 'marker'")->fetchAll(PDO::FETCH_COLUMN) as $d) {
                $logged[$d] = true;
            }
        } catch (PDOException $e) {
            error_log('[' . APP_NAME . '] Marker lookup failed: ' . $e->getMessage());
        }
    }

    return isset($logged[$file . ($content === null ? '' : '|' . $content)]);
}

/** Write the marker; falls back to the activity log when storage/ is not writable. */
function marker_write(string $file, ?string $content = null): void
{
    if (@file_put_contents(APP_ROOT . '/' . $file, $content ?? date('c'), LOCK_EX) === false) {
        log_activity('marker', 'system', null, $file . ($content === null ? '' : '|' . $content), null, null, SYSTEM_ACTOR);
    }
}
