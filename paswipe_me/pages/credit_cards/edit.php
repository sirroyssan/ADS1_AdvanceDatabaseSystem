<?php
/**
 * Credit Cards - edit.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/credit_card_functions.php';

$currentUser = require_admin();

$card = find_or_redirect(credit_card_find(request_id()), 'pages/credit_cards/index.php', 'Credit card');
$fields = credit_card_fields($card);
$data = form_data_from_row($fields, $card + ['opening_balance' => card_opening_balance($card['id'])['amount'] ?? '0.00']);
$errors = [];

if (is_post()) {
    require_csrf('pages/credit_cards/edit.php?id=' . $card['id']);
    [$data, $errors] = credit_card_validate($fields, $_POST, $card['id']);

    if (!$errors) {
        try {
            credit_card_save(null, $data, $card['id']);
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
