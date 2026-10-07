<?php
/**
 * Data access and validation for the Categories module (table: categories).
 *
 * categories
 *   id          CHAR(36)     PK (UUID)
 *   parent_id   CHAR(36)     NULL, FK -> categories.id (sub-categories)
 *   name        VARCHAR(150) NOT NULL, UNIQUE together with parent_id
 *   description TEXT         NULL
 *   is_active   TINYINT(1)   NOT NULL DEFAULT 1
 *   created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 *
 * Referenced by: categories.parent_id, merchants.category_id, transactions.category_id
 *
 * When building the next module, copy this file and change the table,
 * fields, validation rules and dependency checks.
 */

defined('APP_ROOT') or exit('No direct access.');

const CATEGORY_NAME_MAX        = 150;    // VARCHAR(150)
const CATEGORY_DESCRIPTION_MAX = 65535;  // TEXT limit in bytes

/**
 * One category with its parent's name, or null.
 */
function category_find(string $id): ?array
{
    if (!is_valid_uuid($id)) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT c.id, c.parent_id, c.name, c.description, c.is_active, c.created_at,
                p.name AS parent_name, p.is_active AS parent_is_active
           FROM categories c
           LEFT JOIN categories p ON p.id = c.parent_id
          WHERE c.id = :id
          LIMIT 1'
    );
    $stmt->execute([':id' => $id]);

    return $stmt->fetch() ?: null;
}

/**
 * Every category as id => [id, parent_id, name, is_active].
 * The table is small master data, so loading it once is cheap.
 */
function category_all_map(): array
{
    $rows = db()->query('SELECT id, parent_id, name, is_active FROM categories ORDER BY name')->fetchAll();

    return array_column($rows, null, 'id');
}

/**
 * Full name such as "Gadgets › Phones › Accessories".
 */
function category_path(string $id, array $map): string
{
    $names = [];
    $seen = [];

    while ($id !== '' && isset($map[$id]) && !isset($seen[$id])) {
        $seen[$id] = true;
        array_unshift($names, $map[$id]['name']);
        $id = (string) $map[$id]['parent_id'];
    }

    return implode(' › ', $names);
}

/**
 * Ids of all sub-categories below $id (children, grandchildren, ...).
 *
 * @return string[]
 */
function category_descendant_ids(string $id, array $map): array
{
    $children = [];
    foreach ($map as $row) {
        if ($row['parent_id'] !== null) {
            $children[$row['parent_id']][] = $row['id'];
        }
    }

    $result = [];
    $queue = $children[$id] ?? [];
    while ($queue) {
        $next = array_shift($queue);
        if (isset($result[$next])) {
            continue;
        }
        $result[$next] = true;
        array_push($queue, ...($children[$next] ?? []));
    }

    return array_keys($result);
}

/**
 * Choices for the "Parent category" select, as id => full path.
 * When editing, the category itself and its descendants are excluded
 * so a category can never become its own ancestor.
 */
function category_parent_options(?string $excludeId = null): array
{
    $map = category_all_map();
    $excluded = [];

    if ($excludeId !== null) {
        $excluded = array_flip(category_descendant_ids($excludeId, $map));
        $excluded[$excludeId] = true;
    }

    $options = [];
    foreach ($map as $id => $row) {
        if (!isset($excluded[$id])) {
            $options[$id] = category_path($id, $map) . ((int) $row['is_active'] === 1 ? '' : ' (inactive)');
        }
    }

    asort($options, SORT_NATURAL | SORT_FLAG_CASE);

    return $options;
}

/**
 * How many records point to this category. Used before deleting.
 *
 * @return array{children:int, merchants:int, transactions:int, total:int}
 */
function category_usage(string $id): array
{
    $stmt = db()->prepare(
        'SELECT
            (SELECT COUNT(*) FROM categories   WHERE parent_id   = :id1) AS children,
            (SELECT COUNT(*) FROM merchants    WHERE category_id = :id2) AS merchants,
            (SELECT COUNT(*) FROM transactions WHERE category_id = :id3) AS transactions'
    );
    $stmt->execute([':id1' => $id, ':id2' => $id, ':id3' => $id]);
    $usage = array_map('intval', $stmt->fetch());
    $usage['total'] = $usage['children'] + $usage['merchants'] + $usage['transactions'];

    return $usage;
}

/**
 * Human-readable list such as "2 sub-categories and 5 transactions".
 */
function category_usage_text(array $usage): string
{
    $parts = [];
    if ($usage['children']) {
        $parts[] = $usage['children'] . ' sub-categor' . ($usage['children'] === 1 ? 'y' : 'ies');
    }
    if ($usage['merchants']) {
        $parts[] = $usage['merchants'] . ' merchant' . ($usage['merchants'] === 1 ? '' : 's');
    }
    if ($usage['transactions']) {
        $parts[] = $usage['transactions'] . ' transaction' . ($usage['transactions'] === 1 ? '' : 's');
    }

    if (count($parts) > 1) {
        $last = array_pop($parts);
        return implode(', ', $parts) . ' and ' . $last;
    }

    return $parts[0] ?? 'no records';
}

/**
 * Validate submitted form data against the schema rules.
 *
 * @param string|null $currentId id of the category being edited (null on create)
 * @return array{0: array, 1: array<string,string>} [clean data, field errors]
 */
function category_validate(array $input, ?string $currentId = null): array
{
    $errors = [];

    $data = [
        'name'        => input_string($input, 'name'),
        'parent_id'   => input_string($input, 'parent_id'),
        'description' => input_string($input, 'description'),
        'is_active'   => input_string($input, 'is_active', '0') === '1' ? 1 : 0,
    ];

    // Collapse repeated spaces so "Gadgets" and "Gadgets  " are the same name.
    $data['name'] = preg_replace('/\s+/u', ' ', $data['name']) ?? $data['name'];

    // name: VARCHAR(150) NOT NULL
    if ($data['name'] === '') {
        $errors['name'] = 'Category name is required.';
    } elseif (mb_strlen($data['name']) > CATEGORY_NAME_MAX) {
        $errors['name'] = 'Category name must not exceed ' . CATEGORY_NAME_MAX . ' characters.';
    }

    // description: TEXT NULL
    if (strlen($data['description']) > CATEGORY_DESCRIPTION_MAX) {
        $errors['description'] = 'Description is too long.';
    }
    $data['description'] = $data['description'] === '' ? null : $data['description'];

    // parent_id: CHAR(36) NULL, must reference an existing category
    if ($data['parent_id'] === '') {
        $data['parent_id'] = null;
    } elseif (!is_valid_uuid($data['parent_id'])) {
        $errors['parent_id'] = 'Please choose a valid parent category.';
    } else {
        $map = category_all_map();

        if (!isset($map[$data['parent_id']])) {
            $errors['parent_id'] = 'The selected parent category no longer exists.';
        } elseif ($currentId !== null && (
            $data['parent_id'] === $currentId
            || in_array($data['parent_id'], category_descendant_ids($currentId, $map), true)
        )) {
            $errors['parent_id'] = 'A category cannot be placed under itself or one of its sub-categories.';
        }
    }

    // UNIQUE (parent_id, name). MariaDB treats NULL parents as different,
    // so top-level duplicates are checked here explicitly with <=>.
    if (!isset($errors['name']) && !isset($errors['parent_id'])) {
        $stmt = db()->prepare(
            'SELECT COUNT(*) FROM categories
              WHERE name = :name
                AND parent_id <=> :parent_id
                AND id <> :id'
        );
        $stmt->execute([
            ':name'      => $data['name'],
            ':parent_id' => $data['parent_id'],
            ':id'        => $currentId ?? '',
        ]);

        if ((int) $stmt->fetchColumn() > 0) {
            $errors['name'] = $data['parent_id'] === null
                ? 'A top-level category with this name already exists.'
                : 'This parent category already has a sub-category with this name.';
        }
    }

    return [$data, $errors];
}

/**
 * Turn a database exception into a message that is safe to show.
 */
function category_db_error_message(PDOException $e): string
{
    error_log('[' . APP_NAME . '] Category query failed: ' . $e->getMessage());

    $driverCode = (int) ($e->errorInfo[1] ?? 0);

    return match ($driverCode) {
        1062       => 'A category with this name already exists under the same parent.',
        1451       => 'Unable to delete this category because it is being used by other records.',
        1452       => 'The selected parent category does not exist.',
        4025, 3819 => 'The submitted values are not allowed by the database rules.',
        default    => 'Unable to save the category. Please try again.',
    };
}
