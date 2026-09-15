<?php

namespace Tests\Feature\Transport;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The "one live attachment per container" guarantee, proven on MySQL.
 *
 * ── WHY THIS IS A SEPARATE CLASS, AND NOT A METHOD NEXT TO THE OTHERS ────
 * `use RefreshDatabase` is declared at CLASS level. A MySQL test sitting in
 * ContainerAttachmentGuaranteeTest would inherit it and run `migrate:fresh`
 * against whatever MySQL database the connection points at — which, when you
 * run this the only way it is worth running, is the working one.
 *
 * A test that destroys the database in order to prove the database is safe is
 * not a test. Hence: no RefreshDatabase anywhere in this file, and no trait
 * that might acquire one later.
 *
 * ── WHY IT EXISTS AT ALL (D-51) ──────────────────────────────────────────
 * phpunit.xml sets DB_CONNECTION=sqlite and DB_DATABASE=:memory:. Production is
 * MySQL 8. So every green suite proves the schema behaves in an engine that
 * does not run the business. For most columns that gap is harmless. For a
 * CONSTRAINT it is not: a partial index, for instance, works on sqlite and does
 * not exist in MySQL, so it would pass every test and enforce nothing.
 *
 * The mechanism here — a STORED generated column holding container_id only
 * while detached_at IS NULL, with a unique index over it — was probed against
 * MySQL 8.0.46 and sqlite 3.45.1 before the migration was written, and behaves
 * identically on both. This test is what keeps that true.
 *
 * ── HOW TO RUN IT ────────────────────────────────────────────────────────
 *   DB_CONNECTION=mysql DB_DATABASE=<your database> php artisan test \
 *     --filter=ContainerAttachmentMysqlTest
 *
 * It skips silently on any other driver, so a normal `php artisan test` is
 * unaffected.
 *
 * ── HOW IT AVOIDS TOUCHING REAL DATA ─────────────────────────────────────
 * It writes under a tenant id no workspace holds, and deletes its own rows by
 * that tenant id in a finally block — never a blanket delete, and never a
 * truncate. It asserts the row count is unchanged at the end.
 */
class ContainerAttachmentMysqlTest extends TestCase
{
    /** A tenant id no real workspace holds. */
    private const SCRATCH_TENANT = 987654321;

    private function skipUnlessMysql(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped(
                'Runs only on MySQL — the engine production uses. The suite defaults to sqlite '
                .'(phpunit.xml), so this is skipped in CI and must be run explicitly. See D-51.'
            );
        }
    }

    private function row(int $consignmentId, ?string $detachedAt = null): array
    {
        return [
            'tenant_id'      => self::SCRATCH_TENANT,
            'consignment_id' => $consignmentId,
            'container_id'   => 424242,
            'attached_at'    => now(),
            'detached_at'    => $detachedAt,
            'created_at'     => now(),
            'updated_at'     => now(),
        ];
    }

    private function cleanUp(): void
    {
        DB::table('transport_consignment_containers')
            ->where('tenant_id', self::SCRATCH_TENANT)
            ->delete();
    }

    public function test_mysql_refuses_a_second_live_attachment(): void
    {
        $this->skipUnlessMysql();

        $before = DB::table('transport_consignment_containers')->count();

        try {
            DB::table('transport_consignment_containers')->insert($this->row(1));

            $refused = false;

            try {
                DB::table('transport_consignment_containers')->insert($this->row(2));
            } catch (QueryException) {
                $refused = true;
            }

            $this->assertTrue(
                $refused,
                'MySQL accepted a second live attachment — STOS-CTD §7\'s "not simultaneously" '
                .'does not hold in the engine that runs the business',
            );
        } finally {
            $this->cleanUp();
        }

        $this->assertSame(
            $before,
            DB::table('transport_consignment_containers')->count(),
            'this test must leave the database exactly as it found it',
        );
    }

    public function test_mysql_allows_the_history_ctd_7_requires(): void
    {
        $this->skipUnlessMysql();

        $before = DB::table('transport_consignment_containers')->count();

        try {
            // Three past attachments of the same container, plus one live.
            foreach ([3, 2, 1] as $daysAgo) {
                DB::table('transport_consignment_containers')
                    ->insert($this->row(1, now()->subDays($daysAgo)->toDateTimeString()));
            }

            DB::table('transport_consignment_containers')->insert($this->row(9));

            $rows = DB::table('transport_consignment_containers')
                ->where('tenant_id', self::SCRATCH_TENANT)->count();

            $live = DB::table('transport_consignment_containers')
                ->where('tenant_id', self::SCRATCH_TENANT)->whereNull('detached_at')->count();

            $this->assertSame(4, $rows, 'historical attachments must coexist');
            $this->assertSame(1, $live, 'exactly one may be live');
        } finally {
            $this->cleanUp();
        }

        $this->assertSame($before, DB::table('transport_consignment_containers')->count());
    }

    public function test_mysql_scopes_the_rule_per_tenant(): void
    {
        $this->skipUnlessMysql();

        $before = DB::table('transport_consignment_containers')->count();

        try {
            DB::table('transport_consignment_containers')->insert($this->row(1));
            DB::table('transport_consignment_containers')
                ->insert(['tenant_id' => self::SCRATCH_TENANT + 1] + $this->row(1));

            $this->assertSame(
                2,
                DB::table('transport_consignment_containers')
                    ->whereIn('tenant_id', [self::SCRATCH_TENANT, self::SCRATCH_TENANT + 1])->count(),
                'the unique key leads with tenant_id, so two workspaces may hold the same container',
            );
        } finally {
            $this->cleanUp();
            DB::table('transport_consignment_containers')
                ->where('tenant_id', self::SCRATCH_TENANT + 1)->delete();
        }

        $this->assertSame($before, DB::table('transport_consignment_containers')->count());
    }
}
