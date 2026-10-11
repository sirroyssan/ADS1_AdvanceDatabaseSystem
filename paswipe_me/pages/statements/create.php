<?php
/**
 * Statements - record the bank's actual SOA for a card and billing period.
 * Opened from a period (card and cutoff known: prefilled) or from "Record SOA".
 * Card details and records come from the database; only the SOA figures are typed.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/statement_functions.php';

$currentUser = require_admin();

$presetCard = input_string($_GET, 'credit_card_id');
$card = is_valid_uuid($presetCard) ? db_row('SELECT * FROM credit_cards c WHERE c.id = :c AND ' . owner_scope('credit_cards', 'c'), [':c' => $presetCard]) : null;
$presetCutoff = preg_match('/^\d{4}-\d{2}-\d{2}$/', input_string($_GET, 'cutoff')) ? input_string($_GET, 'cutoff') : null;

$fields = statement_fields(null, $card !== null);
$defaults = [];
$period = null;
if ($card) {
    $period = card_period_containing(card_period_context($card), $presetCutoff ?? date('Y-m-d'));
    $defaults['cutoff_date'] = $period['end'];
    [$suggestedDue] = card_due_date($card, $period['end']);
    if ($suggestedDue) {
        $defaults['due_date'] = $suggestedDue;
    }
}
$data = form_defaults($fields, $defaults);
$errors = [];
$action = url('pages/statements/create.php', array_filter(['credit_card_id' => $card['id'] ?? null, 'cutoff' => $presetCutoff]));

if (is_post()) {
    require_csrf($action);
    [$data, $errors] = statement_validate($fields, $_POST, null, $card['id'] ?? null);

    if (!$errors) {
        try {
            $row = ['source' => 'manual', 'due_date_status' => 'manual', 'minimum_due_status' => 'manual', 'soa_recorded_at' => date('Y-m-d H:i:s')] + $data;
            if ($card) {
                $row['credit_card_id'] = $card['id'];
            }
            // The period may already have a prepared statement: the SOA is recorded into it (never a second row).
            $prepared = db_row(
                'SELECT id FROM credit_card_statements WHERE credit_card_id = :c AND soa_recorded_at IS NULL
                    AND (cutoff_date = :d OR :d2 BETWEEN COALESCE(period_start_date, cutoff_date) AND cutoff_date) ORDER BY cutoff_date = :d3 DESC LIMIT 1',
                [':c' => $row['credit_card_id'], ':d' => $data['cutoff_date'], ':d2' => $data['cutoff_date'], ':d3' => $data['cutoff_date']]
            );
            $id = db_transaction(static function () use ($row, $prepared) {
                if ($prepared) {
                    db_update('credit_card_statements', $row + ['updated_at' => date('Y-m-d H:i:s')], $prepared['id']);

                    return $prepared['id'];
                }
                $id = new_uuid();
                db_insert('credit_card_statements', ['id' => $id] + $row);

                return $id;
            });
            log_activity('create', 'statement', $id, 'Recorded SOA ' . format_money($data['total_amount_due']) . ' cut off ' . $data['cutoff_date'], null, $row);
            flash('success', 'SOA for ' . statement_month_label($data['cutoff_date']) . ' was recorded: ' . format_money($data['total_amount_due'])
                . ', minimum ' . format_money($data['minimum_amount_due']) . ', due ' . format_date($data['due_date']) . '.');
            redirect('pages/statements/view.php', ['id' => $id]);
        } catch (PDOException $e) {
            $errors['_form'] = str_contains($e->getMessage(), 'Duplicate')
                ? 'An SOA is already recorded for this card and period. Edit that one instead.' : db_error_message($e);
        }
    }
}

$pageTitle = 'Record SOA';
$activeNav = 'statements';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Statements' => url('pages/statements/index.php'), 'Record SOA' => null]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Record the bank's SOA</h2>
        <p class="page-header__subtitle">Type only what the bank's Statement of Account says. The card and the period's transactions are already in the system.</p>
    </div>
</section>

<?= render_record_form($action, $fields, $data, $errors, 'Save SOA', url('pages/statements/index.php', array_filter(['card' => $card['id'] ?? null])),
    $card ? statement_card_context($card, $period) : '') ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
