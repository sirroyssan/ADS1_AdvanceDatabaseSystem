<?php
/**
 * Installment Rules - view details and the plans created with the rule.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/rule_functions.php';

$currentUser = require_admin();

$rule = find_or_redirect(installment_rule_find(request_id()), 'pages/installment_rules/index.php', 'Installment rule');
$usage = installment_rule_usage($rule['id']);
$card = $rule['credit_card_id'] ? db_row('SELECT card_nickname, issuer, last_four FROM credit_cards WHERE id = :id', [':id' => $rule['credit_card_id']]) : null;

$plans = db_all(
    'SELECT p.id, p.number_of_installments, p.installment_amount, t.transaction_number, t.transaction_date, t.amount
       FROM installment_plans p JOIN transactions t ON t.id = p.transaction_id
      WHERE p.installment_rule_id = :id AND ' . owner_scope('installment_plans', 'p') . '
      ORDER BY t.transaction_date DESC LIMIT 10',
    [':id' => $rule['id']]
);

$pageTitle = 'Installment Rule Details';
$activeNav = 'installment_rules';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Installment Rules' => url('pages/installment_rules/index.php'), $rule['name'] => null]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title"><?= e($rule['name']) ?></h2>
        <p class="page-header__subtitle"><?= e(installment_rule_summary($rule)) ?></p>
    </div>
    <div class="page-header__actions no-print">
        <a href="<?= e(url('pages/installment_rules/index.php')) ?>" class="btn btn--outline"><?= icon('arrow-left') ?> <span>Back</span></a>
        <a data-modal-form href="<?= e(url('pages/installment_rules/edit.php', ['id' => $rule['id']])) ?>" class="btn btn--primary"><?= icon('edit') ?> <span>Edit</span></a>
    </div>
</section>

<div class="detail-layout">
    <section class="panel">
        <header class="panel__header"><h3>Record details</h3></header>
        <?= render_details([
            'Rule name'       => e($rule['name']),
            'Card'            => $card ? e($card['card_nickname'] . ' — ' . $card['issuer'] . ' •••• ' . $card['last_four']) : '<span class="text-muted">Any card of the bank</span>',
            'Bank'            => $rule['issuer'] ? e($rule['issuer']) : '<span class="text-muted">Any bank</span>',
            'Months offered'  => e(implode(', ', installment_rule_terms($rule))),
            'Amount range'    => e(($rule['min_amount'] !== null ? format_money($rule['min_amount']) : 'Any') . ' – ' . ($rule['max_amount'] !== null ? format_money($rule['max_amount']) : 'any')),
            'Interest'        => e(INSTALLMENT_METHODS[$rule['interest_method']] ?? $rule['interest_method']),
            'Interest rate'   => $rule['interest_rate'] !== null ? e(rtrim(rtrim((string) $rule['interest_rate'], '0'), '.') . '%') : show(null),
            'Processing fee'  => e(INSTALLMENT_FEE_TYPES[$rule['fee_type']] ?? $rule['fee_type']) . ($rule['fee_value'] !== null ? ': ' . e(rtrim(rtrim((string) $rule['fee_value'], '0'), '.')) . ($rule['fee_type'] === 'percent' ? '%' : '') : ''),
            'Fee billed'      => e(INSTALLMENT_FEE_BILLING[$rule['fee_billing']] ?? $rule['fee_billing']),
            'Effective from'  => show($rule['effective_from'] ? format_date($rule['effective_from']) : null),
            'Effective until' => show($rule['effective_until'] ? format_date($rule['effective_until']) : null),
            'Status'          => active_badge($rule['is_active']),
            'Notes'           => show($rule['notes']),
            'Last updated'    => e(format_datetime($rule['updated_at'])),
        ]) ?>
    </section>

    <div class="detail-side">
        <section class="panel">
            <header class="panel__header"><h3>Plans created with this rule</h3></header>
            <?php if (!$plans): ?>
                <p class="empty-inline">No installment plan uses this rule yet.</p>
            <?php else: ?>
                <ul class="child-list">
                    <?php foreach ($plans as $p): ?>
                        <li>
                            <span><a href="<?= e(url('pages/installment_plans/view.php', ['id' => $p['id']])) ?>"><?= e($p['transaction_number']) ?></a>
                                · <?= e(format_date($p['transaction_date'])) ?> · <?= e($p['number_of_installments']) ?> × <?= e(format_money($p['installment_amount'])) ?></span>
                            <strong><?= e(format_money($p['amount'])) ?></strong>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="panel panel--danger no-print">
            <header class="panel__header"><h3>Delete or deactivate</h3></header>
            <?php if ($usage['total'] > 0): ?>
                <p class="field-help">Used by <?= e(usage_text($usage)) ?>, so it cannot be deleted. Those plans keep their terms.</p>
                <?php if ((int) $rule['is_active'] === 1): ?>
                    <?= post_button(url('pages/installment_rules/delete.php'), ['id' => $rule['id'], 'action' => 'deactivate'], 'Deactivate', 'power', 'btn btn--warning btn--block',
                        'Deactivate "' . $rule['name'] . '"? It will no longer be offered on new installment purchases.', false, 'Deactivate rule?') ?>
                <?php endif; ?>
            <?php else: ?>
                <p class="field-help">Deleting is permanent.</p>
                <?= post_button(url('pages/installment_rules/delete.php'), ['id' => $rule['id'], 'action' => 'delete'], 'Delete rule', 'trash', 'btn btn--danger btn--block',
                    'Are you sure you want to delete the rule "' . $rule['name'] . '"? This cannot be undone.') ?>
            <?php endif; ?>
        </section>
    </div>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
