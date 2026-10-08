<?php
/**
 * Borrowers - edit.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/borrower_functions.php';

$currentUser = require_admin();

$borrower = find_or_redirect(borrower_find(request_id()), 'pages/borrowers/index.php', 'Borrower');
$fields = borrower_fields();
$fields['borrower_number']['required'] = true;
unset($fields['borrower_number']['help']);
$data = form_data_from_row($fields, $borrower);
$errors = [];

if (is_post()) {
    require_csrf('pages/borrowers/edit.php?id=' . $borrower['id']);
    [$data, $errors] = validate_fields($fields, $_POST, $borrower['id']);

    if (!$errors) {
        try {
            db_update('borrowers', $data + ['updated_by' => $currentUser['id']], $borrower['id']);
            log_activity('update', 'borrower', $borrower['id'], 'Updated borrower ' . $data['borrower_number'], array_intersect_key($borrower, $data), $data);
            flash('success', 'Borrower ' . $data['first_name'] . ' ' . $data['last_name'] . ' was updated successfully.');
            redirect('pages/borrowers/view.php', ['id' => $borrower['id']]);
        } catch (PDOException $e) {
            $errors['_form'] = db_error_message($e);
        }
    }
}

$pageTitle = 'Edit Borrower';
$activeNav = 'borrowers';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb([
    'Borrowers' => url('pages/borrowers/index.php'),
    borrower_full_name($borrower) => url('pages/borrowers/view.php', ['id' => $borrower['id']]),
    'Edit' => null,
]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Edit borrower</h2>
        <p class="page-header__subtitle">Last updated <?= e(format_datetime($borrower['updated_at'])) ?><?= $borrower['updated_by_name'] ? ' by ' . e($borrower['updated_by_name']) : '' ?></p>
    </div>
</section>

<?= render_record_form(url('pages/borrowers/edit.php', ['id' => $borrower['id']]), $fields, $data, $errors, 'Save changes',
    url('pages/borrowers/view.php', ['id' => $borrower['id']])) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
