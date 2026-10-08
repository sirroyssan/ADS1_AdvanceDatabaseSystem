<?php
/**
 * Demand Letters - create. "Fill from template" reloads this page with
 * ?obligation_id=&template_id= so the balance and text are prefilled.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/letter_functions.php';

$currentUser = require_admin();

$fields = letter_fields();
$prefill = letter_prefill(input_string($_GET, 'obligation_id') ?: null, input_string($_GET, 'template_id') ?: null, input_string($_GET, 'borrower_id') ?: null);
$data = form_defaults($fields, $prefill);
$errors = [];

if (is_post()) {
    require_csrf('pages/demand_letters/create.php');
    [$data, $errors] = letter_validate($fields, $_POST);

    if (!$errors) {
        try {
            $id = new_uuid();
            $data['letter_number'] ??= generate_reference('DL', 'demand_letters', 'letter_number');
            $data['content'] = str_replace('(assigned on save)', $data['letter_number'], $data['content']);
            db_insert('demand_letters', ['id' => $id] + $data + ['status' => 'draft', 'created_by' => $currentUser['id']]);
            log_activity('create', 'demand_letter', $id, 'Drafted demand letter ' . $data['letter_number']);
            flash('success', 'Demand letter ' . $data['letter_number'] . ' was saved as a draft.');
            redirect('pages/demand_letters/view.php', ['id' => $id]);
        } catch (PDOException $e) {
            $errors['_form'] = db_error_message($e);
        }
    }
}

[$oblOptions] = obligation_options(null, true);

$pageTitle = 'Add Demand Letter';
$activeNav = 'demand_letters';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Demand Letters' => url('pages/demand_letters/index.php'), 'Add' => null]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Add demand letter</h2>
        <p class="page-header__subtitle">Start from an obligation and a template, then review the text before saving.</p>
    </div>
</section>

<form method="get" action="<?= e(url('pages/demand_letters/create.php')) ?>" class="toolbar">
    <div class="toolbar__filters">
        <label class="visually-hidden" for="prefill_obligation">Obligation</label>
        <select id="prefill_obligation" name="obligation_id">
            <option value="">Choose an open obligation…</option>
            <?php foreach ($oblOptions as $value => $label): ?>
                <option value="<?= e($value) ?>" <?= $value === ($data['obligation_id'] ?? '') ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <label class="visually-hidden" for="prefill_template">Template</label>
        <select id="prefill_template" name="template_id">
            <option value="">Choose a template…</option>
            <?php foreach (letter_template_options() as $value => $label): ?>
                <option value="<?= e($value) ?>" <?= $value === ($data['template_id'] ?? '') ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn--outline"><?= icon('repeat') ?> <span>Fill from template</span></button>
    </div>
</form>

<?= render_record_form(url('pages/demand_letters/create.php'), $fields, $data, $errors, 'Save draft', url('pages/demand_letters/index.php')) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
