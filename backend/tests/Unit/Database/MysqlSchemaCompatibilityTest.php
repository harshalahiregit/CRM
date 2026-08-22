<?php

namespace Tests\Unit\Database;

use PHPUnit\Framework\TestCase;

/**
 * The suite runs on SQLite; production is MySQL. SQLite accepts almost any
 * schema you hand it, so a migration can be green in every test and every CI
 * run and still abort halfway through `migrate` on the live box — leaving some
 * tables created, the migration row unwritten, and the deploy stuck.
 *
 * That has already cost one deploy (a 65-character index name). This guards the
 * other three ways the same thing happens. None of them can be caught by
 * running the suite, and none of them need a database to detect: they are
 * decidable from the migration source.
 *
 * At the time of writing the codebase violates none of these, so there is no
 * grandfathered list — anything this test reports is new work, and is a genuine
 * production failure rather than a style preference.
 *
 * @see MigrationIdentifierLengthTest for the 64-character identifier limit.
 */
class MysqlSchemaCompatibilityTest extends TestCase
{
    /** Column types MySQL stores off-row: cannot be defaulted, cannot be indexed whole. */
    private const OFF_ROW_TYPES = [
        'text', 'mediumText', 'longText', 'json', 'jsonb', 'binary', 'blob',
    ];

    /** InnoDB's maximum index key length in bytes (utf8mb4, DYNAMIC row format). */
    private const MAX_INDEX_BYTES = 3072;

    /**
     * Bytes each column type contributes to an index key. utf8mb4 charges four
     * bytes per character, which is what makes wide composite string indexes
     * fail on MySQL and nowhere else.
     */
    private const BYTES = [
        'bigInteger' => 8, 'unsignedBigInteger' => 8, 'foreignId' => 8, 'id' => 8,
        'integer' => 4, 'unsignedInteger' => 4, 'mediumInteger' => 3,
        'smallInteger' => 2, 'tinyInteger' => 1, 'boolean' => 1,
        'date' => 3, 'dateTime' => 8, 'timestamp' => 8, 'time' => 3,
        'decimal' => 8, 'float' => 4, 'double' => 8, 'year' => 1,
        'uuid' => 144, 'ulid' => 104, 'ipAddress' => 180, 'macAddress' => 68,
    ];

    public function test_no_off_row_column_is_given_a_default(): void
    {
        $bad = [];

        foreach ($this->columnsAndIndexes() as $file => $parsed) {
            foreach ($parsed['columns'] as $name => $col) {
                if (! in_array($col['type'], self::OFF_ROW_TYPES, true)) {
                    continue;
                }
                if (! $col['hasDefault']) {
                    continue;
                }

                $bad[] = sprintf(
                    '%s:%d  %s.%s is %s() with ->default()',
                    $file, $col['line'], $parsed['table'] ?? '?', $name, $col['type']
                );
            }
        }

        $this->assertSame([], $bad, sprintf(
            "MySQL rejects a default on a TEXT, JSON or BLOB column outright:\n".
            "  \"BLOB, TEXT, GEOMETRY or JSON column can't have a default value\"\n".
            "SQLite allows it, so the suite passes and `migrate` dies on production.\n\n".
            "Drop the ->default() and set the value in the model (\$attributes, or a\n".
            "cast with a default), or make the column nullable and read null as the\n".
            "empty case:\n\n  %s\n",
            implode("\n  ", $bad)
        ));
    }

    public function test_no_off_row_column_is_indexed_without_a_prefix_length(): void
    {
        $bad = [];

        foreach ($this->columnsAndIndexes() as $file => $parsed) {
            foreach ($parsed['indexes'] as $idx) {
                foreach ($idx['columns'] as $column) {
                    $type = $parsed['columns'][$column]['type'] ?? null;

                    if ($type === null || ! in_array($type, self::OFF_ROW_TYPES, true)) {
                        continue;
                    }

                    $bad[] = sprintf(
                        '%s:%d  %s(%s) covers %s, which is %s()',
                        $file, $idx['line'], $idx['kind'],
                        implode(', ', $idx['columns']), $column, $type
                    );
                }
            }
        }

        $this->assertSame([], $bad, sprintf(
            "MySQL cannot index a TEXT, JSON or BLOB column without a prefix length:\n".
            "  \"BLOB/TEXT column used in key specification without a key length\"\n".
            "SQLite indexes it happily, so this only fails on production.\n\n".
            "Either narrow the column to string(n) if the content really is short,\n".
            "add a generated column holding the prefix and index that, or drop the\n".
            "index and search the column another way:\n\n  %s\n",
            implode("\n  ", $bad)
        ));
    }

    public function test_no_composite_index_exceeds_the_innodb_key_length(): void
    {
        $bad = [];

        foreach ($this->columnsAndIndexes() as $file => $parsed) {
            foreach ($parsed['indexes'] as $idx) {
                $bytes = 0;

                foreach ($idx['columns'] as $column) {
                    $col = $parsed['columns'][$column] ?? null;

                    // A column defined in an earlier migration — its width is
                    // not knowable from this file, so do not guess a verdict.
                    if ($col === null) {
                        continue 2;
                    }

                    if (in_array($col['type'], ['string', 'char'], true)) {
                        $bytes += ($col['length'] ?? 255) * 4;   // utf8mb4
                    } elseif (isset(self::BYTES[$col['type']])) {
                        $bytes += self::BYTES[$col['type']];
                    } else {
                        continue 2;                              // unknown type
                    }
                }

                if ($bytes > self::MAX_INDEX_BYTES) {
                    $bad[] = sprintf(
                        '%s:%d  %s(%s) needs %d bytes',
                        $file, $idx['line'], $idx['kind'],
                        implode(', ', $idx['columns']), $bytes
                    );
                }
            }
        }

        $this->assertSame([], $bad, sprintf(
            "InnoDB caps an index key at %d bytes, and utf8mb4 charges 4 bytes per\n".
            "character — so string(255) costs 1020 bytes, and four of them in one\n".
            "index will not create. SQLite has no such limit.\n\n".
            "Shorten the columns to what they actually hold (an email is 191, a GST\n".
            "number is 15), or index fewer of them:\n\n  %s\n",
            self::MAX_INDEX_BYTES,
            implode("\n  ", $bad)
        ));
    }

    /**
     * Parse every migration into its column declarations and its index calls.
     *
     * Deliberately reads the source rather than booting the framework: the
     * failures above are properties of the written schema, so a parse is both
     * sufficient and immune to the suite's SQLite driver papering over them.
     *
     * @return array<string, array{table: ?string, columns: array<string, array>, indexes: array<int, array>}>
     */
    private function columnsAndIndexes(): array
    {
        $out = [];

        foreach (glob(__DIR__.'/../../../database/migrations/*.php') as $path) {
            $file    = basename($path);
            $table   = null;
            $columns = [];
            $indexes = [];

            foreach (file($path) as $i => $line) {
                $lineNo = $i + 1;

                if (preg_match("/Schema::(?:create|table)\('([a-z0-9_]+)'/", $line, $m)) {
                    $table = $m[1];
                }

                // $table->string('name', 120)->nullable()->default('x')
                // preg_match_ALL: nothing in the tree declares two columns on
                // one line today, but one that did would be silently skipped,
                // and a guard that under-reports is worse than no guard.
                if (preg_match_all("/\\\$\w+->(\w+)\(\s*'([a-z0-9_]+)'\s*(?:,\s*(\d+))?/", $line, $ms, PREG_SET_ORDER)) {
                    foreach ($ms as $m) {
                        $columns[$m[2]] = [
                            'type'       => $m[1],
                            'length'     => isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null,
                            'line'       => $lineNo,
                            'hasDefault' => str_contains($line, '->default('),
                        ];
                    }
                }

                // $table->index(['a','b'])  /  $table->unique('a')
                foreach (['index', 'unique'] as $kind) {
                    if (preg_match("/\\\$\w+->{$kind}\(\s*\[([^\]]*)\]/", $line, $m)) {
                        $cols = array_values(array_filter(array_map(
                            fn ($c) => trim(trim($c), "'\" "),
                            explode(',', $m[1])
                        )));
                    } elseif (preg_match("/\\\$\w+->{$kind}\(\s*'([a-z0-9_]+)'\s*\)/", $line, $m)) {
                        $cols = [$m[1]];
                    } else {
                        continue;
                    }

                    if ($cols !== []) {
                        $indexes[] = ['kind' => $kind, 'columns' => $cols, 'line' => $lineNo];
                    }
                }
            }

            $out[$file] = ['table' => $table, 'columns' => $columns, 'indexes' => $indexes];
        }

        return $out;
    }
}
