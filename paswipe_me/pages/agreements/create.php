<?php
/**
 * Agreements - create. Can be prefilled from an obligation (?obligation_id=).
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/agreement_functions.php';

$currentUser = require_admin();

$fields = agreement_fields();
$preset = [];
$obligationId = input_string($_GET, 'obligation_id');
if (is_valid_uuid($obligationId)) {
    $o = db_row('SELECT * FROM borrower_obligations o WHERE id = :id AND ' . owner_scope('borrower_obligations', 'o'), [':id' => $obligationId]);
    if ($o) {
        // The terms recorded for the obligation, as they are.
        $terms = agreement_terms_obligation($o);
        $preset = ['obligation_id' => $obligationId, 'borrower_id' => $o['borrower_id'], 'transaction_id' => $o['transaction_id'],
                   'original_amount' => $terms['original'], 'installment_amount' => $terms['installment'], 'installment_count' => $terms['count'],
                   'agreed_charges' => $terms['charges'], 'payment_terms' => $terms['terms']];
    }
}
$borrowerId = input_string($_GET, 'borrower_id');
if (!$preset && owns('borrowers', $borrowerId)) {
    $preset['borrower_id'] = $borrowerId;
}
$data = form_defaults($fields, $preset);
$errors = [];

if (is_post()) {
    require_csrf('pages/agreements/create.php');
    [$data, $errors] = agreement_validate($fields, $_POST);

    if (!$errors) {
        try {
            $id = new_uuid();
            $data['agreement_number'] ??= generate_reference('AGR', 'agreements', 'agreement_number');
            db_insert('agreements', ['id' => $id] + $data + ['created_by' => $currentUser['id']]);
            log_activity('create', 'agreement', $id, 'Created agreement ' . $data['agreement_number'], null, $data);
            flash('success', 'Agreement ' . $data['agreement_number'] . ' was created. Add the signatories on the agreement page.');
            redirect('pages/agreements/view.php', ['id' => $id]);
        } catch (PDOException $e) {
            $errors['_form'] = db_error_message($e);
        }
    }
}

$pageTitle = 'Add Agreement';
$activeNav = 'agreements';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Agreements' => url('pages/agreements/index.php'), 'Add' => null]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Add agreement</h2>
        <p class="page-header__subtitle">The printable text comes from the template with the borrower's details filled in.</p>
    </div>
</section>

<?= render_record_form(url('pages/agreements/create.php'), $fields, $data, $errors, 'Save agreement', url('pages/agreements/index.php')) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
