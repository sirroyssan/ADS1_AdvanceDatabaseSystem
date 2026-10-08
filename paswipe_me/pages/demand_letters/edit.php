<?php
/**
 * Demand Letters - edit while draft or approved. Editing an approved letter
 * returns it to draft so it is approved again before sending.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/letter_functions.php';

$currentUser = require_admin();

$letter = find_or_redirect(letter_find(request_id()), 'pages/demand_letters/index.php', 'Demand letter');

if (!in_array($letter['status'], LETTER_EDITABLE_STATUSES, true)) {
    flash('error', 'A letter that is ' . humanize($letter['status']) . ' can no longer be edited.');
    redirect('pages/demand_letters/view.php', ['id' => $letter['id']]);
}

$fields = letter_fields($letter);
$data = form_data_from_row($fields, $letter);
$errors = [];

if (is_post()) {
    require_csrf('pages/demand_letters/edit.php?id=' . $letter['id']);
    [$data, $errors] = letter_validate($fields, $_POST, $letter['id']);

    if (!$errors) {
        try {
            db_update('demand_letters', $data + ['status' => 'draft'], $letter['id']);
            log_activity('update', 'demand_letter', $letter['id'], 'Edited demand letter ' . $data['letter_number']);
            flash('success', 'Letter ' . $data['letter_number'] . ' was updated' . ($letter['status'] === 'approved' ? ' and returned to draft for approval.' : '.'));
            redirect('pages/demand_letters/view.php', ['id' => $letter['id']]);
        } catch (PDOException $e) {
            $errors['_form'] = db_error_message($e);
        }
    }
}

$pageTitle = 'Edit Demand Letter';
$activeNav = 'demand_letters';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb([
    'Demand Letters' => url('pages/demand_letters/index.php'),
    $letter['letter_number'] => url('pages/demand_letters/view.php', ['id' => $letter['id']]),
    'Edit' => null,
]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Edit demand letter</h2>
        <p class="page-header__subtitle"><?= $letter['status'] === 'approved' ? 'Saving returns the letter to draft so it can be approved again.' : 'Draft letter.' ?></p>
    </div>
</section>

<?= render_record_form(url('pages/demand_letters/edit.php', ['id' => $letter['id']]), $fields, $data, $errors, 'Save changes',
    url('pages/demand_letters/view.php', ['id' => $letter['id']])) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
