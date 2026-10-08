<?php
/**
 * Demand Letter Templates - view details and body.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/template_functions.php';

$currentUser = require_admin();

$template = find_or_redirect(template_find(request_id()), 'pages/letter_templates/index.php', 'Template');
$usage = template_usage($template['id']);

$pageTitle = 'Demand Letter Template';
$activeNav = 'letter_templates';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Demand Letter Templates' => url('pages/letter_templates/index.php'), $template['name'] => null]) ?>

<div class="print-header">
    <p class="print-header__system"><?= e(APP_NAME) ?></p>
    <h2 class="print-header__title"><?= e($template['name']) ?> (v<?= e($template['version']) ?>)</h2>
    <p class="print-header__meta">Printed: <?= e(date('F j, Y g:i A')) ?></p>
</div>

<section class="page-header">
    <div>
        <h2 class="page-header__title"><?= e($template['name']) ?></h2>
        <p class="page-header__subtitle">Version <?= e($template['version']) ?> · <?= active_badge($template['is_active']) ?></p>
    </div>
    <div class="page-header__actions no-print">
        <a href="<?= e(url('pages/letter_templates/index.php')) ?>" class="btn btn--outline"><?= icon('arrow-left') ?> <span>Back</span></a>
        <button type="button" class="btn btn--outline" data-print><?= icon('printer') ?> <span>Print</span></button>
        <a href="<?= e(url('pages/letter_templates/edit.php', ['id' => $template['id']])) ?>" class="btn btn--primary"><?= icon('edit') ?> <span>Edit</span></a>
    </div>
</section>

<div class="detail-layout">
    <section class="panel">
        <header class="panel__header"><h3>Template body</h3></header>
        <div class="document-body"><?= e($template['template_body']) ?></div>
    </section>

    <div class="detail-side">
        <section class="panel">
            <header class="panel__header"><h3>Details</h3></header>
            <?= render_details([
                'Used by'      => e($usage['demand letters']) . ' demand letter(s)',
                'Created'      => e(format_datetime($template['created_at'])),
                'Last updated' => e(format_datetime($template['updated_at'])),
            ]) ?>
        </section>

        <section class="panel panel--danger no-print">
            <header class="panel__header"><h3>Delete or deactivate</h3></header>
            <?php if ($usage['total'] > 0): ?>
                <p class="field-help">Used by <?= e(usage_text($usage)) ?>, so it cannot be deleted.</p>
                <?php if ((int) $template['is_active'] === 1): ?>
                    <?= post_button(url('pages/letter_templates/delete.php'), ['id' => $template['id'], 'action' => 'deactivate'], 'Deactivate', 'power', 'btn btn--warning btn--block',
                        'Deactivate this template? It will no longer be offered for new demand letters.', false, 'Deactivate template?') ?>
                <?php endif; ?>
            <?php else: ?>
                <p class="field-help">Deleting is permanent.</p>
                <?= post_button(url('pages/letter_templates/delete.php'), ['id' => $template['id'], 'action' => 'delete'], 'Delete template', 'trash', 'btn btn--danger btn--block',
                    'Are you sure you want to delete the template "' . $template['name'] . '"? This cannot be undone.') ?>
            <?php endif; ?>
        </section>
    </div>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
