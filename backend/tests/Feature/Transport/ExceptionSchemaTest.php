<?php

namespace Tests\Feature\Transport;

use App\Support\Transport\ExceptionScope;
use App\Support\Transport\ExceptionSeverity;
use App\Support\Transport\ExceptionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * SNG-TRN-013 step 2 — DB-010 and STT-006's two columns.
 *
 * These assert the SHAPE against the registry rather than any behaviour. A
 * migration that quietly drops FLD-014's default, or loses IDX-008, breaks a
 * LOCKED row without breaking a single behavioural test.
 */
class ExceptionSchemaTest extends TestCase
{
    use RefreshDatabase;

    /* ══════════ DB-010 ══════════ */

    public function test_the_table_is_named_as_db_010_names_it(): void
    {
        $this->assertTrue(Schema::hasTable('trip_exceptions'));
    }

    public function test_tenancy_is_present_and_not_nullable(): void
    {
        // The single most important rule in this module. DB-010 is Tenant-scoped;
        // Step 11 writes the key as company_id, this codebase calls it tenant_id.
        $this->assertTrue(Schema::hasColumn('trip_exceptions', 'tenant_id'));

        $col = collect(Schema::getColumns('trip_exceptions'))->firstWhere('name', 'tenant_id');
        $this->assertNotNull($col);
        $this->assertFalse($col['nullable'], 'tenant_id must never be nullable');
    }

    /**
     * The declared LENGTH is asserted against the migration source, not the
     * live schema: the suite runs on sqlite, which reports every string column
     * as plain "varchar" with no length, so a VARCHAR(20) that had drifted to
     * VARCHAR(255) would pass unnoticed. The migration is the artifact that has
     * to match the LOCKED registry row, so that is what is read.
     */
    private function migrationSource(): string
    {
        $path = database_path('migrations/2026_12_16_000012_create_trip_exceptions_table.php');
        $this->assertFileExists($path);

        return file_get_contents($path);
    }

    public function test_fld_014_severity_is_exactly_as_specified(): void
    {
        // VARCHAR(20) NOT NULL DEFAULT 'medium'
        $col = collect(Schema::getColumns('trip_exceptions'))->firstWhere('name', 'severity');

        $this->assertNotNull($col);
        $this->assertFalse($col['nullable'], 'FLD-014 is NOT NULL');
        $this->assertSame(ExceptionSeverity::DEFAULT, trim((string) $col['default'], "'"));

        $this->assertStringContainsString(
            "string('severity', 20)", $this->migrationSource(),
            'FLD-014 LOCKS VARCHAR(20)',
        );
    }

    public function test_fld_015_status_is_exactly_as_specified(): void
    {
        // VARCHAR(30) NOT NULL DEFAULT 'open'
        $col = collect(Schema::getColumns('trip_exceptions'))->firstWhere('name', 'status');

        $this->assertNotNull($col);
        $this->assertFalse($col['nullable'], 'FLD-015 is NOT NULL');
        $this->assertSame(ExceptionStatus::OPEN, trim((string) $col['default'], "'"));

        $this->assertStringContainsString(
            "string('status', 30)", $this->migrationSource(),
            'FLD-015 LOCKS VARCHAR(30)',
        );
    }

    public function test_every_ops_87_field_marked_built_has_somewhere_to_live(): void
    {
        // The disposition promises twelve fields are handled. This checks the
        // eleven marked 'built' actually resolve to a column (or, for
        // "transaction", to the trip link).
        $map = [
            'exception ID' => 'exception_number',
            'category'     => 'category',
            'severity'     => 'severity',
            'source'       => 'source',
            'transaction'  => 'trip_id',
            'owner'        => 'owner_id',
            'timestamp'    => 'raised_at',
            'due time'     => 'due_at',
            'resolution'   => 'resolution_note',
            'closure'      => 'resolved_at',
        ];

        foreach ($map as $field => $column) {
            $this->assertSame('built', ExceptionScope::OPS_87_DISPOSITION[$field]);
            $this->assertTrue(
                Schema::hasColumn('trip_exceptions', $column),
                "OPS §87 '{$field}' is marked built but {$column} does not exist",
            );
        }
    }

    public function test_the_two_deferred_ops_87_fields_have_no_column(): void
    {
        // D-32: no formula exists and no cost model exists. A column that can
        // only ever be NULL invites a guess and then a report that adds it up.
        $this->assertSame('deferred', ExceptionScope::OPS_87_DISPOSITION['financial impact']);
        $this->assertSame('deferred', ExceptionScope::OPS_87_DISPOSITION['customer impact']);

        foreach (['financial_impact', 'customer_impact'] as $column) {
            $this->assertFalse(
                Schema::hasColumn('trip_exceptions', $column),
                "{$column} is deferred and must not exist as a column",
            );
        }
    }

    public function test_trp_p0_012s_field_list_is_present(): void
    {
        // "Exception type; severity; cause; owner; due time"
        foreach (['category', 'severity', 'cause', 'owner_id', 'due_at'] as $c) {
            $this->assertTrue(Schema::hasColumn('trip_exceptions', $c), "TRP-P0-012 needs {$c}");
        }
    }

    public function test_the_two_locked_transitions_each_have_their_stamp(): void
    {
        // STT-015 records who acknowledged and when; STT-016 the same for resolve.
        foreach (['acknowledged_by', 'acknowledged_at', 'resolved_by', 'resolved_at'] as $c) {
            $this->assertTrue(Schema::hasColumn('trip_exceptions', $c));
        }
    }

    public function test_there_is_no_soft_delete_column(): void
    {
        // OPS §154 — an exception "must never disappear from the system".
        $this->assertFalse(Schema::hasColumn('trip_exceptions', 'deleted_at'));
    }

    public function test_the_due_time_can_be_explained_after_a_policy_change(): void
    {
        // due_at is a commitment made at a point in time; sla_minutes records
        // what it was computed from, so editing the policy later cannot make an
        // existing due time inexplicable.
        $this->assertTrue(Schema::hasColumn('trip_exceptions', 'sla_minutes'));
    }

    public function test_idx_008_exists_with_tenant_leading(): void
    {
        // INDEX (company_id, trip_id, severity, status) — tenant_id in
        // company_id's place, and leading, so a tenant-scoped read hits it.
        $index = collect(Schema::getIndexes('trip_exceptions'))
            ->firstWhere('name', 'trip_exc_idx_008');

        $this->assertNotNull($index, 'IDX-008 is LOCKED and must exist');
        $this->assertSame(['tenant_id', 'trip_id', 'severity', 'status'], $index['columns']);
    }

    public function test_the_reference_is_unique_within_a_tenant(): void
    {
        $index = collect(Schema::getIndexes('trip_exceptions'))
            ->firstWhere('name', 'trip_exc_tenant_number_uniq');

        $this->assertNotNull($index);
        $this->assertTrue($index['unique']);
        $this->assertSame(['tenant_id', 'exception_number'], $index['columns']);
    }

    public function test_every_index_leads_with_tenant(): void
    {
        // A non-tenant-leading index is an invitation to a cross-tenant scan.
        foreach (Schema::getIndexes('trip_exceptions') as $index) {
            if ($index['primary']) {
                continue;
            }

            $this->assertSame(
                'tenant_id', $index['columns'][0],
                "index {$index['name']} does not lead with tenant_id",
            );
        }
    }

    /* ══════════ STT-006's departure columns ══════════ */

    public function test_q3s_two_departure_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumn('transport_trips', 'departed_at'));
        $this->assertTrue(Schema::hasColumn('transport_trips', 'departed_by'));
    }

    public function test_nothing_beyond_those_two_was_added(): void
    {
        // Q3, verbatim: "departed_at, departed_by columns only. Nothing beyond
        // that — no GPS/telemetry/odometer/temperature." OPS §38 asks for all of
        // these; none has a data model (D-9's lesson).
        foreach ([
            'departure_odometer', 'odometer', 'departure_location', 'departure_gps',
            'gps_latitude', 'gps_longitude', 'temperature', 'genset_state', 'container_id',
        ] as $forbidden) {
            $this->assertFalse(
                Schema::hasColumn('transport_trips', $forbidden),
                "{$forbidden} is outside Q3's ruling",
            );
        }

        $this->assertSame(['departed_at', 'departed_by'], ExceptionScope::DEPARTURE_FIELDS);
    }

    public function test_departure_is_distinct_from_dispatch(): void
    {
        // dispatched_at is when the trip was RELEASED; departed_at is when it
        // actually LEFT. The gap between them is what a delay exception is about.
        $this->assertTrue(Schema::hasColumn('transport_trips', 'dispatched_at'));
        $this->assertTrue(Schema::hasColumn('transport_trips', 'departed_at'));
    }
}
