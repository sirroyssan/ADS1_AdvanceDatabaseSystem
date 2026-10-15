<?php
/**
 * Field definitions -> server-side validation + form HTML.
 *
 * A module describes its form once, e.g.
 *
 *   'merchant_name' => ['label' => 'Merchant name', 'type' => 'text', 'max' => 255, 'required' => true],
 *   'category_id'   => ['label' => 'Category', 'type' => 'select', 'options' => $categoryOptions],
 *
 * and uses validate_fields() on POST and render_fields() to draw the form.
 *
 * Supported types: text, email, url, tel, textarea, decimal, int, date,
 * datetime, select, bool, password.
 * Common keys: label, type, required, max, min, help, full, placeholder,
 * options, empty_label, default, pattern + pattern_message, scale,
 * unique => [table, column, message], readonly, rows, attrs.
 */

defined('APP_ROOT') or exit('No direct access.');

/**
 * Validate input against field definitions.
 *
 * @return array{0: array<string,mixed>, 1: array<string,string>} [clean data, errors]
 */
function validate_fields(array $fields, array $input, ?string $currentId = null): array
{
    $data = [];
    $errors = [];

    foreach ($fields as $name => $f) {
        if (!empty($f['readonly'])) {
            continue;
        }

        $label    = $f['label'];
        $type     = $f['type'] ?? 'text';
        $required = !empty($f['required']);
        $raw      = $input[$name] ?? null;

        if ($type === 'bool') {
            $data[$name] = (is_string($raw) && $raw === '1') ? 1 : 0;
            continue;
        }

        $value = is_string($raw) ? trim($raw) : '';

        if ($value === '') {
            if ($required) {
                $errors[$name] = $label . ' is required.';
            }
            $data[$name] = array_key_exists('default', $f) ? $f['default'] : null;
            continue;
        }

        switch ($type) {
            case 'text':
            case 'tel':
            case 'password':
                if (isset($f['max']) && mb_strlen($value) > $f['max']) {
                    $errors[$name] = $label . ' must not exceed ' . $f['max'] . ' characters.';
                }
                if (isset($f['min']) && mb_strlen($value) < $f['min']) {
                    $errors[$name] = $label . ' must be at least ' . $f['min'] . ' characters.';
                }
                break;

            case 'email':
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $errors[$name] = 'Enter a valid email address.';
                } elseif (strlen($value) > ($f['max'] ?? 255)) {
                    $errors[$name] = $label . ' is too long.';
                }
                break;

            case 'url':
                if (!filter_var($value, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $value)) {
                    $errors[$name] = 'Enter a valid web address starting with http:// or https://.';
                } elseif (strlen($value) > ($f['max'] ?? 500)) {
                    $errors[$name] = $label . ' is too long.';
                }
                break;

            case 'textarea':
                if (strlen($value) > ($f['max'] ?? 65535)) {
                    $errors[$name] = $label . ' is too long.';
                }
                break;

            case 'decimal':
                $value = str_replace([',', ' ', '₱'], '', $value);
                $scale = $f['scale'] ?? 2;
                $digits = $f['digits'] ?? 15;
                if (!preg_match('/^-?\d+(\.\d{1,' . $scale . '})?$/', $value)) {
                    $errors[$name] = $label . ' must be a number with up to ' . $scale . ' decimal places.';
                    break;
                }
                if (strlen(ltrim(explode('.', ltrim($value, '-'))[0], '0')) > $digits - $scale) {
                    $errors[$name] = $label . ' is too large.';
                    break;
                }
                if (isset($f['min']) && (float) $value < $f['min']) {
                    $errors[$name] = $label . ' must be at least ' . format_number($f['min'], $f['min'] == (int) $f['min'] ? 0 : 2) . '.';
                }
                if (isset($f['min_exclusive']) && (float) $value <= $f['min_exclusive']) {
                    $errors[$name] = $label . ' must be greater than ' . $f['min_exclusive'] . '.';
                }
                if (isset($f['max']) && (float) $value > $f['max']) {
                    $errors[$name] = $label . ' must not exceed ' . $f['max'] . '.';
                }
                $value = number_format((float) $value, $scale, '.', '');
                break;

            case 'int':
                $int = filter_var($value, FILTER_VALIDATE_INT);
                if ($int === false) {
                    $errors[$name] = $label . ' must be a whole number.';
                } elseif (isset($f['min']) && $int < $f['min']) {
                    $errors[$name] = $label . ' must be at least ' . $f['min'] . '.';
                } elseif (isset($f['max']) && $int > $f['max']) {
                    $errors[$name] = $label . ' must not exceed ' . $f['max'] . '.';
                }
                $value = $int === false ? $value : $int;
                break;

            case 'date':
                $d = DateTime::createFromFormat('!Y-m-d', $value);
                if (!$d || $d->format('Y-m-d') !== $value) {
                    $errors[$name] = $label . ' must be a valid date.';
                }
                break;

            case 'datetime':
                $value = str_replace('T', ' ', $value);
                $d = DateTime::createFromFormat('Y-m-d H:i', substr($value, 0, 16));
                if (!$d) {
                    $errors[$name] = $label . ' must be a valid date and time.';
                } else {
                    $value = $d->format('Y-m-d H:i:00');
                }
                break;

            case 'select':
                if (!array_key_exists($value, $f['options'] ?? [])) {
                    $errors[$name] = 'Please choose a valid ' . strtolower($label) . '.';
                }
                break;
        }

        if (!isset($errors[$name]) && isset($f['pattern']) && !preg_match($f['pattern'], (string) $value)) {
            $errors[$name] = $f['pattern_message'] ?? ($label . ' has an invalid format.');
        }

        if (!isset($errors[$name]) && isset($f['unique'])) {
            [$table, $column] = $f['unique'];
            $taken = (int) db_value(
                'SELECT COUNT(*) FROM ' . db_identifier($table) . ' WHERE ' . db_identifier($column) . ' = :v AND id <> :id',
                [':v' => $value, ':id' => $currentId ?? '']
            );
            if ($taken > 0) {
                $errors[$name] = $f['unique'][2] ?? ($label . ' already exists.');
            }
        }

        $data[$name] = $value;
    }

    return [$data, $errors];
}

/**
 * Turn a database row into form values (only the defined fields).
 */
function form_data_from_row(array $fields, array $row): array
{
    $data = [];
    foreach ($fields as $name => $f) {
        $value = $row[$name] ?? null;
        if (($f['type'] ?? '') === 'datetime' && $value) {
            $value = str_replace(' ', 'T', substr((string) $value, 0, 16));
        }
        $data[$name] = $value;
    }

    return $data;
}

/**
 * Default form values for a create page.
 */
function form_defaults(array $fields, array $overrides = []): array
{
    $data = [];
    foreach ($fields as $name => $f) {
        $data[$name] = $f['initial'] ?? (array_key_exists('default', $f) ? $f['default'] : null);
        if (($f['type'] ?? '') === 'bool' && $data[$name] === null) {
            $data[$name] = 0;
        }
    }

    return array_merge($data, $overrides);
}

/**
 * HTML for one field.
 */
function render_field(string $name, array $f, mixed $value, ?string $error): string
{
    $type   = $f['type'] ?? 'text';
    $id     = 'f_' . $name;
    $value  = $value === null ? '' : (string) $value;
    $invalid = $error !== null;
    $describedBy = trim(($f['help'] ?? '') !== '' ? $id . '-help' : '') . ' ' . $id . '-error';
    $common = ' id="' . e($id) . '" name="' . e($name) . '"'
        . ($invalid ? ' class="is-invalid" aria-invalid="true"' : ' aria-invalid="false"')
        . ' aria-describedby="' . e(trim($describedBy)) . '"'
        . (!empty($f['required']) ? ' required' : '')
        . (!empty($f['readonly']) ? ' disabled' : '');

    foreach (($f['attrs'] ?? []) as $attr => $attrValue) {
        $common .= ' ' . e($attr) . '="' . e($attrValue) . '"';
    }

    $classes = 'form-group' . (!empty($f['full']) || $type === 'textarea' ? ' form-group--full' : '');
    $html = '<div class="' . $classes . '" data-field="' . e($name) . '">';

    if ($type === 'bool') {
        $html .= '<span class="label">' . e($f['label']) . '</span>'
            . '<label class="switch"><input type="checkbox" value="1"' . $common . ($value === '1' ? ' checked' : '') . '>'
            . '<span class="switch__track" aria-hidden="true"></span>'
            . '<span class="switch__label">' . e($f['switch_label'] ?? 'Yes') . '</span></label>';
    } else {
        $html .= '<label for="' . e($id) . '">' . e($f['label'])
            . (!empty($f['required']) || !empty($f['required_mark']) ? ' <span class="required" aria-hidden="true">*</span>' : '') . '</label>';

        switch ($type) {
            case 'textarea':
                $html .= '<textarea rows="' . (int) ($f['rows'] ?? 4) . '"' . $common . '>' . e($value) . '</textarea>';
                break;

            case 'select':
                $html .= '<select' . $common . '>';
                if (empty($f['required']) || !empty($f['empty_label'])) {
                    $html .= '<option value="">' . e($f['empty_label'] ?? '— None —') . '</option>';
                }
                foreach (($f['options'] ?? []) as $optValue => $optLabel) {
                    $extra = '';
                    if (isset($f['option_data'][$optValue])) {
                        foreach ($f['option_data'][$optValue] as $dataKey => $dataValue) {
                            $extra .= ' data-' . e($dataKey) . '="' . e($dataValue) . '"';
                        }
                    }
                    $html .= '<option value="' . e($optValue) . '"' . ((string) $optValue === $value ? ' selected' : '') . $extra . '>'
                        . e($optLabel) . '</option>';
                }
                $html .= '</select>';
                break;

            case 'decimal':
                $html .= '<input type="text" inputmode="decimal" class="input-num" data-scale="' . (int) ($f['scale'] ?? 2) . '" value="' . e($value) . '"'
                    . (isset($f['placeholder']) ? ' placeholder="' . e($f['placeholder']) . '"' : ' placeholder="0.00"')
                    . $common . '>';
                break;

            case 'int':
                $html .= '<input type="number" step="1" value="' . e($value) . '"'
                    . (isset($f['min']) ? ' min="' . (int) $f['min'] . '"' : '')
                    . (isset($f['max']) ? ' max="' . (int) $f['max'] . '"' : '')
                    . $common . '>';
                break;

            case 'date':
                $html .= '<input type="date" value="' . e($value) . '"' . $common . '>';
                break;

            case 'datetime':
                $html .= '<input type="datetime-local" value="' . e($value) . '"' . $common . '>';
                break;

            default:
                $inputType = in_array($type, ['email', 'url', 'tel', 'password'], true) ? $type : 'text';
                $html .= '<input type="' . $inputType . '" value="' . ($type === 'password' ? '' : e($value)) . '"'
                    . (isset($f['max']) && in_array($type, ['text', 'tel', 'password', 'email', 'url'], true) ? ' maxlength="' . (int) $f['max'] . '"' : '')
                    . (isset($f['placeholder']) ? ' placeholder="' . e($f['placeholder']) . '"' : '')
                    . ($type === 'password' ? ' autocomplete="new-password"' : '')
                    . $common . '>';
        }
    }

    if (($f['help'] ?? '') !== '') {
        $html .= '<p class="field-help" id="' . e($id) . '-help">' . e($f['help']) . '</p>';
    }
    $html .= '<p class="field-error" id="' . e($id) . '-error"' . ($invalid ? '' : ' hidden') . '>' . e($error ?? '') . '</p>';

    return $html . '</div>';
}

/**
 * HTML for all fields. Optional 'section' keys start a new titled group.
 */
function render_fields(array $fields, array $data, array $errors): string
{
    $html = '<div class="form-grid">';

    foreach ($fields as $name => $f) {
        if (isset($f['section'])) {
            $html .= '</div><h3 class="form-section">' . e($f['section']) . '</h3><div class="form-grid">';
        }
        $value = $data[$name] ?? null;
        if (($f['type'] ?? '') === 'bool') {
            $value = (int) $value === 1 ? '1' : '0';
        }
        $html .= render_field($name, $f, $value, $errors[$name] ?? null);
    }

    return $html . '</div>';
}

/**
 * Complete create/edit form card (CSRF, errors summary, fields, buttons).
 */
function render_record_form(
    string $action,
    array $fields,
    array $data,
    array $errors,
    string $submitLabel,
    string $cancelUrl,
    string $extraTop = '',
    string $formId = 'record-form'
): string {
    $html = '<form method="post" action="' . e($action) . '" class="form-card form-card--wide" id="' . e($formId) . '" novalidate data-validate>';
    $html .= csrf_field();

    if ($errors) {
        $general = $errors['_form'] ?? null;
        $html .= '<div class="alert alert--error" role="alert">' . icon('alert') . '<span>'
            . e($general ?? ('Please correct the highlighted field' . (count($errors) > 1 ? 's' : '') . ' below.'))
            . '</span></div>';
    }

    $html .= $extraTop . render_fields($fields, $data, $errors);
    $html .= '<div class="form-actions">'
        . '<a href="' . e($cancelUrl) . '" class="btn btn--outline">Cancel</a>'
        . '<button type="submit" class="btn btn--primary" data-loading-text="Saving…">' . icon('check')
        . ' <span class="btn__label">' . e($submitLabel) . '</span></button>'
        . '</div></form>';

    return $html;
}

/**
 * Enum helper: ['pending' => 'Pending', 'partially_paid' => 'Partially paid'].
 */
function enum_options(array $values): array
{
    $options = [];
    foreach ($values as $value) {
        $options[$value] = humanize($value);
    }

    return $options;
}

function humanize(?string $value): string
{
    if ($value === null || $value === '') {
        return '—';
    }

    return ucfirst(str_replace('_', ' ', $value));
}
