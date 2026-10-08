<?php
/**
 * Users & Roles module (tables: users, user_roles).
 *
 * Roles are an ENUM in user_roles (owner, admin, borrower), so roles are not
 * a CRUD table; they are assigned with checkboxes on the user form.
 * Users are never hard-deleted: they are referenced as created_by,
 * reviewed_by, performed_by, owner_user_id, actor_user_id ... in many
 * tables. Use status (inactive/suspended) instead.
 */

defined('APP_ROOT') or exit('No direct access.');

const MIN_PASSWORD_LENGTH = 8;

function user_fields(): array
{
    return [
        'first_name'  => ['label' => 'First name', 'type' => 'text', 'max' => 100],
        'middle_name' => ['label' => 'Middle name', 'type' => 'text', 'max' => 100],
        'last_name'   => ['label' => 'Last name', 'type' => 'text', 'max' => 100],
        'mobile'      => ['label' => 'Mobile', 'type' => 'tel', 'max' => 30, 'pattern' => '/^[0-9+()\-\s]{7,30}$/', 'pattern_message' => 'Enter a valid mobile number.'],
        'email'       => ['label' => 'Email', 'type' => 'email', 'max' => 255, 'required' => true, 'unique' => ['users', 'email', 'This email is already used by another account.'], 'section' => 'Sign-in'],
        'username'    => ['label' => 'Username', 'type' => 'text', 'max' => 100, 'pattern' => '/^[A-Za-z0-9._-]{3,100}$/',
                          'pattern_message' => 'Use 3–100 letters, numbers, dots, dashes or underscores.',
                          'unique' => ['users', 'username', 'This username is already taken.'], 'help' => 'Optional. Users can sign in with username or email.'],
        'status'      => ['label' => 'Account status', 'type' => 'select', 'options' => enum_options(ENUM_USER_STATUS), 'required' => true, 'initial' => 'active'],
    ];
}

/**
 * Validate the whole user form: fields, roles and password.
 *
 * @return array{0: array, 1: array, 2: string[], 3: ?string} [data, errors, roles, new password or null]
 */
function user_validate(array $input, ?array $existing, string $currentUserId): array
{
    $fields = user_fields();
    [$data, $errors] = validate_fields($fields, $input, $existing['id'] ?? null);

    $roles = array_values(array_intersect(ENUM_USER_ROLE, (array) ($input['roles'] ?? [])));
    if (!$roles) {
        $errors['roles'] = 'Choose at least one role.';
    }

    $password = is_string($input['password'] ?? null) ? $input['password'] : '';
    $confirm  = is_string($input['password_confirm'] ?? null) ? $input['password_confirm'] : '';

    if ($existing === null && $password === '') {
        $errors['password'] = 'Password is required for a new account.';
    }
    if ($password !== '') {
        if (strlen($password) < MIN_PASSWORD_LENGTH) {
            $errors['password'] = 'Password must be at least ' . MIN_PASSWORD_LENGTH . ' characters.';
        } elseif (strlen($password) > 255) {
            $errors['password'] = 'Password is too long.';
        } elseif (!hash_equals($password, $confirm)) {
            $errors['password_confirm'] = 'Passwords do not match.';
        }
    }

    // Do not let administrators lock themselves out.
    if ($existing !== null && $existing['id'] === $currentUserId) {
        if (($data['status'] ?? '') !== 'active') {
            $errors['status'] = 'You cannot change the status of your own account.';
        }
        if (!array_intersect(ADMIN_ROLES, $roles)) {
            $errors['roles'] = 'You cannot remove your own administrator role.';
        }
    }

    return [$data, $errors, $roles, $password !== '' ? $password : null];
}

/**
 * Replace the user's roles inside the caller's transaction.
 */
function user_save_roles(string $userId, array $roles): void
{
    db_exec('DELETE FROM user_roles WHERE user_id = :id', [':id' => $userId]);
    foreach ($roles as $role) {
        db_insert('user_roles', ['id' => new_uuid(), 'user_id' => $userId, 'role' => $role]);
    }
}

function user_find(string $id): ?array
{
    if (!is_valid_uuid($id)) {
        return null;
    }

    $user = db_row(
        'SELECT id, username, email, first_name, middle_name, last_name, mobile, status, email_verified_at,
                mobile_verified_at, last_login_at, failed_login_attempts, locked_until,
                (locked_until IS NOT NULL AND locked_until > NOW()) AS is_locked, created_at, updated_at
           FROM users WHERE id = :id',
        [':id' => $id]
    );

    if ($user) {
        $user['roles'] = fetch_user_roles($user['id']);
    }

    return $user;
}

/**
 * Role checkboxes (user_roles is managed inside the user form).
 */
function render_role_checkboxes(array $selected, ?string $error): string
{
    $descriptions = [
        'owner'    => 'Owns the credit cards; full administrator access.',
        'admin'    => 'Manages records and approves payments.',
        'borrower' => 'Card user. Link the account to a borrower record from the borrower page.',
    ];

    $html = '<fieldset class="form-group form-group--full role-fieldset" aria-describedby="roles-error"><legend class="label">Roles <span class="required" aria-hidden="true">*</span></legend>';
    foreach (ENUM_USER_ROLE as $role) {
        $html .= '<label class="check-option"><input type="checkbox" name="roles[]" value="' . e($role) . '"' . (in_array($role, $selected, true) ? ' checked' : '') . '>'
            . '<span><strong>' . e(role_label($role)) . '</strong><small>' . e($descriptions[$role]) . '</small></span></label>';
    }

    return $html . '<p class="field-error" id="roles-error"' . ($error ? '' : ' hidden') . '>' . e($error ?? '') . '</p></fieldset>';
}

function render_password_fields(array $errors, bool $required): string
{
    $fields = [
        'password'         => ['label' => $required ? 'Password' : 'New password', 'type' => 'password', 'max' => 255, 'required' => $required,
                               'help' => $required ? 'At least ' . MIN_PASSWORD_LENGTH . ' characters.' : 'Leave blank to keep the current password.'],
        'password_confirm' => ['label' => 'Confirm password', 'type' => 'password', 'max' => 255, 'required' => $required],
    ];

    return '<h3 class="form-section">Password</h3>' . render_fields($fields, [], $errors);
}
