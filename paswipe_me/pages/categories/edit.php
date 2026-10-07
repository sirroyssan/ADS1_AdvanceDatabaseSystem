<?php
/**
 * Categories - Edit.
 * Flow: load record -> fill form -> validate -> UPDATE -> success -> list (PRG).
 * Only name, parent_id, description and is_active can change; the id comes
 * from the URL and is re-checked on submit.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/category_functions.php';

$currentUser = require_admin();

$id = input_string($_GET, 'id');
$category = category_find($id);

if ($category === null) {
    flash('error', 'Category not found. It may have been deleted.');
    redirect('pages/categories/index.php');
}

$data = [
    'name'        => $category['name'],
    'parent_id'   => $category['parent_id'],
    'description' => $category['description'],
    'is_active'   => (int) $category['is_active'],
];
$errors = [];

if (is_post()) {
    require_csrf('pages/categories/edit.php?id=' . rawurlencode($category['id']));

    [$data, $errors] = category_validate($_POST, $category['id']);

    if (!$errors) {
        try {
            $stmt = db()->prepare(
                'UPDATE categories
                    SET parent_id = :parent_id,
                        name = :name,
                        description = :description,
                        is_active = :is_active
                  WHERE id = :id'
            );
            $stmt->execute([
                ':parent_id'   => $data['parent_id'],
                ':name'        => $data['name'],
                ':description' => $data['description'],
                ':is_active'   => $data['is_active'],
                ':id'          => $category['id'],
            ]);

            $old = array_intersect_key($category, $data);
            log_activity('update', 'category', $category['id'], 'Updated category "' . $data['name'] . '"', $old, $data);
            flash('success', 'Category "' . $data['name'] . '" was updated successfully.');
            redirect('pages/categories/index.php');
        } catch (PDOException $e) {
            $errors['name'] = category_db_error_message($e);
        }
    }
}

$parentOptions = category_parent_options($category['id']);
$formAction    = url('pages/categories/edit.php', ['id' => $category['id']]);
$submitLabel   = 'Save changes';
$cancelUrl     = url('pages/categories/view.php', ['id' => $category['id']]);

$pageTitle   = 'Edit Category';
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
    <a href="<?= e(url('pages/categories/view.php', ['id' => $category['id']])) ?>"><?= e($category['name']) ?></a>
    <span aria-hidden="true">/</span>
    <span aria-current="page">Edit</span>
</nav>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Edit category</h2>
        <p class="page-header__subtitle">Created <?= e(format_datetime($category['created_at'])) ?></p>
    </div>
</section>

<?php require __DIR__ . '/_form.php'; ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
