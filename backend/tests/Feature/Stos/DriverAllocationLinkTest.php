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

    /* ── The rule, as Person 1 ruled it ─────────────────── */

    /*
     * An expired licence used to be reported against the VEHICLE: a flag on the
     * allocation row, and a driver score of zero that pushed the truck down the
     * ranking. Person 1 ruled that this is the wrong object — the licence
     * belongs to the driver, the truck is roadworthy, and standing it down
     * offers a dispatcher a worse vehicle to solve a problem that swapping
     * drivers fixes in seconds.
     *
     * So: the vehicle stays fully eligible and unflagged, and the licence is a
     * hard block on the DRIVER, raised by DriverService::eligible().
     */

    public function test_an_expired_licence_does_not_touch_the_vehicles_ranking(): void
    {
        $good = $this->vehicle('MH12GOOD01');
        $bad  = $this->vehicle('MH12EXPD01');

        $this->assignDriver($this->person('Valid Vikram'), $good, now()->addYear()->toDateString());
        $this->assignDriver($this->person('Expired Rajesh'), $bad, now()->subDay()->toDateString());

        $rows = collect($this->eligible())->keyBy('registration_number');

        // No licence token on either vehicle — the board reads it off the driver.
        $this->assertSame([], $rows['MH12EXPD01']['flags']);
        $this->assertSame([], $rows['MH12GOOD01']['flags']);

        // And both trucks rank identically: nothing about either has expired.
        $this->assertSame($rows['MH12GOOD01']['scores']['driver'], $rows['MH12EXPD01']['scores']['driver']);
        $this->assertSame($rows['MH12GOOD01']['score'], $rows['MH12EXPD01']['score']);
    }

    public function test_the_expired_driver_is_the_one_that_is_blocked(): void
    {
        $vehicle = $this->vehicle('MH12ONLY01');
        $this->assignDriver($this->person('Expired Rajesh'), $vehicle, now()->subMonth()->toDateString());

        $result = app(\App\Domains\Fleet\Services\DriverService::class)->eligible(self::COMPANY);

        $blocked = collect($result['excluded'])->firstWhere('name', 'Expired Rajesh');

        $this->assertNotNull($blocked, 'An expired licence must block the person, not the truck');
        $this->assertSame('DRIVER_LICENSE_EXPIRED', $blocked['blockers'][0]['code']);
        // The same shape as a vehicle blocker, so one board component renders
        // both — and `owner` routes the dispatcher to the desk that clears it.
        $this->assertArrayHasKey('why', $blocked['blockers'][0]);
        $this->assertSame('Fleet compliance desk', $blocked['blockers'][0]['owner']);
    }

    public function test_the_truck_of_an_expired_driver_is_still_offered(): void
    {
        $vehicle = $this->vehicle('MH12ONLY01');
        $this->assignDriver($this->person('Expired Rajesh'), $vehicle, now()->subMonth()->toDateString());

        $rows = $this->eligible();

        // Hiding it would hide the only truck in the yard over a problem solved
        // by handing the keys to somebody else.
        $this->assertCount(1, $rows);
        $this->assertSame('MH12ONLY01', $rows[0]['registration_number']);
    }

    public function test_a_valid_driver_is_eligible_and_carries_no_blockers(): void
    {
        $this->assignDriver($this->person('Valid Vikram'), $this->vehicle('MH12PAIR02'), now()->addYear()->toDateString());

        $result = app(\App\Domains\Fleet\Services\DriverService::class)->eligible(self::COMPANY);

        $ok = collect($result['eligible'])->firstWhere('name', 'Valid Vikram');
        $this->assertNotNull($ok);
        $this->assertSame([], $ok['warnings']);
    }

    public function test_an_expiring_licence_is_a_warning_not_a_block(): void
    {
        $this->assignDriver($this->person('Soon Suresh'), $this->vehicle('MH12SOON01'), now()->addDays(9)->toDateString());

        $result = app(\App\Domains\Fleet\Services\DriverService::class)->eligible(self::COMPANY);

        // Still legal today, so still offered — but a planner should see it
        // before sending them out on a three-day run.
        $ok = collect($result['eligible'])->firstWhere('name', 'Soon Suresh');
        $this->assertNotNull($ok);
        $this->assertSame('DRIVER_LICENSE_EXPIRING', $ok['warnings'][0]['code']);
    }

    public function test_an_unrecorded_licence_blocks_the_driver_but_reads_differently(): void
    {
        $this->assignDriver($this->person('Unknown Umesh'), $this->vehicle('MH12UNRC01'), null);

        $result = app(\App\Domains\Fleet\Services\DriverService::class)->eligible(self::COMPANY);

        $blocked = collect($result['excluded'])->firstWhere('name', 'Unknown Umesh');

        // Nobody should be dispatched on a licence nobody has seen — but it is
        // cleared by recording one, not by a renewal, so it says so.
        $this->assertNotNull($blocked);
        $this->assertSame('DRIVER_LICENSE_UNRECORDED', collect($blocked['blockers'])->pluck('code')->first());
    }

    public function test_a_suspended_driver_is_blocked_and_the_fleet_office_owns_it(): void
    {
        $this->assignDriver($this->person('Suspended Sam'), $this->vehicle('MH12SUSP01'), now()->addYear()->toDateString(), 'suspended');

        $result = app(\App\Domains\Fleet\Services\DriverService::class)->eligible(self::COMPANY);

        $blocked = collect($result['excluded'])->firstWhere('name', 'Suspended Sam');
        $this->assertNotNull($blocked);

        $codes = collect($blocked['blockers'])->pluck('code');
        $this->assertTrue($codes->contains('DRIVER_UNAVAILABLE'));
        // A suspension is an office decision, not a compliance one.
        $this->assertSame('Fleet office', collect($blocked['blockers'])->firstWhere('code', 'DRIVER_UNAVAILABLE')['owner']);
    }

    public function test_the_eligible_endpoint_answers_in_the_vehicle_shape(): void
    {
        $this->assignDriver($this->person('Valid Vikram'), $this->vehicle('MH12API001'), now()->addYear()->toDateString());
        $this->assignDriver($this->person('Expired Rajesh'), $this->vehicle('MH12API002'), now()->subDay()->toDateString());

        $data = $this->actingAs($this->user())
            ->getJson('/api/v1/fleet/drivers/eligible')
            ->assertOk()->json('data');

        // One dispatch board renders trucks and crew with one component.
        $this->assertArrayHasKey('eligible', $data);
        $this->assertArrayHasKey('excluded', $data);
        $this->assertSame(1, $data['counts']['excluded']);
    }

    /* ── The states around it ────────────────────────── */

    public function test_driver_compliance_travels_beside_vehicle_compliance(): void
    {
        $vehicle = $this->vehicle('MH12PAIR01');
        $this->assignDriver($this->person('Valid Vikram'), $vehicle, now()->addYear()->toDateString());

        $row = $this->eligible()[0];

        // The driver still TRAVELS with the vehicle row — a planner sees who
        // normally drives it. What changed is that their licence no longer
        // scores or flags the truck.
        $this->assertSame('compliant', $row['compliance_status']);
        $this->assertSame('Valid Vikram', $row['driver']['name']);
        $this->assertSame('valid', $row['driver']['licence']['state']);
        $this->assertNotNull($row['driver']['profile']['licence_number']);
        $this->assertStringContainsString('Valid Vikram', implode(' ', $row['reasons']));
    }

    public function test_a_vehicle_with_no_regular_driver_is_flagged_but_not_punished(): void
    {
        $none = $this->vehicle('MH12NONE01');
        $good = $this->vehicle('MH12GOOD02');

        $this->assignDriver($this->person('Valid Vikram'), $good, now()->addYear()->toDateString());

        $rows = collect($this->eligible())->keyBy('registration_number');

        // Whoever is free takes the next load, so this is information rather
        // than a fault — but a truck with a driver already on it is the
        // marginally more convenient pick.
        $this->assertContains('NO_DRIVER_ASSIGNED', $rows['MH12NONE01']['flags']);
        $this->assertNull($rows['MH12NONE01']['driver']);
        $this->assertLessThan($rows['MH12GOOD02']['score'], $rows['MH12NONE01']['score']);
    }

    public function test_an_unavailable_regular_driver_still_flags_the_pairing(): void
    {
        $vehicle = $this->vehicle('MH12SUSP02');
        $this->assignDriver($this->person('Suspended Sam'), $vehicle, now()->addYear()->toDateString(), 'suspended');

        $row = $this->eligible()[0];

        // Availability stays on the vehicle row: it describes the pairing, not
        // the person's right to drive. The licence is what moved.
        $this->assertContains('DRIVER_UNAVAILABLE', $row['flags']);
        $this->assertNotContains('DRIVER_LICENSE_EXPIRED', $row['flags']);
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
