<?php
/**
 * Reset test data - download one backup batch as restorable SQL (own batches only).
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once APP_ROOT . '/includes/data_reset.php';

require_admin();

$batch = input_string($_GET, 'batch');
$sql = preg_match('/^reset_\d{8}_\d{6}$/', $batch) ? data_reset_export_sql(owner_id(), $batch) : null;
if ($sql === null) {
    flash('error', 'Backup not found.');
    redirect('pages/data_reset/index.php');
}

header('Content-Type: application/sql; charset=utf-8');
header('Content-Disposition: attachment; filename="pa_kaskas_' . $batch . '.sql"');
header('Content-Length: ' . strlen($sql));
echo $sql;
