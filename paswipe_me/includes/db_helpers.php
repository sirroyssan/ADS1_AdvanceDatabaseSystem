<?php
/**
 * Small database helpers shared by all modules.
 * Every value goes through prepared statements. Table and column names
 * only ever come from the application code (never from user input) and
 * are additionally checked against a strict pattern.
 */

defined('APP_ROOT') or exit('No direct access.');

function db_value(string $sql, array $params = []): mixed
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchColumn();
}

function db_row(string $sql, array $params = []): ?array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetch() ?: null;
}

function db_all(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

/**
 * Key => label pairs for a <select>, e.g. "SELECT id, name FROM merchants".
 */
function db_options(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

function db_exec(string $sql, array $params = []): int
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->rowCount();
}

/**
 * Quote a table/column name written in code (e.g. `condition`, a reserved word).
 */
function db_identifier(string $name): string
{
    if (!preg_match('/^[a-z_][a-z0-9_]*$/', $name)) {
        throw new InvalidArgumentException('Invalid identifier.');
    }

    return '`' . $name . '`';
}

/**
 * INSERT a row. $data keys are column names written in code.
 */
function db_insert(string $table, array $data): void
{
    $columns = array_map('db_identifier', array_keys($data));
    $holders = array_map(static fn ($i) => ':v' . $i, array_keys(array_values($data)));

    $stmt = db()->prepare(
        'INSERT INTO ' . db_identifier($table) . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $holders) . ')'
    );
    $stmt->execute(array_combine($holders, array_values($data)));
}

/**
 * UPDATE one row by its id.
 */
function db_update(string $table, array $data, string $id): void
{
    if (!$data) {
        return;
    }

    $sets = [];
    $params = [':id' => $id];
    $i = 0;
    foreach ($data as $column => $value) {
        $sets[] = db_identifier($column) . ' = :v' . $i;
        $params[':v' . $i] = $value;
        $i++;
    }

    $stmt = db()->prepare('UPDATE ' . db_identifier($table) . ' SET ' . implode(', ', $sets) . ' WHERE id = :id');
    $stmt->execute($params);
}

/**
 * Run $work inside a database transaction; roll back on any error.
 */
function db_transaction(callable $work): mixed
{
    $pdo = db();
    $pdo->beginTransaction();

    try {
        $result = $work($pdo);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Next readable reference such as "TXN-20261008-0003" for UNIQUE number columns.
 */
function generate_reference(string $prefix, string $table, string $column): string
{
    $stem = $prefix . '-' . date('Ymd') . '-';

    $last = (string) db_value(
        'SELECT MAX(' . db_identifier($column) . ') FROM ' . db_identifier($table) . ' WHERE ' . db_identifier($column) . ' LIKE :stem',
        [':stem' => $stem . '%']
    );

    $next = $last !== '' ? ((int) substr($last, strlen($stem))) + 1 : 1;

    return $stem . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
}

/**
 * Count how many rows in other tables point to a record.
 *
 * @param array<string, array{0:string,1:string}> $checks label => [table, column]
 * @return array<string,int> counts per label plus 'total'
 */
function record_usage(array $checks, string $id): array
{
    $usage = ['total' => 0];

    foreach ($checks as $label => [$table, $column]) {
        $count = (int) db_value(
            'SELECT COUNT(*) FROM ' . db_identifier($table) . ' WHERE ' . db_identifier($column) . ' = :id',
            [':id' => $id]
        );
        $usage[$label] = $count;
        $usage['total'] += $count;
    }

    return $usage;
}

/**
 * "3 transactions and 1 statement" from record_usage() output.
 */
function usage_text(array $usage): string
{
    $parts = [];
    foreach ($usage as $label => $count) {
        if ($label !== 'total' && $count > 0) {
            $parts[] = $count . ' ' . $label;
        }
    }

    if (count($parts) > 1) {
        $last = array_pop($parts);
        return implode(', ', $parts) . ' and ' . $last;
    }

    return $parts[0] ?? 'no records';
}

/**
 * Safe, user-facing message for a failed write.
 */
function db_error_message(PDOException $e, string $fallback = 'Unable to save the record. Please check the information and try again.'): string
{
    error_log('[' . APP_NAME . '] Database write failed: ' . $e->getMessage());

    $driverCode = (int) ($e->errorInfo[1] ?? 0);

    return match ($driverCode) {
        1062       => 'A record with the same unique value already exists.',
        1451       => 'This record cannot be deleted because it is currently being used by other records.',
        1452       => 'A selected related record does not exist.',
        1644       => (string) ($e->errorInfo[2] ?? $fallback),   // SIGNAL from a schema trigger
        4025, 3819 => 'The submitted values are not allowed by the database rules.',
        default    => $fallback,
    };
}
