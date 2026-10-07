<?php
/**
 * Writes audit entries to the schema's activity_logs table.
 * The schema notes say activity logs are append-only, so this only INSERTs.
 */

defined('APP_ROOT') or exit('No direct access.');

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
