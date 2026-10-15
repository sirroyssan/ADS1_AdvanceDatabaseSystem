<?php
/**
 * Borrowers - edit.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/borrower_functions.php';

$currentUser = require_admin();

$borrower = find_or_redirect(borrower_find(request_id()), 'pages/borrowers/index.php', 'Borrower');
$fields = borrower_fields($borrower);
$fields['borrower_number']['required'] = true;
unset($fields['borrower_number']['help']);
$data = form_data_from_row($fields, $borrower);
$errors = [];

if (is_post()) {
    require_csrf('pages/borrowers/edit.php?id=' . $borrower['id']);
    [$data, $errors] = validate_fields($fields, $_POST, $borrower['id']);

    // Same name, mobile or email as another borrower: probably added twice. Two people can share a
    // name, so the owner may still save after ticking "Save anyway".
    // Only when the name, mobile or email changed, so an existing pair does not block every edit.
    $identity = ['first_name', 'last_name', 'primary_mobile', 'primary_email'];
    $changed = array_intersect_key($data, array_flip($identity)) != array_intersect_key($borrower, array_flip($identity));
    $duplicates = ($errors || !$changed) ? [] : borrower_duplicates($data, $borrower['id']);
    if ($duplicates && input_string($_POST, 'confirm_duplicate') !== '1') {
        $errors['_form'] = 'This borrower may already be recorded: ' . borrower_duplicate_text($duplicates)
            . '. Use the existing record, or if this really is a different person, tick "Save anyway" below and save again.';
        $fields['confirm_duplicate'] = ['label' => 'Duplicate check', 'type' => 'bool', 'switch_label' => 'Save anyway: this is a different person', 'full' => true];
    }


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
