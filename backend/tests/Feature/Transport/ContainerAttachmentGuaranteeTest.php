<?php

namespace Tests\Feature\Transport;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * STOS-CTD §7 — "reuse allowed HISTORICALLY but NOT SIMULTANEOUSLY".
 *
 * This asserts the DATABASE refuses a second attachment, not that an index
 * exists and not that a service checks first. A service check cannot deliver
 * this: two concurrent requests both read "no active attachment", both pass,
 * and both insert.
 *
 * ── WHY THERE ARE TWO CLASSES OF TEST IN HERE ────────────────────────────
 * phpunit.xml runs the suite on sqlite :memory:. Production is MySQL. A green
 * suite therefore proves the guarantee holds in the engine that does NOT run
 * the business (D-51).
 *
 * So the guarantee is asserted twice:
 *
 *   1. on whatever engine the suite is using — sqlite in CI, so the mechanism
 *      is covered on every run;
 *   2. against MySQL — the engine production uses. That lives in a SEPARATE
 *      class, ContainerAttachmentMysqlTest, and the separation is not tidiness.
 *      `use RefreshDatabase` is declared at CLASS level, so a MySQL test sharing
 *      this class would inherit it and run migrate:fresh against the working
 *      database. A test that destroys production data to prove production is
 *      safe is not a test.
 */
class ContainerAttachmentGuaranteeTest extends TestCase
{
    /* ══════════ 1. the mechanism, on whatever engine is running ══════════ */

    use RefreshDatabase;

    private function attach(int $containerId, ?string $detachedAt = null, int $tenant = 1): int
    {
        return DB::table('transport_consignment_containers')->insertGetId([
            'tenant_id'      => $tenant,
            'consignment_id' => 1,
            'container_id'   => $containerId,
            'attached_at'    => now(),
            'detached_at'    => $detachedAt,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }

    public function test_the_generated_column_and_its_unique_index_exist(): void
    {
        $this->assertTrue(Schema::hasColumn('transport_consignment_containers', 'active_container_key'));

        $index = collect(Schema::getIndexes('transport_consignment_containers'))
            ->firstWhere('name', 'transport_cc_active_uniq');

        $this->assertNotNull($index, 'the guarantee has no index behind it');
        $this->assertTrue($index['unique']);
        $this->assertSame(['tenant_id', 'active_container_key'], $index['columns']);
    }

    public function test_a_second_attachment_of_an_attached_container_is_refused(): void
    {
        $this->attach(77);

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->attach(77);
    }

    public function test_history_is_allowed_once_the_first_is_detached(): void
    {
        $first = $this->attach(77);

        DB::table('transport_consignment_containers')
            ->where('id', $first)->update(['detached_at' => now()->subDay()]);

        $second = $this->attach(77);

        $this->assertSame(
            2, DB::table('transport_consignment_containers')->where('container_id', 77)->count(),
            'the detached row must survive — it IS the history CTD §7 requires',
        );
        $this->assertNotSame($first, $second);
    }

    public function test_many_detached_rows_for_one_container_coexist(): void
    {
        foreach (range(1, 4) as $i) {
            $this->attach(77, now()->subDays($i)->toDateTimeString());
        }

        $this->assertSame(4, DB::table('transport_consignment_containers')->where('container_id', 77)->count());
    }

    public function test_another_tenant_may_attach_the_same_container(): void
    {
        // The unique key leads with tenant_id, so the rule is per workspace.
        $this->attach(77, tenant: 1);
        $this->attach(77, tenant: 2);

        $this->assertSame(2, DB::table('transport_consignment_containers')->where('container_id', 77)->count());
    }

    public function test_detaching_frees_the_container_for_a_different_consignment(): void
    {
        $first = $this->attach(77);

        DB::table('transport_consignment_containers')->where('id', $first)
            ->update(['detached_at' => now()]);

        DB::table('transport_consignment_containers')->insert([
            'tenant_id' => 1, 'consignment_id' => 2, 'container_id' => 77,
            'attached_at' => now(), 'detached_at' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(
            1,
            DB::table('transport_consignment_containers')
                ->where('container_id', 77)->whereNull('detached_at')->count(),
            'exactly one attachment may be live at a time',
        );
    }
}
