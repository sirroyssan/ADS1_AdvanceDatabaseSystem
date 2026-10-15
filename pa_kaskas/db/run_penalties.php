<?php
/**
 * Charge the automatic monthly penalties now (command line only).
 *
 * The website already runs this check once a day on the first page load.
 * Schedule this script if you want it to run even when nobody opens the
 * site, e.g. Windows Task Scheduler, daily at 1:00 AM:
 *     C:\xampp\php\php.exe C:\xampp\htdocs\pa_kaskas\db\run_penalties.php
 * Linux / macOS cron:
 *     0 1 * * * /opt/lampp/bin/php /opt/lampp/htdocs/pa_kaskas/db/run_penalties.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';

[$checked, $created] = penalty_run_all();
marker_write(PENALTY_RUN_MARKER, date('Y-m-d'));
log_activity('penalty_run', 'system', null, 'Penalty check (command line): ' . $created . ' new penalties on ' . $checked . ' open obligation(s)');

echo 'Checked ' . $checked . ' open obligation(s); charged ' . $created . ' new penalt' . ($created === 1 ? 'y' : 'ies') . '.' . PHP_EOL;
