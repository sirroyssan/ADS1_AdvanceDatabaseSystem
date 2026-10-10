<?php
/**
 * Credit Cards - edit.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/credit_card_functions.php';

$currentUser = require_admin();

$card = find_or_redirect(credit_card_find(request_id()), 'pages/credit_cards/index.php', 'Credit card');
// Once statements were generated, a change to the billing settings must be confirmed.
$hasGenerated = (int) db_value("SELECT COUNT(*) FROM credit_card_statements WHERE credit_card_id = :c AND source = 'generated'", [':c' => $card['id']]) > 0;
$fields = credit_card_fields($card, $hasGenerated);
$data = form_data_from_row($fields, ['issuer' => bank_canonical($card['issuer'])] + $card + ['opening_balance' => card_opening_balance($card['id'])['amount'] ?? '0.00']);
$errors = [];

if (is_post()) {
    require_csrf('pages/credit_cards/edit.php?id=' . $card['id']);
    [$data, $errors] = credit_card_validate($fields, $_POST, $card['id']);
    $billingChanged = array_filter(CARD_BILLING_SETTINGS, static fn ($k) => (string) ($card[$k] ?? '') !== (string) ($data[$k] ?? ''));
    if ($hasGenerated && $billingChanged && empty($data['confirm_billing_change'])) {
        $errors['confirm_billing_change'] = 'You changed ' . implode(', ', array_map(static fn ($k) => strtolower($fields[$k]['label']), $billingChanged))
            . '. Tick the box to confirm: generated statements stay as they are and the new settings apply from the current cycle.';
    }
    unset($data['confirm_billing_change']);

    if (!$errors) {
        try {
            db_transaction(static function () use ($data, $card, $billingChanged) {
                if (array_intersect($billingChanged, ['minimum_due_percent', 'minimum_due_floor'])) {
                    // Keep the minimum due already worked out on closed statements.
                    db_exec("UPDATE credit_card_statements SET minimum_due_status = 'manual'
                              WHERE credit_card_id = :c AND source = 'generated' AND minimum_due_status = 'rule'", [':c' => $card['id']]);
                }
                if (in_array('billing_cycle_day', $billingChanged, true) && $data['billing_cycle_day']) {
                    // The new cutoff day starts with the cycle that is open now: it runs from where the last
                    // statement ended to the next new cutoff day from today (never a period already past).
                    $start = card_next_period_start($card);
                    if ($start) {
                        $end = cutoff_on_or_after((int) $data['billing_cycle_day'], max($start, date('Y-m-d')));
                        db_exec('DELETE FROM card_billing_cycles WHERE credit_card_id = :c AND cycle_start_date >= :s', [':c' => $card['id'], ':s' => $start]);
                        db_insert('card_billing_cycles', ['id' => new_uuid(), 'credit_card_id' => $card['id'], 'cycle_start_date' => $start, 'cycle_end_date' => $end]);
                    }
                }
                credit_card_save(null, $data, $card['id']);
            });
            if ($billingChanged) {
                log_activity('billing_change', 'credit_card', $card['id'], 'Billing settings changed: ' . implode(', ', $billingChanged),
                    array_intersect_key($card, array_flip($billingChanged)), array_intersect_key($data, array_flip($billingChanged)));
                if ($hasGenerated) {
                    $open = card_open_period(credit_card_find($card['id']));
                    flash('info', 'Billing settings changed. Generated statements were not changed' . ($open ? '; the current cycle now runs ' . format_date($open[0]) . ' – ' . format_date($open[1]) : '') . '.');
                }
            }
            log_activity('update', 'credit_card', $card['id'], 'Updated card ' . $data['card_nickname'], array_intersect_key($card, $data), $data);
            flash('success', 'Card "' . $data['card_nickname'] . '" was updated successfully.');
            redirect('pages/credit_cards/view.php', ['id' => $card['id']]);
        } catch (PDOException $e) {
            $errors['_form'] = db_error_message($e);
        }
    }
}

$pageTitle = 'Edit Credit Card';
$activeNav = 'credit_cards';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb([
    'Credit Cards' => url('pages/credit_cards/index.php'),
    credit_card_label($card) => url('pages/credit_cards/view.php', ['id' => $card['id']]),
    'Edit' => null,
]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Edit credit card</h2>
        <p class="page-header__subtitle">Last updated <?= e(format_datetime($card['updated_at'])) ?></p>
    </div>
</section>

<?= render_record_form(url('pages/credit_cards/edit.php', ['id' => $card['id']]), $fields, $data, $errors, 'Save changes',
    url('pages/credit_cards/view.php', ['id' => $card['id']])) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
