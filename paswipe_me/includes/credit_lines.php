<?php
/**
 * Shared credit lines: several cards of one bank that share one credit limit
 * (e.g. BDO Visa, BDO Shopmore and BDO AMEX on one ₱1,120,000 line).
 *
 *   credit_lines            - one row per shared line: name, bank, credit_limit
 *   credit_cards.credit_line_id - the line a card belongs to (NULL = own limit)
 *   credit_cards.sub_limit  - optional cap of one card inside the line (e.g. a
 *                             cash or AMEX sub-limit); NULL = the line's limit
 *
 * A card's own credit_limit column holds its cap: the sub-limit, else the
 * line's limit, so every existing figure and the schema CHECKs keep working.
 * Each card keeps its own used credit, statements and due dates; only the
 * room left is shared:
 *
 *     line used        = sum of the used credit of the line's cards
 *     card available   = min(card cap - card used, line limit - line used)
 *     card utilization = (card cap - card available) / card cap
 *
 * A line exists only while at least two cards share it; the last card left
 * goes back to its own limit (the line's limit).
 */

defined('APP_ROOT') or exit('No direct access.');

const CREDIT_LINE_UPGRADE_MARKER = 'storage/upgrade_credit_lines.done';

/** Additive upgrade (db/upgrade_credit_lines.sql): the credit_lines table and two nullable card columns. */
function credit_line_schema_upgrade(): void
{
    if (marker_exists(CREDIT_LINE_UPGRADE_MARKER)) {
        return;
    }
    $id = db_row("SELECT CHARACTER_SET_NAME AS cs, COLLATION_NAME AS co FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'id'");
    $uuid = 'CHAR(36)' . ($id && $id['cs'] ? ' CHARACTER SET ' . $id['cs'] . ' COLLATE ' . $id['co'] : '');

    db()->exec("CREATE TABLE IF NOT EXISTS credit_lines (
        id $uuid NOT NULL PRIMARY KEY,
        owner_user_id $uuid NOT NULL,
        name VARCHAR(150) NOT NULL,
        issuer VARCHAR(150) NULL,
        credit_limit DECIMAL(15,2) NOT NULL,
        notes TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_credit_lines_owner (owner_user_id),
        CONSTRAINT fk_credit_lines_owner FOREIGN KEY (owner_user_id) REFERENCES users (id),
        CONSTRAINT chk_credit_lines_limit CHECK (credit_limit >= 0)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("ALTER TABLE credit_cards ADD COLUMN IF NOT EXISTS credit_line_id $uuid NULL AFTER credit_limit");
    db()->exec('ALTER TABLE credit_cards ADD COLUMN IF NOT EXISTS sub_limit DECIMAL(15,2) NULL AFTER credit_line_id');
    if (!(int) db_value("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE()
                          AND TABLE_NAME = 'credit_cards' AND CONSTRAINT_NAME = 'fk_credit_cards_line'")) {
        db()->exec('ALTER TABLE credit_cards ADD KEY idx_credit_cards_line (credit_line_id),
                    ADD CONSTRAINT fk_credit_cards_line FOREIGN KEY (credit_line_id) REFERENCES credit_lines (id)');
    }
    marker_write(CREDIT_LINE_UPGRADE_MARKER);
}

function credit_line_find(?string $id): ?array
{
    return $id && is_valid_uuid($id)
        ? db_row('SELECT cl.* FROM credit_lines cl WHERE cl.id = :id AND ' . owner_scope('credit_lines', 'cl'), [':id' => $id])
        : null;
}

/** Cards of a line, by nickname. */
function credit_line_cards(string $lineId): array
{
    return db_all('SELECT id, card_nickname, network, last_four, credit_limit, sub_limit, status FROM credit_cards WHERE credit_line_id = :l ORDER BY card_nickname', [':l' => $lineId]);
}

/** Own used credit of each card of a line, summed (one query). */
function credit_line_used(string $lineId): float
{
    return round((float) db_value(
        'SELECT COALESCE(SUM(cu.used), 0) FROM credit_cards c JOIN (' . card_usage_sql() . ') cu ON cu.credit_card_id = c.id WHERE c.credit_line_id = :l',
        [':l' => $lineId]
    ), 2);
}

/** A line's figures: limit, used (all its cards), available, utilization, cards. */
function credit_line_usage(array $line): array
{
    $used = credit_line_used($line['id']);
    $limit = (float) $line['credit_limit'];

    return [
        'id' => $line['id'], 'name' => $line['name'], 'credit_limit' => $limit, 'used' => $used,
        'available' => round($limit - $used, 2), 'utilization' => $limit > 0 ? round($used / $limit * 100, 2) : 0.0,
        'cards' => credit_line_cards($line['id']),
    ];
}

/** The signed-in user's lines with their figures. */
function credit_lines_mine(): array
{
    return array_map('credit_line_usage', db_all('SELECT cl.* FROM credit_lines cl WHERE ' . owner_scope('credit_lines', 'cl') . ' ORDER BY cl.name'));
}

/**
 * Choices of the card form's "Credit limit" dropdown, with option data (limit)
 * and the suggestions shown when a new card's bank and limit match another card:
 * [options, option_data, suggestions].
 */
function credit_line_choices(?array $card): array
{
    $options = ['' => 'Own limit (not shared)'];
    $data = [];
    $suggest = [];
    foreach (db_all('SELECT cl.* FROM credit_lines cl WHERE ' . owner_scope('credit_lines', 'cl') . ' ORDER BY cl.name') as $line) {
        $count = (int) db_value('SELECT COUNT(*) FROM credit_cards WHERE credit_line_id = :l', [':l' => $line['id']]);
        $options[$line['id']] = 'Shared: ' . $line['name'] . ' — ' . format_money($line['credit_limit']) . ' (' . $count . ' cards)';
        $data[$line['id']] = ['limit' => number_format((float) $line['credit_limit'], 2, '.', '')];
        $suggest[] = ['bank' => (string) bank_canonical($line['issuer']), 'limit' => number_format((float) $line['credit_limit'], 2, '.', ''),
                      'value' => $line['id'], 'label' => $line['name']];
    }
    foreach (db_all("SELECT cc.id, cc.card_nickname, cc.issuer, cc.last_four, cc.credit_limit FROM credit_cards cc
                      WHERE cc.credit_line_id IS NULL AND cc.status <> 'closed' AND cc.id <> :me AND " . owner_scope('credit_cards', 'cc') . ' ORDER BY cc.card_nickname',
                    [':me' => $card['id'] ?? '']) as $other) {
        $value = '__with:' . $other['id'];
        $options[$value] = 'Share the limit with ' . $other['card_nickname'] . ' •••• ' . $other['last_four'] . ' (new shared line)';
        $data[$value] = ['limit' => number_format((float) $other['credit_limit'], 2, '.', '')];
        $suggest[] = ['bank' => (string) bank_canonical($other['issuer']), 'limit' => number_format((float) $other['credit_limit'], 2, '.', ''),
                      'value' => $value, 'label' => $other['card_nickname'] . ' •••• ' . $other['last_four']];
    }

    return [$options, $data, $suggest];
}

/**
 * Put a card on its line after it was saved (inside the card's transaction):
 *   ''          - own limit: leaves its line (the typed limit is its own);
 *   <line id>   - joins that line; already in it: the typed limit becomes the line's limit for every card;
 *   __with:<id> - a new line with that card, at the typed limit.
 * The card's cap (credit_limit) becomes its sub-limit or the line's limit.
 * Returns notes for the user. Throws DomainException when it cannot.
 */
function credit_line_assign(string $cardId, string $choice, float $typedLimit, ?float $subLimit): array
{
    $card = db_row('SELECT * FROM credit_cards WHERE id = :id FOR UPDATE', [':id' => $cardId]);
    $oldLine = $card['credit_line_id'];
    $notes = [];

    if ($choice === '') {
        db_update('credit_cards', ['credit_line_id' => null, 'sub_limit' => null], $cardId);
        if ($oldLine) {
            $notes[] = 'This card now has its own limit of ' . format_money($typedLimit) . '.';
            credit_line_cleanup($oldLine, $notes);
        }
        return $notes;
    }

    if (str_starts_with($choice, '__with:')) {
        $partner = db_row('SELECT * FROM credit_cards cc WHERE cc.id = :id AND cc.credit_line_id IS NULL AND ' . owner_scope('credit_cards', 'cc') . ' FOR UPDATE',
            [':id' => substr($choice, 7)]);
        if (!$partner || $partner['id'] === $cardId) {
            throw new DomainException('Choose a card to share the limit with.');
        }
        $lineId = new_uuid();
        $bank = (string) bank_canonical($card['issuer']);
        db_insert('credit_lines', ['id' => $lineId, 'owner_user_id' => $card['owner_user_id'], 'name' => $bank . ' credit line',
                                   'issuer' => $bank, 'credit_limit' => number_format($typedLimit, 2, '.', '')]);
        db_update('credit_cards', ['credit_line_id' => $lineId, 'sub_limit' => null, 'credit_limit' => number_format($typedLimit, 2, '.', '')], $partner['id']);
        log_activity('create', 'credit_line', $lineId, 'Shared credit line ' . $bank . ' credit line (' . format_money($typedLimit) . '): '
            . $card['card_nickname'] . ' and ' . $partner['card_nickname']);
        $notes[] = $card['card_nickname'] . ' and ' . $partner['card_nickname'] . ' now share one limit of ' . format_money($typedLimit) . ' (' . $bank . ' credit line).';
        $line = credit_line_find($lineId) ?? db_row('SELECT * FROM credit_lines WHERE id = :id', [':id' => $lineId]);
    } else {
        $line = credit_line_find($choice);
        if (!$line) {
            throw new DomainException('Choose one of your shared credit lines.');
        }
        if ($oldLine === $line['id'] && abs($typedLimit - (float) $line['credit_limit']) >= 0.005) {
            credit_line_set_limit($line, $typedLimit);
            $notes[] = 'The shared limit of ' . $line['name'] . ' is now ' . format_money($typedLimit) . ' for every card in it.';
            $line['credit_limit'] = number_format($typedLimit, 2, '.', '');
        } elseif ($oldLine !== $line['id']) {
            $notes[] = 'This card now shares ' . $line['name'] . ' (' . format_money($line['credit_limit']) . ').';
        }
    }

    $lineLimit = (float) $line['credit_limit'];
    if ($subLimit !== null && $subLimit > $lineLimit + 0.004) {
        throw new DomainException('The card\'s sub-limit (' . format_money($subLimit) . ') cannot be more than the shared limit (' . format_money($lineLimit) . ').');
    }
    db_update('credit_cards', ['credit_line_id' => $line['id'], 'sub_limit' => $subLimit === null ? null : number_format($subLimit, 2, '.', ''),
                               'credit_limit' => number_format($subLimit ?? $lineLimit, 2, '.', '')], $cardId);
    if ($oldLine && $oldLine !== $line['id']) {
        credit_line_cleanup($oldLine, $notes);
    }
    $used = credit_line_used($line['id']);
    if ($used > $lineLimit + 0.004) {
        $notes[] = 'Together these cards already use ' . format_money($used) . ', more than the shared limit of ' . format_money($lineLimit)
            . '. Check the limit on your bank statement; new cash loans and advances on these cards are refused until there is room.';
    }

    return $notes;
}

/** A new shared limit: the line and the cap of every card in it (a sub-limit above it is lowered to it). */
function credit_line_set_limit(array $line, float $limit): void
{
    db_update('credit_lines', ['credit_limit' => number_format($limit, 2, '.', '')], $line['id']);
    foreach (credit_line_cards($line['id']) as $c) {
        $sub = $c['sub_limit'] !== null ? min((float) $c['sub_limit'], $limit) : null;
        db_update('credit_cards', ['sub_limit' => $sub === null ? null : number_format($sub, 2, '.', ''),
                                   'credit_limit' => number_format($sub ?? $limit, 2, '.', '')], $c['id']);
    }
    log_activity('update', 'credit_line', $line['id'], 'Shared limit of ' . $line['name'] . ': ' . format_money($line['credit_limit']) . ' → ' . format_money($limit),
        ['credit_limit' => $line['credit_limit']], ['credit_limit' => $limit]);
}

/** A line with fewer than two cards is not shared any more: its last card keeps the line's limit as its own. */
function credit_line_cleanup(string $lineId, array &$notes): void
{
    $cards = credit_line_cards($lineId);
    if (count($cards) >= 2) {
        return;
    }
    $line = db_row('SELECT * FROM credit_lines WHERE id = :id', [':id' => $lineId]);
    foreach ($cards as $c) {
        db_update('credit_cards', ['credit_line_id' => null, 'sub_limit' => null, 'credit_limit' => $line['credit_limit']], $c['id']);
        $notes[] = $c['card_nickname'] . ' no longer shares a limit: it keeps ' . format_money($line['credit_limit']) . ' as its own limit.';
    }
    db_exec('DELETE FROM credit_lines WHERE id = :id', [':id' => $lineId]);
    log_activity('delete', 'credit_line', $lineId, 'Shared credit line ' . $line['name'] . ' ended (fewer than two cards)', $line);
}

/** Cards sharing a line with any of these cards (to lock and recalculate them together). */
function credit_line_siblings(array $cardIds): array
{
    $cardIds = array_values(array_filter($cardIds));
    if (!$cardIds) {
        return [];
    }
    $in = implode(',', array_map(static fn ($id) => db()->quote($id), $cardIds));

    return array_column(db_all("SELECT c.id FROM credit_cards c WHERE c.credit_line_id IN (SELECT x.credit_line_id FROM credit_cards x WHERE x.id IN ($in) AND x.credit_line_id IS NOT NULL)"), 'id');
}
