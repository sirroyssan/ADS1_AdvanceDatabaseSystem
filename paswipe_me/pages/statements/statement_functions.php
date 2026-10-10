<?php
/**
 * Statements module (table: credit_card_statements) - the bank's actual SOA
 * per card and billing period. The card details and the period's records come
 * from the database (includes/statement_engine.php); only the SOA figures are
 * entered here. UNIQUE (credit_card_id, statement_date) and
 * (credit_card_id, cutoff_date). Payments link with credit_card_payments.statement_id.
 */

defined('APP_ROOT') or exit('No direct access.');

/**
 * The SOA form. $lockedCard: the card is already known (no need to pick it).
 */
function statement_fields(?array $current = null, bool $lockedCard = false): array
{
    $fields = [
        'credit_card_id'         => ['label' => 'Credit card', 'type' => 'select', 'options' => credit_card_options($current['credit_card_id'] ?? null, false), 'required' => true, 'empty_label' => 'Select card'],
        'cutoff_date'            => ['label' => 'Statement cutoff date', 'type' => 'date', 'required' => true,
                                     'help' => 'Last day of the billing period on the SOA. It decides the statement month.'],
        'total_amount_due'       => ['label' => 'Actual SOA amount (statement balance)', 'type' => 'decimal', 'required' => true,
                                     'help' => 'Exactly as printed on the bank\'s SOA. Kept as entered even if your records add up differently.'],
        'minimum_amount_due'     => ['label' => 'Minimum amount due', 'type' => 'decimal', 'min' => 0, 'required' => true],
        'due_date'               => ['label' => 'Payment due date', 'type' => 'date', 'required' => true],
        'statement_date'         => ['label' => 'Statement date', 'type' => 'date', 'help' => 'Date printed on the SOA. Leave blank to use the cutoff date.', 'section' => 'Optional'],
        'statement_number'       => ['label' => 'Statement number', 'type' => 'text', 'max' => 100],
        'attachment_document_id' => ['label' => 'SOA file', 'type' => 'select', 'options' => document_options($current['attachment_document_id'] ?? null), 'empty_label' => '— No file —', 'help' => 'Upload the PDF first in Document Files.'],
        'notes'                  => ['label' => 'Notes', 'type' => 'textarea', 'rows' => 2],
    ];
    if ($lockedCard) {
        unset($fields['credit_card_id']);
    }

    return $fields;
}

function statement_validate(array $fields, array $input, ?string $id = null, ?string $cardId = null): array
{
    [$data, $errors] = validate_fields($fields, $input, $id);
    $cardId = $cardId ?? $data['credit_card_id'] ?? null;
    $data['statement_date'] = $data['statement_date'] ?: $data['cutoff_date'];

    if ($data['statement_date'] && $data['cutoff_date'] && $data['statement_date'] < $data['cutoff_date']) {
        $errors['statement_date'] = 'The statement date cannot be before the cutoff date.';
    }
    if ($data['due_date'] && $data['cutoff_date'] && $data['due_date'] < $data['cutoff_date']) {
        $errors['due_date'] = 'The payment due date must be on or after the cutoff date.';
    }
    if (!isset($errors['cutoff_date']) && $cardId && $data['cutoff_date']) {
        $card = db_row('SELECT * FROM credit_cards WHERE id = :c', [':c' => $cardId]);
        if ($card) {
            // One SOA per card and billing period.
            $ctx = card_period_context($card);
            $ctx['soas'] = array_values(array_filter($ctx['soas'], static fn ($s) => $s['id'] !== $id));
            $period = card_period_containing($ctx, $data['cutoff_date']);
            $taken = db_row(
                'SELECT cutoff_date FROM credit_card_statements WHERE credit_card_id = :c AND id <> :id
                    AND (COALESCE(cutoff_date, statement_date) = :d OR statement_date = :sd OR (period_start_date IS NOT NULL AND :d2 BETWEEN period_start_date AND cutoff_date))',
                [':c' => $cardId, ':id' => $id ?? '', ':d' => $data['cutoff_date'], ':sd' => $data['statement_date'], ':d2' => $data['cutoff_date']]
            );
            if ($taken) {
                $errors['cutoff_date'] = 'An SOA is already recorded for this card and period (cutoff ' . format_date($taken['cutoff_date']) . '). Edit that one instead.';
            } else {
                $data['period_start_date'] = $period['start'];
            }
        }
    }

    return [$data, $errors];
}

function statement_find(string $id): ?array
{
    return statement_soa_find($id);
}

/**
 * Records that keep a statement. Payments always do; the old statement links
 * on bank charges and cash advances too.
 */
function statement_usage(string $id): array
{
    return record_usage([
        'card payments' => ['credit_card_payments', 'statement_id'],
        'bank charges'  => ['bank_charges', 'statement_id'],
        'cash advances' => ['cash_advances', 'statement_id'],
    ], $id);
}

function statement_label(array $s): string
{
    return $s['card_nickname'] . ' •••• ' . $s['last_four'] . ' — ' . statement_month_label($s['cutoff_date'] ?: $s['statement_date']);
}

/** Edit page of the record behind a statement line (null when it has none). */
function statement_line_edit_url(array $line): ?string
{
    parse_str((string) parse_url($line['link'], PHP_URL_QUERY), $query);
    $id = $query['id'] ?? null;
    $module = match ($line['kind']) {
        'transaction'  => 'transactions',
        'bank_charge'  => 'bank_charges',
        'cash_advance' => 'cash_advances',
        'payment'      => 'card_payments',
        'installment'  => 'installment_plans',
        'cash_loan'    => 'cash_loans',
        default        => null,
    };

    return $module && $id ? url('pages/' . $module . '/edit.php', ['id' => $id]) : null;
}

/** "Add missing …" links for a period: label => [url, icon]. */
function statement_missing_links(string $cardId, string $end): array
{
    $base = ['credit_card_id' => $cardId, 'period' => $end];

    return [
        'Purchase'                       => [url('pages/transactions/create.php', $base + ['transaction_date' => $end]), 'layers'],
        'Annual fee, interest or charge' => [url('pages/bank_charges/create.php', $base + ['charge_date' => $end]), 'receipt'],
        'Cash advance'                   => [url('pages/cash_advances/create.php', $base + ['advance_date' => $end]), 'banknote'],
        'Payment or credit'              => [url('pages/card_payments/create.php', $base + ['payment_date' => $end]), 'wallet'],
    ];
}

/** Record SOA (or edit it when recorded) for a period. */
function statement_soa_url(string $cardId, array $period): string
{
    return $period['statement_id']
        ? url('pages/statements/edit.php', ['id' => $period['statement_id']])
        : url('pages/statements/create.php', ['credit_card_id' => $cardId, 'cutoff' => $period['end']]);
}

/** View a period (recorded SOA or not). */
function statement_view_url(string $cardId, array $period): string
{
    return $period['statement_id']
        ? url('pages/statements/view.php', ['id' => $period['statement_id']])
        : url('pages/statements/view.php', ['card' => $cardId, 'start' => $period['start'], 'end' => $period['end']]);
}

/**
 * One-time token for a form that must not be saved twice (a double click or a
 * resent request): issued with the form, used up by the first successful save.
 */
function form_once_token(string $form): string
{
    $token = bin2hex(random_bytes(16));
    $_SESSION['form_once'][$form][$token] = time();
    // Keep the list short.
    $_SESSION['form_once'][$form] = array_slice($_SESSION['form_once'][$form], -20, null, true);

    return $token;
}

function form_once_use(string $form, string $token): bool
{
    if ($token === '' || !isset($_SESSION['form_once'][$form][$token])) {
        return false;
    }
    unset($_SESSION['form_once'][$form][$token]);

    return true;
}

/**
 * Card and period shown above the SOA / payment forms (taken from the
 * database, nothing to type): card, issuer, period and what the records add up to.
 */
function statement_card_context(array $card, ?array $period, array $extra = []): string
{
    $rows = ['Credit card' => e($card['card_nickname'] . ' — ' . $card['issuer'] . ' •••• ' . $card['last_four'])];
    if ($period) {
        $summary = statement_lines_summary(statement_period_lines($card['id'], $period['start'], $period['end']));
        $rows['Statement month'] = e(statement_month_label($period['end'])) . ' <span class="text-muted">(' . e(format_date($period['start'])) . ' – ' . e(format_date($period['end'])) . ')</span>';
        $rows['Your records in this period'] = e($summary['count']) . ' record(s) · charges ' . e(format_money($summary['charges']))
            . ' · payments and credits ' . e(format_money($summary['payments']));
    }

    return '<div class="recon-system">' . render_details($rows + $extra) . '</div>';
}

/**
 * Billing period of a recorded SOA. Older statements entered before cutoff
 * dates were required use their statement date.
 */
function statement_period_of(array $statement, array $card): array
{
    $end = $statement['cutoff_date'] ?: $statement['statement_date'];
    $start = $statement['period_start_date'] ?: card_period_containing(card_period_context($card), $end)['start'];

    return ['start' => $start, 'end' => $end, 'statement_id' => $statement['id']];
}
