<?php
/**
 * Shared list-page building blocks: search/filter state, paginated
 * queries, toolbar, print header, table footer, empty state, badges.
 * They produce the same markup and classes as the Categories module.
 *
 * List config example:
 *   $config = [
 *       'path'    => 'pages/merchants/index.php',
 *       'title'   => 'Merchants',
 *       'search'  => 'Search name, branch or city…',
 *       'filters' => [
 *           'category' => ['label' => 'Category', 'type' => 'select', 'options' => [...], 'all' => 'All categories'],
 *           'from'     => ['label' => 'From', 'type' => 'date'],
 *       ],
 *       'sorts'   => ['name' => ['Name (A–Z)', 'm.merchant_name ASC'], ...],  // first = default
 *   ];
 */

defined('APP_ROOT') or exit('No direct access.');

const LIST_PER_PAGE_OPTIONS = [10, 25, 50];
const LIST_PRINT_LIMIT      = 5000;

/**
 * Read and whitelist search, filters, sort and paging from the query string.
 */
function list_state(array $config): array
{
    $sorts = $config['sorts'] ?? [];
    $defaultSort = (string) array_key_first($sorts);

    $state = [
        'config'   => $config,
        'search'   => isset($config['search']) ? mb_substr(input_string($_GET, 'search'), 0, 100) : '',
        'filters'  => [],
        'sort'     => input_string($_GET, 'sort', $defaultSort),
        'per_page' => input_int($_GET, 'per_page', 10),
        'page'     => input_int($_GET, 'page', 1),
        'print'    => input_string($_GET, 'print') === '1',
        'summary'  => [],
    ];

    if (!isset($sorts[$state['sort']])) {
        $state['sort'] = $defaultSort;
    }
    if (!in_array($state['per_page'], LIST_PER_PAGE_OPTIONS, true)) {
        $state['per_page'] = 10;
    }

    foreach (($config['filters'] ?? []) as $key => $filter) {
        $value = input_string($_GET, $key);

        if ($filter['type'] === 'select' && !array_key_exists($value, $filter['options'])) {
            $value = '';
        }
        if ($filter['type'] === 'date') {
            $d = DateTime::createFromFormat('!Y-m-d', $value);
            if (!$d || $d->format('Y-m-d') !== $value) {
                $value = '';
            }
        }

        $state['filters'][$key] = $value;

        if ($value !== '') {
            $shown = $filter['type'] === 'select' ? $filter['options'][$value] : format_date($value);
            $state['summary'][] = $filter['label'] . ': ' . $shown;
        }
    }

    if ($state['search'] !== '') {
        array_unshift($state['summary'], 'Search: “' . $state['search'] . '”');
    }

    $state['has_filters'] = $state['search'] !== '' || array_filter($state['filters'], static fn ($v) => $v !== '');

    // Parameters to carry over in links (only non-default values).
    $query = array_filter(['search' => $state['search']] + $state['filters'], static fn ($v) => $v !== '');
    if ($state['sort'] !== $defaultSort) {
        $query['sort'] = $state['sort'];
    }
    if ($state['per_page'] !== 10) {
        $query['per_page'] = (string) $state['per_page'];
    }
    foreach (($config['keep'] ?? []) as $key => $value) {
        $query[$key] = $value;
    }
    $state['query'] = $query;

    return $state;
}

function list_url(array $state, array $extra = []): string
{
    return url($state['config']['path'], array_merge($state['query'], $extra));
}

/**
 * Count + fetch one page (or every row when printing).
 *
 * @param string $select  e.g. "m.id, m.merchant_name, c.name AS category_name"
 * @param string $from    e.g. "merchants m LEFT JOIN categories c ON c.id = m.category_id"
 * @param array  $where   SQL fragments joined with AND
 * @return array{rows: array, pager: array, total: int, grand_total: int}
 */
function run_list(array $state, string $select, string $from, array $where, array $params): array
{
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $total = (int) db_value('SELECT COUNT(*) FROM ' . $from . $whereSql, $params);
    $grandTotal = $state['has_filters'] ? (int) db_value('SELECT COUNT(*) FROM ' . $from . (isset($state['config']['base_where']) ? ' WHERE ' . $state['config']['base_where'] : '')) : $total;

    if ($state['print']) {
        $pager = paginate($total, 1, max(1, min($total, LIST_PRINT_LIMIT)));
        $limit = ' LIMIT ' . LIST_PRINT_LIMIT;
    } else {
        $pager = paginate($total, $state['page'], $state['per_page']);
        $limit = ' LIMIT ' . (int) $pager['per_page'] . ' OFFSET ' . (int) $pager['offset'];
    }

    $order = $state['config']['sorts'][$state['sort']][1] ?? '1';
    $rows = db_all('SELECT ' . $select . ' FROM ' . $from . $whereSql . ' ORDER BY ' . $order . $limit, $params);

    return ['rows' => $rows, 'pager' => $pager, 'total' => $total, 'grand_total' => $grandTotal];
}

/**
 * Add "(col1 LIKE ? OR col2 LIKE ? ...)" for the search box.
 */
function add_search_condition(array $state, array $columns, array &$where, array &$params): void
{
    if ($state['search'] === '') {
        return;
    }

    $parts = [];
    foreach ($columns as $i => $column) {
        $parts[] = $column . ' LIKE :search' . $i;
        $params[':search' . $i] = like_value($state['search']);
    }
    $where[] = '(' . implode(' OR ', $parts) . ')';
}

/* ------------------------------------------------------------------
 * Rendering
 * ------------------------------------------------------------------ */

function render_breadcrumb(array $trail): string
{
    // Borrower portal pages ($layout = 'portal') start at "My Account".
    $portal = ($GLOBALS['layout'] ?? 'admin') === 'portal';
    $html = '<nav class="breadcrumb no-print" aria-label="Breadcrumb">'
        . ($portal ? '<a href="' . e(url('portal/index.php')) . '">My Account</a>' : '<a href="' . e(url('pages/dashboard.php')) . '">Dashboard</a>');

    foreach ($trail as $label => $link) {
        $html .= '<span aria-hidden="true">/</span>';
        $html .= $link === null
            ? '<span aria-current="page">' . e($label) . '</span>'
            : '<a href="' . e($link) . '">' . e($label) . '</a>';
    }

    return $html . '</nav>';
}

/**
 * Page title row with Print and Add New buttons (hidden in print mode).
 */
function render_list_header(array $state, string $subtitle, ?string $createUrl, string $createLabel = 'Add New', string $extraButtons = ''): string
{
    if ($state['print']) {
        return '';
    }

    $html = '<section class="page-header no-print"><div>'
        . '<h2 class="page-header__title">' . e($state['config']['title']) . '</h2>'
        . '<p class="page-header__subtitle">' . e($subtitle) . '</p></div>'
        . '<div class="page-header__actions">' . $extraButtons
        . '<a href="' . e(list_url($state, ['print' => '1'])) . '" class="btn btn--outline" title="Print all records that match the current filters">'
        . icon('printer') . ' <span>Print</span></a>';

    if ($createUrl !== null) {
        $html .= '<a href="' . e($createUrl) . '" class="btn btn--primary">' . icon('plus') . ' <span>' . e($createLabel) . '</span></a>';
    }

    return $html . '</div></section>';
}

/**
 * Search box + filter controls.
 */
function render_list_toolbar(array $state): string
{
    if ($state['print']) {
        return '';
    }

    $config = $state['config'];
    $html = '<form method="get" action="' . e(url($config['path'])) . '" class="toolbar no-print" role="search">';

    foreach (($config['keep'] ?? []) as $key => $value) {
        $html .= '<input type="hidden" name="' . e($key) . '" value="' . e($value) . '">';
    }

    if (isset($config['search'])) {
        $html .= '<div class="toolbar__search"><label for="search" class="visually-hidden">Search</label>'
            . '<div class="input-icon">' . icon('search')
            . '<input type="search" id="search" name="search" value="' . e($state['search']) . '" maxlength="100" placeholder="' . e($config['search']) . '">'
            . '</div></div>';
    }

    $html .= '<div class="toolbar__filters">';

    foreach (($config['filters'] ?? []) as $key => $filter) {
        $id = 'filter_' . $key;
        $value = $state['filters'][$key];

        if ($filter['type'] === 'select') {
            $html .= '<label class="visually-hidden" for="' . e($id) . '">' . e($filter['label']) . '</label>'
                . '<select id="' . e($id) . '" name="' . e($key) . '" data-auto-submit>'
                . '<option value="">' . e($filter['all'] ?? ('All ' . strtolower($filter['label']))) . '</option>';
            foreach ($filter['options'] as $optValue => $optLabel) {
                $html .= '<option value="' . e($optValue) . '"' . ((string) $optValue === $value ? ' selected' : '') . '>' . e($optLabel) . '</option>';
            }
            $html .= '</select>';
        } else {
            $html .= '<label class="date-filter"><span>' . e($filter['label']) . '</span>'
                . '<input type="date" name="' . e($key) . '" value="' . e($value) . '"></label>';
        }
    }

    if (count($config['sorts'] ?? []) > 1) {
        $html .= '<label class="visually-hidden" for="sort">Sort by</label><select id="sort" name="sort" data-auto-submit>';
        foreach ($config['sorts'] as $key => [$label]) {
            $html .= '<option value="' . e($key) . '"' . ($key === $state['sort'] ? ' selected' : '') . '>' . e($label) . '</option>';
        }
        $html .= '</select>';
    }

    if ($state['per_page'] !== 10) {
        $html .= '<input type="hidden" name="per_page" value="' . e($state['per_page']) . '">';
    }

    $html .= '<button type="submit" class="btn btn--primary" data-loading-text="Searching…">' . icon('search') . ' <span class="btn__label">'
        . (isset($config['search']) ? 'Search' : 'Apply') . '</span></button>';

    if ($state['has_filters']) {
        $html .= '<a href="' . e(url($config['path'], $config['keep'] ?? [])) . '" class="btn btn--ghost">' . icon('x') . ' <span>Clear</span></a>';
    }

    return $html . '</div></form>';
}

/**
 * Print-preview bar (screen only) + report header (print, or print mode).
 */
function render_print_header(array $state, int $total, array $pager, ?string $title = null, array $extraMeta = []): string
{
    $title ??= $state['config']['title'] . ' Report';
    $user = current_user();
    $html = '';

    if ($state['print']) {
        $html .= '<div class="print-toolbar no-print" role="region" aria-label="Print preview">'
            . '<p>' . icon('printer') . ' <span>Print preview — ' . e(format_number($total)) . ' record(s) with the current filters.</span></p>'
            . '<div><a href="' . e(list_url($state)) . '" class="btn btn--outline btn--sm">' . icon('arrow-left') . ' <span>Back to list</span></a>'
            . '<button type="button" class="btn btn--primary btn--sm" data-print>' . icon('printer') . ' <span>Print</span></button></div></div>';
    }

    $html .= '<div class="print-header' . ($state['print'] ? ' print-header--visible" data-auto-print' : '"') . '>'
        . '<p class="print-header__system">' . e(APP_NAME) . '</p>'
        . '<h2 class="print-header__title">' . e($title) . '</h2>'
        . '<p class="print-header__meta">Printed: ' . e(date('F j, Y g:i A'))
        . ($user ? ' · Printed by: ' . e(user_display_name($user)) : '') . '</p>'
        . '<p class="print-header__meta">' . e($state['summary'] ? implode(' · ', $state['summary']) : 'All records')
        . ' · ' . e(format_number($total)) . ' record(s)'
        . (!$state['print'] && $pager['pages'] > 1 ? ' · Page ' . e($pager['page']) . ' of ' . e($pager['pages']) : '')
        . '</p>';

    foreach ($extraMeta as $line) {
        $html .= '<p class="print-header__meta">' . e($line) . '</p>';
    }

    return $html . '</div>';
}

/**
 * "Showing x–y of z", rows-per-page selector and pagination.
 */
function render_list_footer(array $state, array $result): string
{
    if ($state['print']) {
        return $result['total'] > LIST_PRINT_LIMIT
            ? '<p class="table-note">Only the first ' . e(format_number(LIST_PRINT_LIMIT)) . ' records are printed. Narrow the filters to print the rest.</p>'
            : '';
    }

    $pager = $result['pager'];
    $html = '<footer class="table-footer no-print"><p class="table-footer__info">Showing <strong>'
        . e($pager['from']) . '–' . e($pager['to']) . '</strong> of <strong>' . e(format_number($result['total'])) . '</strong> record(s)';
    if ($state['has_filters']) {
        $html .= ' <span class="text-muted">(filtered from ' . e(format_number($result['grand_total'])) . ')</span>';
    }
    $html .= '</p>';

    $html .= '<form method="get" action="' . e(url($state['config']['path'])) . '" class="per-page">';
    foreach ($state['query'] as $key => $value) {
        if ($key !== 'per_page') {
            $html .= '<input type="hidden" name="' . e($key) . '" value="' . e($value) . '">';
        }
    }
    $html .= '<label for="per_page">Rows</label><select id="per_page" name="per_page" data-auto-submit>';
    foreach (LIST_PER_PAGE_OPTIONS as $option) {
        $html .= '<option value="' . $option . '"' . ($option === $state['per_page'] ? ' selected' : '') . '>' . $option . '</option>';
    }
    $html .= '</select><noscript><button type="submit" class="btn btn--sm btn--outline">Apply</button></noscript></form>';

    if ($pager['pages'] > 1) {
        $html .= '<nav class="pagination" aria-label="Pagination">';
        $html .= $pager['page'] > 1
            ? '<a href="' . e(list_url($state, ['page' => $pager['page'] - 1])) . '" class="pagination__link" rel="prev">Previous</a>'
            : '<span class="pagination__link is-disabled" aria-disabled="true">Previous</span>';
        foreach (pagination_links($pager['page'], $pager['pages']) as $number) {
            if ($number === null) {
                $html .= '<span class="pagination__gap" aria-hidden="true">…</span>';
            } elseif ($number === $pager['page']) {
                $html .= '<span class="pagination__link is-current" aria-current="page">' . $number . '</span>';
            } else {
                $html .= '<a href="' . e(list_url($state, ['page' => $number])) . '" class="pagination__link">' . $number . '</a>';
            }
        }
        $html .= $pager['page'] < $pager['pages']
            ? '<a href="' . e(list_url($state, ['page' => $pager['page'] + 1])) . '" class="pagination__link" rel="next">Next</a>'
            : '<span class="pagination__link is-disabled" aria-disabled="true">Next</span>';
        $html .= '</nav>';
    }

    return $html . '</footer>';
}

function render_empty_state(array $state, ?string $createUrl, string $emptyMessage, string $iconName = 'folder'): string
{
    $html = '<div class="empty-state"><div class="empty-state__icon">' . icon($state['has_filters'] ? 'search' : $iconName, 'icon icon--xl') . '</div>';

    if ($state['has_filters']) {
        $html .= '<h3>No matching records found.</h3><p class="text-muted">Try another search term or clear the filters.</p>'
            . '<a href="' . e(url($state['config']['path'], $state['config']['keep'] ?? [])) . '" class="btn btn--outline no-print">' . icon('x') . ' <span>Clear Search</span></a>';
    } else {
        $html .= '<h3>No records available.</h3><p class="text-muted">' . e($emptyMessage) . '</p>';
        if ($createUrl !== null) {
            $html .= '<a href="' . e($createUrl) . '" class="btn btn--primary no-print">' . icon('plus') . ' <span>Add New</span></a>';
        }
    }

    return $html . '</div>';
}

/* ------------------------------------------------------------------
 * Small UI pieces
 * ------------------------------------------------------------------ */

/**
 * Status label with text (never colour alone). $tones maps value => success|warning|danger|info|muted.
 */
function status_badge(?string $value, array $tones = []): string
{
    if ($value === null || $value === '') {
        return '<span class="text-muted">—</span>';
    }

    $tone = $tones[$value] ?? 'muted';

    return '<span class="status status--' . e($tone) . '">' . e(humanize($value)) . '</span>';
}

function active_badge(int|string|null $isActive): string
{
    return (int) $isActive === 1
        ? '<span class="status status--success">' . icon('check', 'icon icon--sm') . ' Active</span>'
        : '<span class="status status--muted">' . icon('x', 'icon icon--sm') . ' Inactive</span>';
}

function icon_link(string $href, string $iconName, string $label, bool $danger = false): string
{
    return '<a href="' . e($href) . '" class="icon-button' . ($danger ? ' icon-button--danger' : '') . '" title="' . e($label) . '" aria-label="' . e($label) . '">'
        . icon($iconName) . '</a>';
}

/**
 * A POST form with CSRF token and hidden fields, rendered as one button.
 * With $confirm, the shared confirmation modal asks first.
 */
function post_button(string $action, array $hidden, string $label, string $iconName, string $class = 'btn btn--outline', ?string $confirm = null, bool $iconOnly = false, ?string $confirmTitle = null): string
{
    $html = '<form method="post" action="' . e($action) . '" class="inline-form"'
        . ($confirm !== null ? ' data-confirm="' . e($confirm) . '"' : '')
        . ($confirmTitle !== null ? ' data-confirm-title="' . e($confirmTitle) . '" data-confirm-button="' . e($label) . '"' : '')
        . '>' . csrf_field();
    foreach ($hidden as $name => $value) {
        $html .= '<input type="hidden" name="' . e($name) . '" value="' . e($value) . '">';
    }

    if ($iconOnly) {
        $html .= '<button type="submit" class="' . e($class) . '" title="' . e($label) . '" aria-label="' . e($label) . '">' . icon($iconName) . '</button>';
    } else {
        $html .= '<button type="submit" class="' . e($class) . '" data-loading-text="Please wait…">' . icon($iconName) . ' <span class="btn__label">' . e($label) . '</span></button>';
    }

    return $html . '</form>';
}

/**
 * Definition list rows for view pages: label => already-escaped HTML.
 */
function render_details(array $rows): string
{
    $html = '<dl class="detail-list">';
    foreach ($rows as $label => $valueHtml) {
        $html .= '<div><dt>' . e($label) . '</dt><dd>' . ($valueHtml === '' || $valueHtml === null ? '<span class="text-muted">—</span>' : $valueHtml) . '</dd></div>';
    }

    return $html . '</dl>';
}

/**
 * Escaped text or a muted dash.
 */
function show(mixed $value): string
{
    return ($value === null || $value === '') ? '<span class="text-muted">—</span>' : e($value);
}

function show_money(mixed $value): string
{
    return ($value === null || $value === '') ? '<span class="text-muted">—</span>' : e(format_money($value));
}

function show_multiline(?string $value): string
{
    return ($value === null || $value === '') ? '<span class="text-muted">—</span>' : '<span class="pre-line">' . e($value) . '</span>';
}

/**
 * Link to a related record's view page, or a dash.
 */
function related_link(?string $id, ?string $label, string $viewPath): string
{
    if ($id === null || $id === '') {
        return '<span class="text-muted">—</span>';
    }

    return '<a href="' . e(url($viewPath, ['id' => $id])) . '">' . e($label ?? $id) . '</a>';
}

/**
 * Load a record by UUID from ?id= (or POST id) or go back to the list.
 */
function find_or_redirect(?array $row, string $listPath, string $noun = 'Record'): array
{
    if ($row === null) {
        flash('error', $noun . ' not found. It may have been deleted.');
        redirect($listPath);
    }

    return $row;
}

function request_id(): string
{
    // POST forms send the id in the body; edit forms keep it in the URL.
    $id = input_string($_POST, 'id');
    if ($id === '') {
        $id = input_string($_GET, 'id');
    }

    return is_valid_uuid($id) ? $id : '';
}

function borrower_name(array $row, string $prefix = ''): string
{
    $first = $row[$prefix . 'first_name'] ?? '';
    $last  = $row[$prefix . 'last_name'] ?? '';

    return trim($first . ' ' . $last);
}

/**
 * Attached documents panel used by transactions, borrower payments and agreements
 * (junction tables transaction_attachments, payment_attachments, agreement_documents).
 *
 * @param array  $rows       each: link_id, document_id, original_filename, document_type, type_label (attachment type)
 * @param string $actionUrl  POST target handling attach_document / detach_document
 * @param array  $hidden     hidden fields identifying the parent record
 */
function render_attachments_panel(array $rows, string $actionUrl, array $hidden, bool $typeRequired, bool $canEdit = true): string
{
    $html = '<section class="panel subpanel"><header class="panel__header"><h3>' . icon('folder') . ' Attached documents</h3>';
    if ($canEdit) {
        $html .= '<button type="button" class="btn btn--sm btn--outline no-print" data-toggle-panel="attach-document">' . icon('plus') . ' <span>Attach</span></button>';
    }
    $html .= '</header>';

    if ($canEdit) {
        $documents = document_options();
        $html .= '<form method="post" action="' . e($actionUrl) . '" class="inline-panel no-print" id="attach-document" hidden novalidate data-validate>' . csrf_field()
            . '<input type="hidden" name="action" value="attach_document">';
        foreach ($hidden as $name => $value) {
            $html .= '<input type="hidden" name="' . e($name) . '" value="' . e($value) . '">';
        }
        if (!$documents) {
            $html .= '<p class="field-help">No uploaded documents yet. <a href="' . e(url('pages/documents/create.php')) . '">Upload a file</a> first.</p>';
        } else {
            $html .= render_fields([
                'document_id'     => ['label' => 'Document', 'type' => 'select', 'options' => $documents, 'required' => true, 'empty_label' => 'Select an uploaded file'],
                'attachment_type' => ['label' => 'Attachment type', 'type' => 'text', 'max' => 100, 'required' => $typeRequired, 'placeholder' => 'e.g. receipt, proof of payment'],
            ], [], []);
            $html .= '<div class="inline-panel__actions"><a class="btn btn--outline" href="' . e(url('pages/documents/create.php')) . '">' . icon('plus') . ' <span>Upload new file</span></a>'
                . '<button type="submit" class="btn btn--primary" data-loading-text="Attaching…">' . icon('check') . ' <span class="btn__label">Attach</span></button></div>';
        }
        $html .= '</form>';
    }

    if (!$rows) {
        return $html . '<p class="empty-inline">No documents attached.</p></section>';
    }

    $html .= '<ul class="child-list">';
    foreach ($rows as $row) {
        $html .= '<li><span><a href="' . e(url('pages/documents/view.php', ['id' => $row['document_id']])) . '">' . e($row['original_filename']) . '</a>'
            . ' <span class="text-muted">· ' . e(humanize($row['type_label'] ?? $row['document_type'])) . '</span></span>';
        if ($canEdit) {
            $html .= post_button($actionUrl, $hidden + ['action' => 'detach_document', 'item_id' => $row['link_id']], 'Remove attachment', 'x',
                'icon-button icon-button--danger', 'Remove this attachment? The file itself stays in Document Files.', true, 'Remove attachment?');
        }
        $html .= '</li>';
    }

    return $html . '</ul></section>';
}
