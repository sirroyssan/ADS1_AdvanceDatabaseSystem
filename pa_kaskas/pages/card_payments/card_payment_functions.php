<?php
/**
 * Card Payments module (table: credit_card_payments) - payments the owner
 * makes to the bank. Separate from borrower payments (schema note #4).
 *
 * No delete: this is a financial history record and the table has no
 * status/void column (see README: database limitations). Mistakes are
 * corrected with Edit, which is recorded in the activity log.
 */

defined('APP_ROOT') or exit('No direct access.');

function card_payment_fields(?array $current = null): array
{
    [$statementOptions, $statementData] = statement_options();
    [$methodOptions, $methodData] = payment_method_options(true, $current['payment_method_id'] ?? null);

    return [
        'credit_card_id'        => ['label' => 'Credit card', 'type' => 'select', 'options' => credit_card_options($current['credit_card_id'] ?? null, false), 'required' => true, 'empty_label' => 'Select card'],
        'statement_id'          => ['label' => 'Statement', 'type' => 'select', 'options' => $statementOptions, 'option_data' => $statementData,
                                    'attrs' => ['data-depends-on' => 'f_credit_card_id'], 'empty_label' => '— Not linked to a statement —'],
        'payment_date'          => ['label' => 'Payment date', 'type' => 'date', 'required' => true, 'initial' => date('Y-m-d')],
        'amount'                => ['label' => 'Amount', 'type' => 'decimal', 'required' => true, 'min_exclusive' => 0],
        'payment_type'          => ['label' => 'Payment type', 'type' => 'text', 'max' => 100, 'help' => 'e.g. full balance, minimum, partial.'],
        'payment_method_id'     => ['label' => 'Paid from (owner account)', 'type' => 'select', 'options' => $methodOptions, 'empty_label' => '— Not specified —'],
        'payment_channel'       => ['label' => 'Channel', 'type' => 'text', 'max' => 100, 'help' => 'e.g. online banking, over the counter, GCash bills pay.'],
        'source_account_masked' => ['label' => 'Source account (masked)', 'type' => 'text', 'max' => 100],
        'reference_number'      => ['label' => 'Reference number', 'type' => 'text', 'max' => 150],
        'receipt_document_id'   => ['label' => 'Receipt file', 'type' => 'select', 'options' => document_options($current['receipt_document_id'] ?? null), 'empty_label' => '— No file —'],
        'notes'                 => ['label' => 'Notes', 'type' => 'textarea', 'rows' => 3],
    ];
}

function card_payment_validate(array $fields, array $input, ?string $id = null): array
{
    [$data, $errors] = validate_fields($fields, $input, $id);

    if ($data['statement_id'] && $data['credit_card_id'] && !belongs_to('credit_card_statements', $data['statement_id'], 'credit_card_id', $data['credit_card_id'])) {
        $errors['statement_id'] = 'The statement must belong to the selected card.';
    }

    return [$data, $errors];
}

function card_payment_find(string $id): ?array
{
    if (!is_valid_uuid($id)) {
        return null;
    }

    return db_row(
        'SELECT p.*, c.card_nickname, c.last_four, s.statement_date, pm.display_name AS method_name, d.original_filename
           FROM credit_card_payments p
           JOIN credit_cards c ON c.id = p.credit_card_id
           LEFT JOIN credit_card_statements s ON s.id = p.statement_id
           LEFT JOIN payment_methods pm ON pm.id = p.payment_method_id
           LEFT JOIN documents d ON d.id = p.receipt_document_id
          WHERE p.id = :id AND ' . owner_scope('credit_card_payments', 'p'),
        [':id' => $id]
    );
}

/* ------------------------------------------------------------------
 * Void a card payment (audit): the payment leaves the live table, so every
 * total (card usage, statements, reports) drops it at once, and a full copy
 * is kept in card_payment_voids with the reason, who and when. Nothing is
 * erased without a trace. Same SQL as db/upgrade_card_payment_voids.sql.
 * ------------------------------------------------------------------ */

const CARD_PAYMENT_VOIDS_MARKER = 'storage/upgrade_card_payment_voids.done';

function card_payment_voids_upgrade(): void
{
    if (marker_exists(CARD_PAYMENT_VOIDS_MARKER)) {
        return;
    }
    $col = db_row(
        "SELECT CHARACTER_SET_NAME AS cs, COLLATION_NAME AS co FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'credit_cards' AND COLUMN_NAME = 'id'"
    );
    $like = $col && $col['cs'] && $col['co'] ? ' CHARACTER SET ' . $col['cs'] . ' COLLATE ' . $col['co'] : '';
    $table = db_row(
        "SELECT t.ENGINE AS engine, t.TABLE_COLLATION AS co, c.CHARACTER_SET_NAME AS cs
           FROM information_schema.TABLES t JOIN information_schema.COLLATION_CHARACTER_SET_APPLICABILITY c ON c.COLLATION_NAME = t.TABLE_COLLATION
          WHERE t.TABLE_SCHEMA = DATABASE() AND t.TABLE_NAME = 'credit_card_payments'"
    );
    $options = $table ? ' ENGINE=' . $table['engine'] . ' DEFAULT CHARSET=' . $table['cs'] . ' COLLATE=' . $table['co'] : ' ENGINE=InnoDB';
    db()->exec(
        "CREATE TABLE IF NOT EXISTS card_payment_voids (
            id CHAR(36)$like NOT NULL PRIMARY KEY,
            payment_id CHAR(36)$like NOT NULL,
            credit_card_id CHAR(36)$like NOT NULL,
            payment_date DATE NOT NULL,
            amount DECIMAL(15,2) NOT NULL,
            reference_number VARCHAR(150) NULL,
            payment_type VARCHAR(100) NULL,
            snapshot LONGTEXT NOT NULL,
            void_reason TEXT NOT NULL,
            voided_by CHAR(36)$like NULL,
            voided_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_card_payment_void (payment_id),
            KEY idx_card_payment_voids_card (credit_card_id, voided_at),
            CONSTRAINT fk_card_payment_void_card FOREIGN KEY (credit_card_id) REFERENCES credit_cards (id),
            CONSTRAINT fk_card_payment_void_user FOREIGN KEY (voided_by) REFERENCES users (id)
        )$options"
    );
    marker_write(CARD_PAYMENT_VOIDS_MARKER);
}

/** Void one payment with a reason. Returns an error, or null. */
function card_payment_void(array $payment, string $reason, array $user): ?string
{
    card_payment_voids_upgrade();
    if (($payment['payment_type'] ?? '') === AUTO_CARD_PAYMENT_TYPE) {
        return 'This payment was recorded automatically from borrower payment ' . ($payment['reference_number'] ?: '') . '. It follows that payment: reject or void the borrower payment instead.';
    }
    $row = db_row('SELECT * FROM credit_card_payments WHERE id = :id', [':id' => $payment['id']]);
    if (!$row) {
        return 'This payment was already voided.';
    }
    card_usage_apply([$row['credit_card_id']], static function () use ($row, $reason, $user) {
        db_insert('card_payment_voids', [
            'id' => new_uuid(), 'payment_id' => $row['id'], 'credit_card_id' => $row['credit_card_id'], 'payment_date' => $row['payment_date'],
            'amount' => $row['amount'], 'reference_number' => $row['reference_number'], 'payment_type' => $row['payment_type'],
            'snapshot' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'void_reason' => $reason, 'voided_by' => $user['id'],
        ]);
        // A prepared statement line that pointed to it goes too (the statement is prepared again without it).
        db_exec("DELETE FROM statement_items WHERE source_type = 'card_payment' AND source_id = :id", [':id' => $row['id']]);
        db_exec('DELETE FROM credit_card_payments WHERE id = :id', [':id' => $row['id']]);
    });
    log_activity('void', 'card_payment', $row['id'], 'Voided card payment of ' . format_money($row['amount']) . ' (' . format_date($row['payment_date']) . '): ' . $reason,
        $row, ['reason' => $reason]);

    return null;
}

/** Voided payments of the signed-in owner's cards, newest first. */
function card_payment_voids_list(int $limit = 50): array
{
    card_payment_voids_upgrade();

    return db_all(
        "SELECT v.*, c.card_nickname, c.last_four, COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.email) AS voided_by_name
           FROM card_payment_voids v JOIN credit_cards c ON c.id = v.credit_card_id LEFT JOIN users u ON u.id = v.voided_by
          WHERE " . owner_scope('credit_cards', 'c') . ' ORDER BY v.voided_at DESC LIMIT ' . (int) $limit
    );
}
