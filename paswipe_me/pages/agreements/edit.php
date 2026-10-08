<?php
/**
 * Agreements - edit (not allowed once completed or cancelled).
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/agreement_functions.php';

$currentUser = require_admin();

$agreement = find_or_redirect(agreement_find(request_id()), 'pages/agreements/index.php', 'Agreement');

if (in_array($agreement['status'], AGREEMENT_LOCKED_STATUSES, true)) {
    flash('error', 'A ' . $agreement['status'] . ' agreement can no longer be edited.');
    redirect('pages/agreements/view.php', ['id' => $agreement['id']]);
}

$fields = agreement_fields($agreement);
$data = form_data_from_row($fields, $agreement);
$errors = [];

if (is_post()) {
    require_csrf('pages/agreements/edit.php?id=' . $agreement['id']);
    [$data, $errors] = agreement_validate($fields, $_POST, $agreement['id']);

    if (!$errors) {
        try {
            db_update('agreements', $data, $agreement['id']);
            log_activity('update', 'agreement', $agreement['id'], 'Updated agreement ' . $data['agreement_number'], array_intersect_key($agreement, $data), $data);
            flash('success', 'Agreement ' . $data['agreement_number'] . ' was updated.');
            redirect('pages/agreements/view.php', ['id' => $agreement['id']]);
        } catch (PDOException $e) {
            $errors['_form'] = db_error_message($e);
        }
    }
}

$pageTitle = 'Edit Agreement';
$activeNav = 'agreements';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb([
    'Agreements' => url('pages/agreements/index.php'),
    $agreement['agreement_number'] => url('pages/agreements/view.php', ['id' => $agreement['id']]),
    'Edit' => null,
]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Edit agreement</h2>
        <p class="page-header__subtitle">Set the status to Cancelled to void the agreement while keeping its history.</p>
    </div>
</section>

<?= render_record_form(url('pages/agreements/edit.php', ['id' => $agreement['id']]), $fields, $data, $errors, 'Save changes',
    url('pages/agreements/view.php', ['id' => $agreement['id']])) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
