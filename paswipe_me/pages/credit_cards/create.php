<?php
/**
 * Credit Cards - create.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/credit_card_functions.php';

$currentUser = require_admin();

$fields = credit_card_fields();
$data = form_defaults($fields);
$errors = [];

if (is_post()) {
    require_csrf('pages/credit_cards/create.php');
    [$data, $errors] = credit_card_validate($fields, $_POST);

    if (!$errors) {
        try {
            $id = new_uuid();
            $lineNotes = credit_card_save($id, $data);
            log_activity('create', 'credit_card', $id, 'Added card ' . $data['card_nickname'] . ' •••• ' . $data['last_four'], null, $data);
            foreach ($lineNotes as $note) {
                flash('info', $note);
            }
            flash('success', 'Card "' . $data['card_nickname'] . '" was added successfully.');
            redirect('pages/credit_cards/view.php', ['id' => $id]);
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

$pageTitle = 'Add Credit Card';
$activeNav = 'credit_cards';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Credit Cards' => url('pages/credit_cards/index.php'), 'Add' => null]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Add credit card</h2>
        <p class="page-header__subtitle">For security, only the last four digits are stored. Never enter the full number, CVV or PIN.</p>
    </div>
</section>

<?= render_record_form(url('pages/credit_cards/create.php'), $fields, $data, $errors, 'Save card', url('pages/credit_cards/index.php')) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
