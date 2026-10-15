<?php
/**
 * Agreements module (tables: agreements, agreement_signatories, agreement_documents).
 *
 * The agreements table stores the terms, not the text: the printable text is
 * produced from the selected template with {{placeholders}} filled in.
 * Generated/signed copies are uploaded files (documents) linked to the agreement.
 * Delete only drafts; otherwise cancel.
 */

defined('APP_ROOT') or exit('No direct access.');

const AGREEMENT_LOCKED_STATUSES = ['completed', 'cancelled'];

function agreement_fields(?array $current = null): array
{
    [$txnOptions, $txnData] = transaction_options($current['transaction_id'] ?? null);
    [$oblOptions, $oblData] = obligation_options($current['obligation_id'] ?? null);
    // Each choice carries its recorded terms; picking it fills the Terms section.
    foreach (agreement_terms_by_obligation(array_keys($oblOptions)) as $id => $terms) {
        $oblData[$id] = ($oblData[$id] ?? []) + agreement_terms_data($terms);
    }
    foreach (agreement_terms_by_transaction(array_keys($txnOptions)) as $id => $terms) {
        $txnData[$id] = ($txnData[$id] ?? []) + agreement_terms_data($terms);
    }
    $fills = 'f_original_amount:original,f_installment_amount:installment,f_installment_count:count,f_agreed_charges:charges,f_payment_terms:terms';

    return [
        'agreement_number'      => ['label' => 'Agreement number', 'type' => 'text', 'max' => 50, 'required' => $current !== null,
                                    'unique' => ['agreements', 'agreement_number', 'This agreement number is already used.'],
                                    'help' => $current === null ? 'Leave blank to generate one automatically.' : ''],
        'borrower_id'           => ['label' => 'Borrower', 'type' => 'select', 'options' => borrower_options($current['borrower_id'] ?? null), 'required' => true, 'empty_label' => 'Select borrower'],
        'template_id'           => ['label' => 'Template', 'type' => 'select', 'options' => agreement_template_options($current['template_id'] ?? null), 'empty_label' => '— No template —'],
        'agreement_date'        => ['label' => 'Agreement date', 'type' => 'date', 'required' => true, 'initial' => date('Y-m-d')],
        'status'                => ['label' => 'Status', 'type' => 'select', 'options' => enum_options(ENUM_AGREEMENT_STATUS), 'required' => true, 'initial' => 'draft'],
        'transaction_id'        => ['label' => 'Transaction', 'type' => 'select', 'options' => $txnOptions, 'option_data' => $txnData,
                                    'attrs' => ['data-depends-on' => 'f_borrower_id', 'data-fills' => 'f_obligation_id:obligation,' . $fills], 'empty_label' => '— None —', 'section' => 'Covers',
                                    'help' => 'Picking one fills in the terms already recorded for it.'],
        'obligation_id'         => ['label' => 'Obligation', 'type' => 'select', 'options' => $oblOptions, 'option_data' => $oblData,
                                    'attrs' => ['data-depends-on' => 'f_borrower_id', 'data-fills' => $fills], 'empty_label' => '— None —'],
        'original_amount'       => ['label' => 'Original amount', 'type' => 'decimal', 'min' => 0, 'section' => 'Terms'],
        'installment_amount'    => ['label' => 'Installment amount', 'type' => 'decimal', 'min' => 0],
        'installment_count'     => ['label' => 'Number of installments', 'type' => 'int', 'min' => 1, 'max' => 360],
        'agreed_charges'        => ['label' => 'Agreed charges', 'type' => 'decimal', 'min' => 0, 'default' => '0.00'],
        'payment_terms'         => ['label' => 'Payment terms', 'type' => 'textarea', 'rows' => 4],
        'generated_document_id' => ['label' => 'Generated copy (file)', 'type' => 'select', 'options' => document_options($current['generated_document_id'] ?? null), 'empty_label' => '— None —', 'section' => 'Files'],
        'signed_document_id'    => ['label' => 'Signed copy (file)', 'type' => 'select', 'options' => document_options($current['signed_document_id'] ?? null), 'empty_label' => '— None —'],
    ];
}

/**
 * Terms already recorded for an obligation: its amounts and its schedule (the
 * installment plan of its purchase, or its own monthly schedule). Nothing is
 * recalculated - the numbers are read from what was saved.
 *
 * @return array{obligation: ?string, original: string, installment: ?string, count: ?int, charges: string, terms: string}
 */
function agreement_terms_obligation(array $o): array
{
    [$rows] = obligation_schedule_view($o);
    $charges = (float) $o['agreed_interest'] + (float) $o['agreed_fees'];

    return [
        'obligation'  => $o['id'],
        'original'    => number_format((float) $o['original_amount'], 2, '.', ''),
        'installment' => $rows ? number_format((float) $rows[0]['amount_due'], 2, '.', '') : null,
        'count'       => $rows ? count($rows) : null,
        'charges'     => number_format($charges, 2, '.', ''),
        'terms'       => agreement_terms_text($rows, (float) $o['total_amount_due'], $charges, 'monthly', $o['due_date']),
    ];
}

/** Terms of a purchase: its obligation's when it has one, else its installment plan, else one payment. */
function agreement_terms_transaction(array $t, ?array $obligation, ?array $plan): array
{
    if ($obligation) {
        return agreement_terms_obligation($obligation);
    }
    if ($plan) {
        $rows = db_all('SELECT amount_due, due_date FROM installment_schedules WHERE installment_plan_id = :p ORDER BY installment_number', [':p' => $plan['id']]);
        $charges = (float) $plan['interest_amount'] + (float) $plan['fees_amount'];

        return [
            'obligation'  => null,
            'original'    => number_format((float) $plan['total_principal'], 2, '.', ''),
            'installment' => number_format((float) $plan['installment_amount'], 2, '.', ''),
            'count'       => (int) $plan['number_of_installments'],
            'charges'     => number_format($charges, 2, '.', ''),
            'terms'       => agreement_terms_text($rows, (float) $plan['total_repayment'], $charges, (string) $plan['frequency'], $plan['end_date']),
        ];
    }
    $amount = number_format((float) $t['amount'], 2, '.', '');

    return ['obligation' => null, 'original' => $amount, 'installment' => $amount, 'count' => 1, 'charges' => '0.00',
            'terms' => 'One payment of ' . format_money($amount) . '.'];
}

/** "12 monthly installments of ₱6,514.50 (Jan 16, 2026 to Dec 16, 2026). Total to pay: ₱78,174.00, including ₱4,374.00 agreed charges." */
function agreement_terms_text(array $rows, float $total, float $charges, string $frequency, ?string $dueDate): string
{
    $count = count($rows);
    if ($count === 0) {
        $text = 'Total to pay: ' . format_money($total) . ($dueDate ? ', due ' . format_date($dueDate) : '') . '.';
    } else {
        $first = (float) $rows[0]['amount_due'];
        $last = (float) $rows[$count - 1]['amount_due'];
        $word = $frequency === 'monthly' || $frequency === '' ? 'monthly' : $frequency;
        $text = $count === 1
            ? 'One payment of ' . format_money($first) . ' on ' . format_date($rows[0]['due_date']) . '.'
            : (abs($first - $last) < 0.005
                ? $count . ' ' . $word . ' installments of ' . format_money($first)
                : ($count - 1) . ' ' . $word . ' installments of ' . format_money($first) . ' and a last one of ' . format_money($last))
              . ' (' . format_date($rows[0]['due_date']) . ' to ' . format_date($rows[$count - 1]['due_date']) . ').';
        $text .= ' Total to pay: ' . format_money($total) . '.';
    }
    if ($charges > 0.004) {
        $text = rtrim($text, '.') . ', including ' . format_money($charges) . ' agreed charges.';
    }

    return $text;
}

/** Recorded terms of these obligations, by id. */
function agreement_terms_by_obligation(array $ids): array
{
    $terms = [];
    foreach (array_chunk(array_values(array_filter($ids, 'is_valid_uuid')), 200) as $chunk) {
        $in = sql_in_list($chunk);   // validated UUIDs only
        $params = [];
        foreach (db_all("SELECT * FROM borrower_obligations WHERE id IN ($in)", $params) as $o) {
            $terms[$o['id']] = agreement_terms_obligation($o);
        }
    }

    return $terms;
}

/** Recorded terms of these purchases, by id (with the obligation that covers it, when only one does). */
function agreement_terms_by_transaction(array $ids): array
{
    $terms = [];
    foreach (array_chunk(array_values(array_filter($ids, 'is_valid_uuid')), 200) as $chunk) {
        $in = sql_in_list($chunk);   // validated UUIDs only
        $params = [];
        $obligations = [];
        foreach (db_all("SELECT * FROM borrower_obligations WHERE transaction_id IN ($in) AND status <> 'cancelled'", $params) as $o) {
            $obligations[$o['transaction_id']][] = $o;
        }
        $plans = [];
        foreach (db_all("SELECT * FROM installment_plans WHERE transaction_id IN ($in) AND status <> 'cancelled'", $params) as $p) {
            $plans[$p['transaction_id']] = $p;
        }
        foreach (db_all("SELECT id, amount FROM transactions WHERE id IN ($in)", $params) as $t) {
            $own = count($obligations[$t['id']] ?? []) === 1 ? $obligations[$t['id']][0] : null;
            $terms[$t['id']] = agreement_terms_transaction($t, $own, $plans[$t['id']] ?? null);
        }
    }

    return $terms;
}

/** Terms as option data attributes (data-original, data-terms ...). */
function agreement_terms_data(array $terms): array
{
    return array_filter([
        'obligation' => $terms['obligation'], 'original' => $terms['original'], 'installment' => $terms['installment'],
        'count' => $terms['count'] === null ? null : (string) $terms['count'], 'charges' => $terms['charges'], 'terms' => $terms['terms'],
    ], static fn ($v) => $v !== null && $v !== '');
}

function agreement_validate(array $fields, array $input, ?string $id = null): array
{
    [$data, $errors] = validate_fields($fields, $input, $id);

    // Terms left empty are taken from the chosen obligation or transaction, as recorded.
    $source = null;
    if (!$errors && $data['obligation_id']) {
        $source = agreement_terms_by_obligation([$data['obligation_id']])[$data['obligation_id']] ?? null;
    } elseif (!$errors && $data['transaction_id']) {
        $source = agreement_terms_by_transaction([$data['transaction_id']])[$data['transaction_id']] ?? null;
    }
    if ($source) {
        $data['original_amount'] ??= $source['original'];
        $data['installment_amount'] ??= $source['installment'];
        $data['installment_count'] ??= $source['count'];
        if ($data['payment_terms'] === null || $data['payment_terms'] === '') {
            $data['payment_terms'] = $source['terms'];
        }
        if ((float) ($data['agreed_charges'] ?? 0) === 0.0 && (float) $source['charges'] > 0) {
            $data['agreed_charges'] = $source['charges'];
        }
    }

    foreach (['transaction_id' => 'transactions', 'obligation_id' => 'borrower_obligations'] as $field => $table) {
        if ($data[$field] && $data['borrower_id'] && !belongs_to($table, $data[$field], 'borrower_id', $data['borrower_id'])) {
            $errors[$field] = 'This record must belong to the selected borrower.';
        }
    }

    return [$data, $errors];
}

function agreement_find(string $id): ?array
{
    if (!is_valid_uuid($id)) {
        return null;
    }

    return db_row(
        "SELECT a.*, b.first_name, b.middle_name, b.last_name, b.suffix, b.borrower_number,
                t.name AS template_name, t.version AS template_version, t.template_body,
                tx.transaction_number, o.obligation_number,
                gd.original_filename AS generated_filename, sd.original_filename AS signed_filename,
                COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.email) AS created_by_name
           FROM agreements a
           JOIN borrowers b ON b.id = a.borrower_id
           LEFT JOIN agreement_templates t ON t.id = a.template_id
           LEFT JOIN transactions tx ON tx.id = a.transaction_id
           LEFT JOIN borrower_obligations o ON o.id = a.obligation_id
           LEFT JOIN documents gd ON gd.id = a.generated_document_id
           LEFT JOIN documents sd ON sd.id = a.signed_document_id
           LEFT JOIN users u ON u.id = a.created_by
          WHERE a.id = :id AND " . owner_scope('agreements', 'a'),
        [':id' => $id]
    );
}

/**
 * Agreement text from its template with the placeholders filled in.
 */
function agreement_text(array $a): ?string
{
    if (!$a['template_body']) {
        return null;
    }

    // The lender is the owner of the agreement's borrower.
    $owner = db_value(
        "SELECT COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.email)
           FROM users u JOIN borrowers b ON b.owner_user_id = u.id WHERE b.id = :b",
        [':b' => $a['borrower_id']]
    );

    return fill_template($a['template_body'], [
        'borrower_name'      => trim($a['first_name'] . ' ' . ($a['middle_name'] ? $a['middle_name'] . ' ' : '') . $a['last_name'] . ($a['suffix'] ? ' ' . $a['suffix'] : '')),
        'borrower_number'    => $a['borrower_number'],
        'agreement_number'   => $a['agreement_number'],
        'date'               => format_date($a['agreement_date']),
        'original_amount'    => format_money($a['original_amount']),
        'installment_amount' => format_money($a['installment_amount']),
        'installment_count'  => (string) $a['installment_count'],
        'agreed_charges'     => format_money($a['agreed_charges']),
        'payment_terms'      => (string) $a['payment_terms'],
        'owner_name'         => $owner ?: '',
    ]);
}

/**
 * Users who can sign as "a system user": the signed-in user only (never a list
 * of every account).
 */
function signatory_user_options(): array
{
    return db_options(
        "SELECT id, COALESCE(NULLIF(TRIM(CONCAT_WS(' ', first_name, last_name)), ''), username, email) FROM users WHERE id = :id",
        [':id' => owner_id()]
    );
}

function signatory_fields(): array
{
    return [
        'signer'   => ['label' => 'Signer', 'type' => 'select', 'options' => ['borrower' => 'The borrower', 'user' => 'A system user', 'other' => 'Someone else (witness, co-maker)'], 'required' => true, 'initial' => 'borrower'],
        'user_id'  => ['label' => 'User (if a system user)', 'type' => 'select', 'options' => signatory_user_options(), 'empty_label' => '— Not a user —'],
        'name'     => ['label' => 'Name', 'type' => 'text', 'max' => 255, 'help' => 'Filled in automatically for the borrower or a user when left blank.'],
        'role'     => ['label' => 'Role', 'type' => 'text', 'max' => 100, 'required' => true, 'placeholder' => 'e.g. borrower, lender, witness'],
        'email'    => ['label' => 'Email', 'type' => 'email', 'max' => 255],
        'mobile'   => ['label' => 'Mobile', 'type' => 'tel', 'max' => 30],
    ];
}
