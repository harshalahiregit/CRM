<?php

namespace Tests\Feature\Stos;

use App\Domains\Fleet\Models\FastagTransaction;
use App\Domains\Fleet\Models\FuelTransaction;
use App\Domains\Fleet\Models\Genset;
use App\Domains\Fleet\Models\MaintenanceJob;
use App\Domains\Fleet\Models\TelemetryRecord;
use App\Domains\Fleet\Models\Vehicle;
use App\Domains\Fleet\Models\VehicleLiveStatus;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * STOS Sprint 1 baseline — proof the schema and models actually work, rather
 * than the assumption that they do.
 *
 * Covers the four rules that are expensive to get wrong later: every table
 * carries company_id, the two telemetry tiers stay separate, money and GPS keep
 * their exact precision, and one workspace cannot read another's rows.
 */
class FleetBaselineSchemaTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY = 1;
    private const OTHER_COMPANY = 2;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::COMPANY, self::OTHER_COMPANY] as $id) {
            (new Tenant())->forceFill([
                'id' => $id, 'name' => "Co{$id}", 'slug' => "co{$id}",
                'subdomain' => "co{$id}", 'status' => 'active',
            ])->save();
        }
    }

    private function vehicle(int $companyId = self::COMPANY, array $over = []): Vehicle
    {
        return Vehicle::create(array_merge([
            'company_id'          => $companyId,
            'registration_number' => 'MH12AB'.random_int(1000, 9999),
            'vehicle_type'        => 'reefer',
            'ownership_type'      => 'owned',
            'gps_device_id'       => 'DEV-'.Str::random(8),
        ], $over));
    }

    public function test_every_sprint_one_table_exists_and_carries_company_id(): void
    {
        $tables = [
            'vehicles', 'gensets', 'vehicle_live_status', 'telemetry_records',
            'fuel_transactions', 'fastag_transactions', 'maintenance_jobs',
        ];

        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} was not created");
            $this->assertTrue(
                Schema::hasColumn($table, 'company_id'),
                "{$table} has no company_id — golden rule 1"
            );
        }
    }

    public function test_the_vehicle_master_holds_no_live_telemetry_columns(): void
    {
        // Golden rule 3: position never lands on the master row.
        foreach (['latitude', 'longitude', 'speed', 'last_ping_at', 'ignition', 'temperature'] as $column) {
            $this->assertFalse(
                Schema::hasColumn('vehicles', $column),
                "vehicles.{$column} exists — live state belongs in vehicle_live_status"
            );
        }
    }

    public function test_a_vehicle_owns_one_live_row_and_many_historical_ones(): void
    {
        $vehicle = $this->vehicle();

        // Tier 1 — overwritten in place. Two pings, still one row.
        foreach ([['22.30', 5], ['22.40', 41]] as [$lat, $speed]) {
            VehicleLiveStatus::updateOrCreate(
                ['vehicle_id' => $vehicle->id],
                [
                    'company_id' => self::COMPANY,
                    'latitude' => $lat, 'longitude' => '70.80000000',
                    'speed' => $speed, 'ignition' => true,
                    'generator_status' => 'on', 'temperature' => '-18.50',
                    'last_ping_at' => now(),
                ]
            );

            // Tier 2 — appended, never replaced.
            TelemetryRecord::create([
                'company_id' => self::COMPANY,
                'vehicle_id' => $vehicle->id,
                'device_id'  => $vehicle->gps_device_id,
                'latitude'   => $lat, 'longitude' => '70.80000000',
                'speed'      => $speed, 'ignition' => true,
                'generator_status' => 'on', 'temperature' => '-18.50',
                'recorded_at' => now(),
            ]);
        }

        $this->assertSame(1, VehicleLiveStatus::where('vehicle_id', $vehicle->id)->count());
        $this->assertSame(2, $vehicle->telemetryRecords()->count());
        $this->assertSame('41.00', (string) $vehicle->liveStatus->speed);
        // A frozen load runs below zero — the column has to be signed.
        $this->assertSame('-18.50', (string) $vehicle->liveStatus->temperature);
        // Append-only: created_at is stamped, updated_at does not exist.
        $this->assertNull(TelemetryRecord::UPDATED_AT);
        $this->assertNotNull(TelemetryRecord::first()->created_at);
    }

    public function test_gps_and_money_keep_their_exact_precision(): void
    {
        $vehicle = $this->vehicle();

        VehicleLiveStatus::create([
            'vehicle_id' => $vehicle->id, 'company_id' => self::COMPANY,
            'latitude' => '19.07609500', 'longitude' => '72.87765800',
            'last_ping_at' => now(),
        ]);

        $live = VehicleLiveStatus::find($vehicle->id);
        $this->assertSame('19.07609500', (string) $live->latitude);   // 8 dp
        $this->assertSame('72.87765800', (string) $live->longitude);  // 8 dp

        $fuel = FuelTransaction::create([
            'company_id' => self::COMPANY, 'vehicle_id' => $vehicle->id,
            'litres' => '412.755', 'rate_per_litre' => '94.37', 'amount' => '38951.71',
            'odometer' => '184320.5', 'station_vendor' => 'HP Nashik Bypass',
        ]);

        $this->assertSame('38951.71', (string) $fuel->fresh()->amount);
        $this->assertSame('412.755', (string) $fuel->fresh()->litres);

        $job = MaintenanceJob::create([
            'company_id' => self::COMPANY, 'vehicle_id' => $vehicle->id,
            'job_card_number' => 'JC-0001', 'complaint' => 'Reefer not holding temp',
            'parts_cost' => '12500.00', 'labour_cost' => '3000.00', 'total_cost' => '15000.00',
        ]);

        // total_cost is what the signed card says, not parts + labour.
        $this->assertSame('15000.00', (string) $job->fresh()->total_cost);
    }

    public function test_a_re_imported_toll_statement_cannot_double_count(): void
    {
        $vehicle = $this->vehicle();
        $crossing = [
            'company_id' => self::COMPANY, 'vehicle_id' => $vehicle->id,
            'tag_id' => 'TAG-9981', 'plaza_name' => 'Kherdi',
            'amount' => '830.00', 'transaction_timestamp' => '2026-09-14 11:42:07',
        ];

        FastagTransaction::create($crossing);

        $this->expectException(QueryException::class);
        FastagTransaction::create($crossing);
    }

    public function test_a_genset_survives_the_vehicle_it_was_fitted_to(): void
    {
        $vehicle = $this->vehicle();
        $genset = Genset::create([
            'company_id' => self::COMPANY, 'serial_number' => 'GEN-4471',
            'vehicle_id' => $vehicle->id, 'status' => 'active',
        ]);

        $this->assertTrue($genset->vehicle->is($vehicle));

        // Force-deleting the truck must not take the power unit with it.
        $vehicle->forceDelete();

        $this->assertDatabaseHas('gensets', ['id' => $genset->id, 'vehicle_id' => null]);
    }

    public function test_one_company_never_sees_another_companys_fleet(): void
    {
        $mine = $this->vehicle(self::COMPANY, ['registration_number' => 'MH12AB0001']);
        $theirs = $this->vehicle(self::OTHER_COMPANY, ['registration_number' => 'MH12AB0002']);

        $scoped = Vehicle::forCompany(self::COMPANY)->pluck('id');

        $this->assertTrue($scoped->contains($mine->id));
        $this->assertFalse($scoped->contains($theirs->id), 'Cross-company leak — golden rule 1');

        // The same plate may exist in both workspaces; uniqueness is per company.
        $this->vehicle(self::OTHER_COMPANY, ['registration_number' => 'MH12AB0001']);
        $this->assertSame(2, Vehicle::where('registration_number', 'MH12AB0001')->count());
    }

    public function test_company_id_is_stamped_from_the_signed_in_user(): void
    {
        $user = User::create([
            'tenant_id' => self::COMPANY, 'name' => 'Ops', 'role' => 'staff',
            'email' => 'ops-'.Str::random(6).'@test.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);

        $this->actingAs($user);

        // No company_id passed — the trait resolves it from the session.
        $vehicle = Vehicle::create(['registration_number' => 'MH12ZZ7777']);

        $this->assertSame(self::COMPANY, $vehicle->fresh()->company_id);
    }

    public function test_two_vehicles_cannot_claim_the_same_gps_device(): void
    {
        $this->vehicle(self::COMPANY, ['gps_device_id' => 'DEV-SHARED']);

        $this->expectException(QueryException::class);
        $this->vehicle(self::COMPANY, ['gps_device_id' => 'DEV-SHARED']);
    }
}
