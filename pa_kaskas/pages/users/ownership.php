<?php
/**
 * Users & Roles - Ownership review: records the multi-user upgrade could not
 * give an owner with certainty (owner_user_id IS NULL, see includes/multiuser.php).
 * Until confirmed here, nobody sees them. Only names are shown - no amounts.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/user_functions.php';

$currentUser = require_system_admin();
$owners = multiuser_owner_options();

if (is_post()) {
    require_csrf('pages/users/ownership.php');
    $owner = input_string($_POST, 'owner_user_id');
    $done = 0;
    foreach ((array) ($_POST['records'] ?? []) as $key) {
        [$table, $id] = array_pad(explode(':', (string) $key, 2), 2, '');
        $done += multiuser_assign($table, $id, $owner) ? 1 : 0;
    }
    if (!isset($owners[$owner])) {
        flash('error', 'Choose the owner of the selected records.');
    } elseif ($done === 0) {
        flash('error', 'Select at least one record that still has no owner.');
    } else {
        flash('success', $done . ' record(s) assigned to ' . $owners[$owner] . '.');
    }
    redirect('pages/users/ownership.php');
}

$records = multiuser_unassigned_records();
$total = array_sum(array_map('count', $records));
// Where the upgrade put its backup (from its activity log entry).
$log = (string) db_value("SELECT description FROM activity_logs WHERE action = 'multiuser_upgrade' ORDER BY created_at LIMIT 1");
$backup = preg_match('/Backup: (.+?)\. [A-Z]/', $log, $m) ? $m[1] : '';
$backup = str_starts_with($backup, 'before_') ? 'storage/backups/' . $backup : $backup;   // entries written before the location was logged in full

$pageTitle = 'Ownership Review';
$activeNav = 'users';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Users & Roles' => url('pages/users/index.php'), 'Ownership review' => null]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Ownership review</h2>
        <p class="page-header__subtitle">Records from before multi-user accounts whose owner could not be worked out for certain. Nobody can see them until you confirm who they belong to.</p>
    </div>
    <div class="page-header__actions no-print">
        <a href="<?= e(url('pages/users/index.php')) ?>" class="btn btn--outline"><?= icon('arrow-left') ?> <span>Back</span></a>
    </div>
</section>

<?php if ($backup !== ''): ?>
    <p class="field-help">A backup was made before the upgrade: <code><?= e($backup) ?></code></p>
<?php endif; ?>

<?php if ($total === 0): ?>
    <section class="panel"><p class="empty-inline">Every record has an owner. Nothing to review.</p></section>
<?php else: ?>
    <form method="post" action="<?= e(url('pages/users/ownership.php')) ?>" class="panel">
        <?= csrf_field() ?>
        <?php foreach ($records as $table => $rows): ?>
            <?php if (!$rows) { continue; } ?>
            <h3 class="form-section"><?= e(MULTIUSER_OWNED_TABLES[$table]) ?> <span class="chip"><?= e(count($rows)) ?></span></h3>
            <?php foreach ($rows as $row): ?>
                <label class="check-option"><input type="checkbox" name="records[]" value="<?= e($table . ':' . $row['id']) ?>">
                    <span><strong><?= e($row['label']) ?></strong></span></label>
            <?php endforeach; ?>
        <?php endforeach; ?>
        <div class="form-grid">
            <?= render_field('owner_user_id', ['label' => 'Owner of the selected records', 'type' => 'select', 'options' => $owners, 'required' => true, 'empty_label' => 'Select owner'], '', null) ?>
        </div>
        <div class="form-actions"><button type="submit" class="btn btn--primary"><?= icon('check') ?> <span>Assign owner</span></button></div>
    </form>
<?php endif; ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
