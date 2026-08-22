<?php

namespace Tests\Feature\Customer;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Every source the Timeline and Activities feed reads must actually exist.
 *
 * CustomerTimelineService::from() guards on Schema::hasTable and hasColumn so
 * an uninstalled module costs one source rather than the whole screen. That
 * guard also swallows typos: `proposals` was queried on `client_id`, a column
 * it does not have (it is polymorphic on rel_type/rel_id), so the source was
 * skipped in complete silence. No error, no empty state — 22 proposals simply
 * never appeared on any customer's timeline, activities feed, or portal, and
 * the only way to notice was for someone to ask "where is my proposal?".
 *
 * This asserts each from() call names a real table AND real columns, so the
 * next wrong column name fails here instead of disappearing.
 */
class TimelineSourceCoverageTest extends TestCase
{
    use RefreshDatabase;

    private const SERVICE = __DIR__.'/../../../app/Services/Customer/CustomerTimelineService.php';

    public function test_every_timeline_source_names_real_columns(): void
    {
        $src = file_get_contents(self::SERVICE);

        // $this->from($client, 'table', 'fk', 'date_column', ...)
        preg_match_all(
            "/\\\$this->from\(\s*\\\$client\s*,\s*'([a-z0-9_]+)'\s*,\s*'([a-z0-9_]+)'\s*,\s*'([a-z0-9_]+)'/",
            $src,
            $m,
            PREG_SET_ORDER
        );

        $this->assertNotEmpty($m, 'No from() calls parsed — has the helper been renamed?');

        $broken = [];

        foreach ($m as [, $table, $fk, $dateColumn]) {
            if (! Schema::hasTable($table)) {
                // A genuinely absent module is fine — that is what the guard is for.
                continue;
            }

            foreach (['foreign key' => $fk, 'date column' => $dateColumn] as $what => $column) {
                if (! Schema::hasColumn($table, $column)) {
                    $broken[] = sprintf(
                        '%s.%s (%s) — table exists but has no such column, so this source is silently skipped',
                        $table,
                        $column,
                        $what
                    );
                }
            }
        }

        $this->assertSame([], $broken, sprintf(
            "These timeline sources name columns that do not exist. They will not error —\n".
            "they will simply never appear, which is how 22 proposals went missing:\n\n  %s\n",
            implode("\n  ", $broken)
        ));
    }

    public function test_the_parsed_source_list_covers_what_we_expect(): void
    {
        $src = file_get_contents(self::SERVICE);

        preg_match_all("/\\\$this->from\(\s*\\\$client\s*,\s*'([a-z0-9_]+)'/", $src, $m);
        $tables = array_unique($m[1]);

        // Sources reached through a dedicated method rather than from(), because
        // they need a join or a polymorphic key. Listed so that moving one out of
        // from() cannot quietly reduce coverage without this failing.
        foreach (['proposals', 'sales_payments', 'tasks', 'kickoff_meetings'] as $special) {
            $this->assertStringContainsString(
                $special,
                $src,
                "Expected a dedicated reader for {$special}."
            );
        }

        // A floor, not an exact count — adding sources should not break this.
        $this->assertGreaterThanOrEqual(
            12,
            count($tables),
            'The timeline reads fewer sources than expected; has one been dropped?'
        );
    }
}
