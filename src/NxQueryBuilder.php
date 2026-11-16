<?php

/**
 * NxMirror DB — official PHP SDK: the SQL query builder.
 *
 * Fluent port of the JS SDK's NxQueryBuilder: where()/orderBy()/limit()/
 * offset() compose a parameterized SELECT, insert()/update()/delete() build
 * their statements with bound parameters, count() aggregates server-side.
 *
 * Part of the crossberry-in/nxmirror-db package (https://nxmirror.netlify.app).
 */

declare(strict_types=1);

namespace NxDb;

final class NxQueryBuilder
{
    /** Friendly operator names → SQL operators (the raw operator is passed through). */
    private const OPS = [
        'eq' => '=',
        'ne' => '!=',
        'gt' => '>',
        'lt' => '<',
        'gte' => '>=',
        'lte' => '<=',
        'like' => 'LIKE',
    ];

    private NxDatabase $client;
    private string $table;

    /** @var string[] WHERE fragments, AND-combined */
    private array $where = [];

    /** @var array<int,mixed> bound parameters for the WHERE fragments */
    private array $params = [];

    private ?string $orderBy = null;
    private ?int $limit = null;
    private ?int $offset = null;

    /** @var array<int,string>|null */
    private ?array $columns = null;

    public function __construct(NxDatabase $client, string $table)
    {
        $this->client = $client;
        $this->table = $table;
    }

    /**
     * Execute the SELECT and return the full result array
     * (rows, columns, changes, executionMs, truncated, lastInsertRowid).
     * Call limit()/offset() BEFORE select().
     *
     * @param array<int,string>|null $columns
     * @return array<string,mixed>
     */
    public function select(?array $columns = null): array
    {
        $this->columns = $columns;
        return $this->run();
    }

    /** Count matching rows via SELECT COUNT(*) AS __n. */
    public function count(): int
    {
        $result = $this->client->query(
            'SELECT COUNT(*) AS __n FROM "' . $this->table . '"' . $this->whereSql(),
            $this->params
        );
        $row = $result['rows'][0] ?? null;
        return (int) (is_array($row) ? ($row['__n'] ?? 0) : 0);
    }

    /**
     * where('age', '>', 18) or where(['active' => true]) — object form is
     * AND-combined; a null value becomes IS NULL.
     *
     * Operators: eq ne gt lt gte lte like (or any raw SQL operator), "in"
     * (empty list pushes "0 = 1") and "isNull".
     */
    public function where(mixed ...$args): static
    {
        if (count($args) === 1 && is_array($args[0])) {
            foreach ($args[0] as $key => $value) {
                if ($value === null) {
                    $this->whereNull((string) $key);
                } else {
                    $this->where[] = '"' . $key . '" = ?';
                    $this->params[] = $value;
                }
            }
            return $this;
        }
        if (count($args) < 2) {
            throw new NxDbError('where() expects (column, operator, value) or a single object-style array.', 'invalid_input');
        }
        $key = $args[0];
        $op = $args[1];
        $value = $args[2] ?? null;

        if ($op === 'in') {
            if (!is_array($value) || count($value) === 0) {
                $this->where[] = '0 = 1';
                return $this;
            }
            $this->where[] = '"' . $key . '" IN (' . implode(',', array_fill(0, count($value), '?')) . ')';
            foreach ($value as $item) {
                $this->params[] = $item;
            }
            return $this;
        }
        if ($op === 'isNull') {
            $this->where[] = '"' . $key . '" IS NULL';
            return $this;
        }
        $sqlOp = self::OPS[$op] ?? $op;
        $this->where[] = '"' . $key . '" ' . $sqlOp . ' ?';
        $this->params[] = $value;
        return $this;
    }

    public function whereNull(string $key): static
    {
        $this->where[] = '"' . $key . '" IS NULL';
        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): static
    {
        $this->orderBy = '"' . $column . '" ' . (strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC');
        return $this;
    }

    public function limit(int $n): static
    {
        $this->limit = max(0, min(1000, $n));
        return $this;
    }

    public function offset(int $n): static
    {
        $this->offset = max(0, $n);
        return $this;
    }

    private function whereSql(): string
    {
        return $this->where !== [] ? ' WHERE ' . implode(' AND ', $this->where) : '';
    }

    /**
     * Insert one associative row or a list of rows (executed sequentially,
     * changes accumulated, last non-null lastInsertRowid kept).
     *
     * @param array<string,mixed>|array<int,array<string,mixed>> $data
     * @return array<string,mixed>
     */
    public function insert(array $data): array
    {
        $empty = [
            'rows' => [],
            'columns' => [],
            'changes' => 0,
            'executionMs' => 0,
            'lastInsertRowid' => null,
            'truncated' => false,
        ];
        if ($data === []) {
            return $empty;
        }
        $rows = (isset($data[0]) && is_array($data[0])) ? $data : [$data];
        $keys = array_keys($rows[0]);
        $columnsSql = implode(', ', array_map(static fn ($key) => '"' . $key . '"', $keys));
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $sql = 'INSERT INTO "' . $this->table . '" (' . $columnsSql . ') VALUES (' . $placeholders . ')';

        $result = $empty;
        foreach ($rows as $row) {
            $values = [];
            foreach ($keys as $key) {
                $values[] = $row[$key] ?? null;
            }
            $r = $this->client->query($sql, $values);
            $result['changes'] += (int) $r['changes'];
            $result['executionMs'] += (int) $r['executionMs'];
            if ($r['lastInsertRowid'] !== null) {
                $result['lastInsertRowid'] = $r['lastInsertRowid'];
            }
        }
        return $result;
    }

    /**
     * Update rows: SET from $patch, optionally WHERE from the object-form
     * $where (or a chained where()). Needs at least one column to set.
     *
     * @param array<string,mixed> $patch
     * @param array<string,mixed>|null $where
     * @return array<string,mixed>
     */
    public function update(array $patch, ?array $where = null): array
    {
        if ($where !== null) {
            $this->where($where);
        }
        $sets = [];
        foreach (array_keys($patch) as $key) {
            $sets[] = '"' . $key . '" = ?';
        }
        if ($sets === []) {
            throw new NxDbError('update() needs at least one column to set.', 'invalid_input');
        }
        $params = array_merge(array_values($patch), $this->params);
        return $this->client->query(
            'UPDATE "' . $this->table . '" SET ' . implode(', ', $sets) . $this->whereSql(),
            $params
        );
    }

    /**
     * Delete rows. REFUSES to run without a WHERE clause — pass one via
     * where() or delete(['id' => …]).
     *
     * @param array<string,mixed>|null $where
     * @return array<string,mixed>
     */
    public function delete(?array $where = null): array
    {
        if ($where !== null) {
            $this->where($where);
        }
        if ($this->where === []) {
            throw new NxDbError(
                'delete() refuses to run without a WHERE clause — pass where() or delete({ id: ... }).',
                'invalid_input'
            );
        }
        return $this->client->query('DELETE FROM "' . $this->table . '"' . $this->whereSql(), $this->params);
    }

    /** @return array<string,mixed> */
    private function run(): array
    {
        $columnsSql = $this->columns !== null
            ? implode(', ', array_map(static fn ($column) => '"' . $column . '"', $this->columns))
            : '*';
        $sql = 'SELECT ' . $columnsSql . ' FROM "' . $this->table . '"' . $this->whereSql();
        if ($this->orderBy !== null) {
            $sql .= ' ORDER BY ' . $this->orderBy;
        }
        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
        }
        if ($this->offset !== null) {
            $sql .= ' OFFSET ' . $this->offset;
        }
        return $this->client->query($sql, $this->params);
    }
}
