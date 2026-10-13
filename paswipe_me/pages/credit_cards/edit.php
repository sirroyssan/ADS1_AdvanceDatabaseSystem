<?php
/**
 * Credit Cards - edit.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/credit_card_functions.php';

$currentUser = require_admin();

$card = find_or_redirect(credit_card_find(request_id()), 'pages/credit_cards/index.php', 'Credit card');
$fields = credit_card_fields($card);
$line = $card['credit_line_id'] ? db_row('SELECT * FROM credit_lines WHERE id = :id', [':id' => $card['credit_line_id']]) : null;
// A card on a shared line shows the line's limit (its own cap is the sub-limit, if any).
$data = form_data_from_row($fields, ['issuer' => bank_canonical($card['issuer']), 'credit_line' => $card['credit_line_id'] ?? '']
    + ($line ? ['credit_limit' => $line['credit_limit']] : []) + $card + ['opening_balance' => card_opening_balance($card['id'])['amount'] ?? '0.00']);
$errors = [];

if (is_post()) {
    require_csrf('pages/credit_cards/edit.php?id=' . $card['id']);
    [$data, $errors] = credit_card_validate($fields, $_POST, $card['id']);
    // Numbers compare by value (3.0000 = 3), the rule by text.
    $same = static fn ($a, $b) => is_numeric($a) && is_numeric($b) ? abs((float) $a - (float) $b) < 0.00001 : (string) $a === (string) $b;
    $billingChanged = array_filter(CARD_BILLING_SETTINGS, static fn ($k) => !$same($card[$k] ?? '', $data[$k] ?? ''));

    if (!$errors) {
        try {
            $lineNotes = credit_card_save(null, $data, $card['id']);
            if ($billingChanged) {
                log_activity('billing_change', 'credit_card', $card['id'], 'Billing settings changed: ' . implode(', ', $billingChanged),
                    array_intersect_key($card, array_flip($billingChanged)), array_intersect_key($data, array_flip($billingChanged)));
                flash('info', 'Billing settings changed. Recorded SOAs keep their own periods; other periods now follow the new settings.');
            }
            log_activity('update', 'credit_card', $card['id'], 'Updated card ' . $data['card_nickname'], array_intersect_key($card, $data), $data);
            foreach ($lineNotes as $note) {
                flash('info', $note);
            }
            flash('success', 'Card "' . $data['card_nickname'] . '" was updated successfully.');
            redirect('pages/credit_cards/view.php', ['id' => $card['id']]);
        } catch (DomainException $e) {
            $errors[match (true) {
                str_starts_with($e->getMessage(), 'You already have this card') => 'last_four',
                str_contains($e->getMessage(), 'sub-limit') => 'sub_limit',
                default => '_form',
            }] = $e->getMessage();
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
