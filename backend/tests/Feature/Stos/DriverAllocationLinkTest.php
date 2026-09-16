<?php

namespace Tests\Feature\Stos;

use App\Domains\Fleet\Models\DriverProfile;
use App\Domains\Fleet\Models\Vehicle;
use App\Domains\Fleet\Models\VehicleLiveStatus;
use App\Domains\Fleet\Services\DriverService;
use App\Domains\Fleet\Services\FleetService;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * STOS-FLEET + STOS-CMP — a vehicle is only as dispatchable as the person who
 * drives it.
 *
 * The rule this locks down: an expired driver licence LOWERS the pairing's
 * score and flags it, rather than hiding the vehicle. The truck is roadworthy;
 * swapping the driver is a smaller decision than standing it down, so the
 * planner is told and left to choose.
 */
class DriverAllocationLinkTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY = 1;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::COMPANY, 'name' => 'Co1', 'slug' => 'co1',
            'subdomain' => 'co1', 'status' => 'active',
        ])->save();
    }

    private function user(string $role = 'staff'): User
    {
        return User::create([
            'tenant_id' => self::COMPANY, 'name' => ucfirst($role), 'role' => $role,
            'email' => $role.'-'.Str::random(6).'@test.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function vehicle(string $plate): Vehicle
    {
        $vehicle = Vehicle::create([
            'company_id' => self::COMPANY, 'registration_number' => $plate,
            'vehicle_type' => 'reefer', 'gps_device_id' => 'DEV-'.Str::random(6),
            'status' => 'active', 'compliance_status' => 'compliant',
        ]);

        VehicleLiveStatus::create([
            'vehicle_id' => $vehicle->id, 'company_id' => self::COMPANY,
            'latitude' => '19.07609500', 'longitude' => '72.87765800',
            'speed' => '0.00', 'ignition' => false, 'last_ping_at' => now()->subMinute(),
        ]);

        return $vehicle;
    }

    /** A worker in the CRM directory, exactly as the customer directory holds them. */
    private function person(string $name): int
    {
        $vendorId = DB::table('vendors')->insertGetId([
            'tenant_id' => self::COMPANY, 'company_name' => 'Sharma Transport',
            'vendor_code' => 'V-'.Str::random(5), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('tpv_workers')->insertGetId([
            'tenant_id' => self::COMPANY, 'vendor_id' => $vendorId,
            'worker_code' => 'W-'.Str::random(6), 'name' => $name,
            'designation' => 'Driver', 'mobile' => '9876500000',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function assignDriver(int $personId, Vehicle $vehicle, ?string $licenceExpiry, string $status = 'available'): void
    {
        $drivers = app(DriverService::class);
        $drivers->saveProfile(self::COMPANY, 'crm_tpv_worker', $personId, [
            'licence_number' => 'MH01-'.$personId,
            'licence_class'  => 'HMV',
            'licence_expiry' => $licenceExpiry,
            'status'         => $status,
        ], 1);
        $drivers->assignToVehicle(self::COMPANY, 'crm_tpv_worker', $personId, $vehicle->id, 1);
    }

    private function eligible(): array
    {
        return app(FleetService::class)->getEligibleVehicles(self::COMPANY, 'REEFER')['eligible'];
    }

    /* ── The rule the task asks for ─────────────────────────────── */

    public function test_an_expired_licence_lowers_the_score_and_flags_the_pairing(): void
    {
        $good = $this->vehicle('MH12GOOD01');
        $bad  = $this->vehicle('MH12EXPD01');

        $this->assignDriver($this->person('Valid Vikram'), $good, now()->addYear()->toDateString());
        $this->assignDriver($this->person('Expired Rajesh'), $bad, now()->subDay()->toDateString());

        $rows = collect($this->eligible())->keyBy('registration_number');

        // Flagged, in the token Developer 1's board switches on.
        $this->assertContains('DRIVER_LICENSE_EXPIRED', $rows['MH12EXPD01']['flags']);
        $this->assertSame([], $rows['MH12GOOD01']['flags']);

        // Scored down, not hidden: the truck is still roadworthy.
        $this->assertLessThan($rows['MH12GOOD01']['score'], $rows['MH12EXPD01']['score']);
        $this->assertSame(0.0, $rows['MH12EXPD01']['scores']['driver']);
        $this->assertSame(1.0, $rows['MH12GOOD01']['scores']['driver']);

        // And the compliant pairing is what gets recommended.
        $this->assertSame('MH12GOOD01', $this->eligible()[0]['registration_number']);
    }

    public function test_the_vehicle_is_still_offered_despite_an_expired_driver(): void
    {
        $vehicle = $this->vehicle('MH12ONLY01');
        $this->assignDriver($this->person('Expired Rajesh'), $vehicle, now()->subMonth()->toDateString());

        $rows = $this->eligible();

        // Excluding it would hide the only truck in the yard over a problem
        // solved by handing the keys to somebody else.
        $this->assertCount(1, $rows);
        $this->assertSame('MH12ONLY01', $rows[0]['registration_number']);
        $this->assertContains('DRIVER_LICENSE_EXPIRED', $rows[0]['flags']);
    }

    /* ── The states around it ───────────────────────────────────── */

    public function test_driver_compliance_travels_beside_vehicle_compliance(): void
    {
        $vehicle = $this->vehicle('MH12PAIR01');
        $this->assignDriver($this->person('Valid Vikram'), $vehicle, now()->addYear()->toDateString());

        $row = $this->eligible()[0];

        // Both halves of "can this go out today", in one payload.
        $this->assertSame('compliant', $row['compliance_status']);
        $this->assertSame('Valid Vikram', $row['driver']['name']);
        $this->assertSame('valid', $row['driver']['licence']['state']);
        $this->assertNotNull($row['driver']['profile']['licence_number']);
        $this->assertStringContainsString('Valid Vikram', implode(' ', $row['reasons']));
    }

    public function test_a_vehicle_with_no_regular_driver_sits_between_the_two(): void
    {
        $none = $this->vehicle('MH12NONE01');
        $good = $this->vehicle('MH12GOOD02');
        $bad  = $this->vehicle('MH12EXPD02');

        $this->assignDriver($this->person('Valid Vikram'), $good, now()->addYear()->toDateString());
        $this->assignDriver($this->person('Expired Rajesh'), $bad, now()->subDay()->toDateString());

        $rows = collect($this->eligible())->keyBy('registration_number');

        // Whoever is free takes the next load — not as good as a known
        // compliant pairing, not as bad as somebody who cannot legally drive.
        $this->assertContains('NO_DRIVER_ASSIGNED', $rows['MH12NONE01']['flags']);
        $this->assertNull($rows['MH12NONE01']['driver']);
        $this->assertLessThan($rows['MH12GOOD02']['score'], $rows['MH12NONE01']['score']);
        $this->assertGreaterThan($rows['MH12EXPD02']['score'], $rows['MH12NONE01']['score']);
    }

    public function test_an_expiring_licence_is_flagged_without_being_treated_as_expired(): void
    {
        $vehicle = $this->vehicle('MH12SOON01');
        $this->assignDriver($this->person('Soon Suresh'), $vehicle, now()->addDays(9)->toDateString());

        $row = $this->eligible()[0];

        $this->assertContains('DRIVER_LICENSE_EXPIRING', $row['flags']);
        $this->assertNotContains('DRIVER_LICENSE_EXPIRED', $row['flags']);
        // Still usable today, so it outscores an expired pairing.
        $this->assertGreaterThan(0.0, $row['scores']['driver']);
    }

    public function test_an_unrecorded_licence_is_flagged_but_does_not_read_as_expired(): void
    {
        $vehicle = $this->vehicle('MH12UNRC01');
        $this->assignDriver($this->person('Unknown Umesh'), $vehicle, null);

        $row = $this->eligible()[0];

        $this->assertContains('DRIVER_LICENSE_UNRECORDED', $row['flags']);
        $this->assertSame('unknown', $row['driver']['licence']['state']);
    }

    public function test_a_suspended_driver_is_flagged_unavailable(): void
    {
        $vehicle = $this->vehicle('MH12SUSP01');
        $this->assignDriver($this->person('Suspended Sam'), $vehicle, now()->addYear()->toDateString(), 'suspended');

        $row = $this->eligible()[0];

        $this->assertContains('DRIVER_UNAVAILABLE', $row['flags']);
        $this->assertSame(0.0, $row['scores']['driver']);
    }

    /* ── Assignment mechanics ───────────────────────────────────── */

    public function test_one_vehicle_keeps_one_regular_driver(): void
    {
        $vehicle = $this->vehicle('MH12SWAP01');
        $first = $this->person('First Driver');
        $second = $this->person('Second Driver');

        $this->assignDriver($first, $vehicle, now()->addYear()->toDateString());
        $this->assignDriver($second, $vehicle, now()->addYear()->toDateString());

        // The newcomer displaces the incumbent: two people recorded as "the"
        // driver of one truck is a question nobody can answer.
        $this->assertSame(1, DriverProfile::where('assigned_vehicle_id', $vehicle->id)->count());
        $this->assertSame($second, DriverProfile::where('assigned_vehicle_id', $vehicle->id)->first()->source_id);
    }

    public function test_a_driver_can_be_taken_off_a_vehicle(): void
    {
        $vehicle = $this->vehicle('MH12CLR001');
        $personId = $this->person('Temporary Tim');
        $this->assignDriver($personId, $vehicle, now()->addYear()->toDateString());

        $this->actingAs($this->user())
            ->putJson("/api/v1/fleet/drivers/crm_tpv_worker/{$personId}/assign", ['vehicle_id' => null])
            ->assertOk();

        $this->assertNull(DriverProfile::first()->assigned_vehicle_id);
        $this->assertContains('NO_DRIVER_ASSIGNED', $this->eligible()[0]['flags']);
    }

    public function test_a_driver_cannot_be_assigned_to_another_companys_vehicle(): void
    {
        (new Tenant())->forceFill([
            'id' => 2, 'name' => 'Co2', 'slug' => 'co2', 'subdomain' => 'co2', 'status' => 'active',
        ])->save();

        $theirs = Vehicle::create([
            'company_id' => 2, 'registration_number' => 'MH99THEM01',
            'vehicle_type' => 'truck', 'ownership_type' => 'owned',
        ]);
        $personId = $this->person('My Driver');

        $this->actingAs($this->user())
            ->putJson("/api/v1/fleet/drivers/crm_tpv_worker/{$personId}/assign", ['vehicle_id' => $theirs->id])
            ->assertNotFound();

        $this->assertNull(DriverProfile::first()?->assigned_vehicle_id);
    }

    /* ── The passport shows it ──────────────────────────────────── */

    public function test_the_passport_carries_the_assigned_driver_and_their_licence(): void
    {
        $vehicle = $this->vehicle('MH12PASS01');
        $this->assignDriver($this->person('Passport Pramod'), $vehicle, now()->addMonths(2)->toDateString());

        $driver = $this->actingAs($this->user())
            ->getJson("/api/v1/fleet/vehicles/{$vehicle->id}/passport")
            ->assertOk()->json('data.driver');

        // Name resolved through the directory, licence from the STOS overlay.
        $this->assertSame('Passport Pramod', $driver['name']);
        $this->assertSame('HMV', $driver['profile']['licence_class']);
        $this->assertNotNull($driver['profile']['licence_expiry']);
        $this->assertSame('valid', $driver['licence']['state']);
        $this->assertTrue($driver['in_directory']);
    }

    public function test_a_driver_removed_from_the_directory_is_reported_not_rendered_blank(): void
    {
        $vehicle = $this->vehicle('MH12GONE01');
        $personId = $this->person('Departed Dev');
        $this->assignDriver($personId, $vehicle, now()->addYear()->toDateString());

        // They leave the vendor, and the CRM record goes.
        DB::table('tpv_workers')->where('id', $personId)->delete();

        $driver = $this->actingAs($this->user())
            ->getJson("/api/v1/fleet/vehicles/{$vehicle->id}/passport")->json('data.driver');

        // The overlay survives; the screen says what happened rather than
        // showing an empty name.
        $this->assertFalse($driver['in_directory']);
        $this->assertSame('No longer in the directory', $driver['name']);
    }
}
