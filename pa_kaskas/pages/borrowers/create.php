<?php
/**
 * Borrowers - create. The borrower number is generated when left blank.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/borrower_functions.php';

$currentUser = require_admin();

$fields = borrower_fields();
$data = form_defaults($fields);
$errors = [];

if (is_post()) {
    require_csrf('pages/borrowers/create.php');
    [$data, $errors] = validate_fields($fields, $_POST);

    // Same name, mobile or email as another borrower: probably added twice. Two people can share a
    // name, so the owner may still save after ticking "Save anyway".
    $duplicates = $errors ? [] : borrower_duplicates($data, null);
    if ($duplicates && input_string($_POST, 'confirm_duplicate') !== '1') {
        $errors['_form'] = 'This borrower may already be recorded: ' . borrower_duplicate_text($duplicates)
            . '. Use the existing record, or if this really is a different person, tick "Save anyway" below and save again.';
        $fields['confirm_duplicate'] = ['label' => 'Duplicate check', 'type' => 'bool', 'switch_label' => 'Save anyway: this is a different person', 'full' => true];
    }


    if (!$errors) {
        try {
            $id = new_uuid();
            $data['borrower_number'] ??= generate_reference('BRW', 'borrowers', 'borrower_number');
            db_insert('borrowers', ['id' => $id] + $data + ['owner_user_id' => owner_id(), 'created_by' => $currentUser['id'], 'updated_by' => $currentUser['id']]);
            log_activity('create', 'borrower', $id, 'Created borrower ' . $data['borrower_number'] . ' ' . $data['first_name'] . ' ' . $data['last_name'], null, $data);
            flash('success', 'Borrower ' . $data['first_name'] . ' ' . $data['last_name'] . ' was added successfully.');
            redirect('pages/borrowers/view.php', ['id' => $id]);
        } catch (PDOException $e) {
            $errors['_form'] = db_error_message($e);
        }
    }
}

$pageTitle = 'Add Borrower';
$activeNav = 'borrowers';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Borrowers' => url('pages/borrowers/index.php'), 'Add' => null]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Add borrower</h2>
        <p class="page-header__subtitle">Addresses, contacts and a sign-in account can be added on the borrower's page after saving.</p>
    </div>
</section>

<?= render_record_form(url('pages/borrowers/create.php'), $fields, $data, $errors, 'Save borrower', url('pages/borrowers/index.php')) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
