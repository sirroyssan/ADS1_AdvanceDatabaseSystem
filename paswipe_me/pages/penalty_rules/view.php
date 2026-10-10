<?php
/**
 * Penalty Rules - view details and where the rule was applied.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/penalty_rule_functions.php';

$currentUser = require_admin();

$rule = find_or_redirect(penalty_rule_find(request_id()), 'pages/penalty_rules/index.php', 'Penalty rule');
$usage = penalty_rule_usage($rule['id']);

$applied = db_all(
    'SELECT ap.applied_date, ap.amount, ap.remaining_amount, o.id AS obligation_id, o.obligation_number, b.first_name, b.last_name
       FROM applied_penalties ap
       JOIN borrower_obligations o ON o.id = ap.obligation_id
       JOIN borrowers b ON b.id = o.borrower_id
      WHERE ap.penalty_rule_id = :id
      ORDER BY ap.applied_date DESC LIMIT 10',
    [':id' => $rule['id']]
);

$pageTitle = 'Penalty Rule Details';
$activeNav = 'penalty_rules';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Penalty Rules' => url('pages/penalty_rules/index.php'), $rule['name'] => null]) ?>

<div class="print-header">
    <p class="print-header__system"><?= e(APP_NAME) ?></p>
    <h2 class="print-header__title">Penalty Rule</h2>
    <p class="print-header__meta">Printed: <?= e(date('F j, Y g:i A')) ?></p>
</div>

<section class="page-header">
    <div>
        <h2 class="page-header__title"><?= e($rule['name']) ?></h2>
        <p class="page-header__subtitle"><?= e(penalty_rule_summary($rule)) ?></p>
    </div>
    <div class="page-header__actions no-print">
        <a href="<?= e(url('pages/penalty_rules/index.php')) ?>" class="btn btn--outline"><?= icon('arrow-left') ?> <span>Back</span></a>
        <button type="button" class="btn btn--outline" data-print><?= icon('printer') ?> <span>Print</span></button>
        <a data-modal-form href="<?= e(url('pages/penalty_rules/edit.php', ['id' => $rule['id']])) ?>" class="btn btn--primary"><?= icon('edit') ?> <span>Edit</span></a>
    </div>
</section>

<div class="detail-layout">
    <section class="panel">
        <header class="panel__header"><h3>Record details</h3></header>
        <?= render_details([
            'Rule name'        => e($rule['name']),
            'Bank'             => $rule['applies_to_issuer'] ? e($rule['applies_to_issuer']) : '<span class="text-muted">None — never charged automatically</span>',
            'Charges from'     => e(format_date(max((string) $rule['effective_from'], substr($rule['updated_at'], 0, 10)))) . ' <span class="text-muted">(effective date or last change, whichever is later)</span>',
            'Calculation type' => e(humanize($rule['calculation_type'])),
            'Basis'            => e($rule['basis']),
            'Rate (%)'         => show($rule['rate']),
            'Fixed amount'     => show_money($rule['fixed_amount']),
            'Maximum charge'   => show_money($rule['maximum_charge']),
            'Frequency'        => show($rule['frequency']),
            'Grace period'     => e($rule['grace_period_days']) . ' day(s)',
            'Effective from'   => show($rule['effective_from'] ? format_date($rule['effective_from']) : null),
            'Effective until'  => show($rule['effective_until'] ? format_date($rule['effective_until']) : null),
            'Status'           => active_badge($rule['is_active']),
            'Created'          => e(format_datetime($rule['created_at'])),
            'Last updated'     => e(format_datetime($rule['updated_at'])),
        ]) ?>
    </section>

    <div class="detail-side">
        <section class="panel">
            <header class="panel__header"><h3>Recently applied</h3></header>
            <?php if (!$applied): ?>
                <p class="empty-inline">Not applied to any obligation yet.</p>
            <?php else: ?>
                <ul class="child-list">
                    <?php foreach ($applied as $a): ?>
                        <li>
                            <span><a href="<?= e(url('pages/obligations/view.php', ['id' => $a['obligation_id']])) ?>"><?= e($a['obligation_number']) ?></a>
                                · <?= e(borrower_name($a)) ?> · <?= e(format_date($a['applied_date'])) ?></span>
                            <strong><?= e(format_money($a['amount'])) ?></strong>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="panel panel--danger no-print">
            <header class="panel__header"><h3>Delete or deactivate</h3></header>
            <?php if ($usage['total'] > 0): ?>
                <p class="field-help">Used by <?= e(usage_text($usage)) ?>, so it cannot be deleted.</p>
                <?php if ((int) $rule['is_active'] === 1): ?>
                    <?= post_button(url('pages/penalty_rules/delete.php'), ['id' => $rule['id'], 'action' => 'deactivate'], 'Deactivate', 'power', 'btn btn--warning btn--block',
                        'Deactivate "' . $rule['name'] . '"? It can no longer be applied to new penalties.', false, 'Deactivate rule?') ?>
                <?php endif; ?>
            <?php else: ?>
                <p class="field-help">Deleting is permanent.</p>
                <?= post_button(url('pages/penalty_rules/delete.php'), ['id' => $rule['id'], 'action' => 'delete'], 'Delete rule', 'trash', 'btn btn--danger btn--block',
                    'Are you sure you want to delete the rule "' . $rule['name'] . '"? This cannot be undone.') ?>
            <?php endif; ?>
        </section>
    </div>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
