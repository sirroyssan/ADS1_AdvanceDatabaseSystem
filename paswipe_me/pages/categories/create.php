<?php
/**
 * Categories - Create.
 * Flow: validate -> INSERT -> success message -> redirect to list (PRG).
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/category_functions.php';

$currentUser = require_admin();

$data = ['name' => '', 'parent_id' => null, 'description' => null, 'is_active' => 1];
$errors = [];

// Allow "Add sub-category" links: create.php?parent_id=<uuid>
$presetParent = input_string($_GET, 'parent_id');
if ($presetParent !== '' && is_valid_uuid($presetParent)) {
    $data['parent_id'] = $presetParent;
}

if (is_post()) {
    require_csrf('pages/categories/create.php');

    [$data, $errors] = category_validate($_POST);

    if (!$errors) {
        try {
            $id = new_uuid();
            $stmt = db()->prepare(
                'INSERT INTO categories (id, parent_id, name, description, is_active)
                 VALUES (:id, :parent_id, :name, :description, :is_active)'
            );
            $stmt->execute([
                ':id'          => $id,
                ':parent_id'   => $data['parent_id'],
                ':name'        => $data['name'],
                ':description' => $data['description'],
                ':is_active'   => $data['is_active'],
            ]);

            log_activity('create', 'category', $id, 'Created category "' . $data['name'] . '"', null, $data);
            flash('success', 'Category "' . $data['name'] . '" was added successfully.');
            redirect('pages/categories/index.php');
        } catch (PDOException $e) {
            $errors['name'] = category_db_error_message($e);
        }
    }
}

$parentOptions = category_parent_options();
$formAction    = url('pages/categories/create.php');
$submitLabel   = 'Save category';
$cancelUrl     = url('pages/categories/index.php');

$pageTitle   = 'Add Category';
$activeNav   = 'categories';
$pageStyles  = ['assets/css/categories.css'];
$pageScripts = ['assets/js/categories.js'];
require APP_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb no-print" aria-label="Breadcrumb">
    <a href="<?= e(url('pages/dashboard.php')) ?>">Dashboard</a>
    <span aria-hidden="true">/</span>
    <a href="<?= e(url('pages/categories/index.php')) ?>">Categories</a>
    <span aria-hidden="true">/</span>
    <span aria-current="page">Add</span>
</nav>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Add category</h2>
        <p class="page-header__subtitle">Create a main category or a sub-category for merchants and transactions.</p>
    </div>
</section>

<?php require __DIR__ . '/_form.php'; ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
