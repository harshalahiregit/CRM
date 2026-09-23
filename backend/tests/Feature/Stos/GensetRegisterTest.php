<?php

namespace Tests\Feature\Stos;

use App\Domains\Fleet\Models\Genset;
use App\Domains\Fleet\Models\Vehicle;
use App\Domains\Fleet\Services\GensetService;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * STOS-FLEET — the genset register and its fitments (T-05).
 *
 * A genset is an asset in its own right: it has a serial, a service life and a
 * history that follow the UNIT, and it gets swapped between trailers when one
 * fails. The onboarding form has told people to "fit its genset from the
 * vehicle's passport once it is saved" since the form was written, and until now
 * there was nothing behind that sentence.
 */
class GensetRegisterTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY = 1;
    private const OTHER   = 2;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::COMPANY, self::OTHER] as $id) {
            (new Tenant())->forceFill([
                'id' => $id, 'name' => "Co{$id}", 'slug' => "co{$id}",
                'subdomain' => "co{$id}", 'status' => 'active',
            ])->save();
        }
    }

    private function user(int $company = self::COMPANY): User
    {
        return User::create([
            'tenant_id' => $company, 'name' => 'Ops', 'role' => 'admin',
            'email' => 'ops-'.Str::random(6).'@test.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function vehicle(array $over = [], int $company = self::COMPANY): Vehicle
    {
        return Vehicle::create(array_merge([
            'company_id' => $company,
            'registration_number' => 'MH'.random_int(10, 99).'AB'.random_int(1000, 9999),
            'vehicle_type' => 'reefer', 'status' => 'AVAILABLE',
        ], $over));
    }

    private function svc(): GensetService
    {
        return app(GensetService::class);
    }

    private function genset(string $serial, array $data = []): Genset
    {
        return $this->svc()->create(self::COMPANY, array_merge(['serial_number' => $serial], $data), $this->user()->id);
    }

    /* ── The register ───────────────────────────────────────────── */

    public function test_a_genset_is_registered_to_the_yard_before_it_is_fitted(): void
    {
        $genset = $this->genset('GS-0001');

        // Unfitted is a normal state, not an incomplete one — a spare in the
        // yard is the whole reason the register exists.
        $this->assertNull($genset->vehicle_id);
        $this->assertSame('IDLE', $genset->status);
        $this->assertSame('GS0001', $genset->serial_number);
    }

    public function test_serials_are_normalised_because_they_are_read_off_a_plate(): void
    {
        $genset = $this->genset(' gs 0002 ');

        // Stamped on a plate and typed back by eye; case and spacing vary.
        $this->assertSame('GS0002', $genset->serial_number);
    }

    public function test_the_same_serial_cannot_be_registered_twice(): void
    {
        $this->genset('GS-0003');

        $this->actingAs($this->user())
            ->postJson('/api/v1/fleet/gensets', ['serial_number' => 'GS-0003'])
            ->assertStatus(422);
    }

    public function test_a_genset_with_no_serial_is_refused(): void
    {
        // The serial is how the unit is identified once it moves. Without it
        // the register cannot tell two units apart.
        $this->actingAs($this->user())
            ->postJson('/api/v1/fleet/gensets', ['serial_number' => '   '])
            ->assertStatus(422);
    }

    /* ── Fitting and moving ─────────────────────────────────────── */

    public function test_fitting_puts_the_unit_on_the_truck_and_wakes_it(): void
    {
        $vehicle = $this->vehicle();
        $genset = $this->genset('GS-0010');

        $fitted = $this->svc()->fit($genset->id, self::COMPANY, $vehicle->id, $this->user()->id);

        $this->assertSame($vehicle->id, $fitted->vehicle_id);
        // A unit sitting in the yard is working again once it is bolted on.
        $this->assertSame('ACTIVE', $fitted->status);
    }

    public function test_a_unit_that_fails_is_replaced_by_moving_the_spare_across(): void
    {
        $failing = $this->vehicle();
        $spareTruck = $this->vehicle();
        $genset = $this->genset('GS-0011', ['vehicle_id' => $failing->id]);

        $moved = $this->svc()->fit($genset->id, self::COMPANY, $spareTruck->id, $this->user()->id);

        // Moved, not refused: that is what physically happens in a yard.
        $this->assertSame($spareTruck->id, $moved->vehicle_id);
        $this->assertSame(0, Genset::where('vehicle_id', $failing->id)->count());
    }

    public function test_fitting_the_same_unit_to_the_same_truck_twice_is_not_an_error(): void
    {
        $vehicle = $this->vehicle();
        $genset = $this->genset('GS-0012', ['vehicle_id' => $vehicle->id]);

        $again = $this->svc()->fit($genset->id, self::COMPANY, $vehicle->id, $this->user()->id);

        $this->assertSame($vehicle->id, $again->vehicle_id);
    }

    public function test_a_retired_unit_cannot_be_fitted(): void
    {
        $genset = $this->genset('GS-0013');
        $this->svc()->update($genset->id, self::COMPANY, ['status' => 'RETIRED'], $this->user()->id);

        $this->actingAs($this->user())
            ->postJson("/api/v1/fleet/gensets/{$genset->id}/fit", ['vehicle_id' => $this->vehicle()->id])
            ->assertStatus(422);
    }

    public function test_a_working_unit_is_not_fitted_to_a_retired_truck(): void
    {
        // T-58 — this used to say 'retired', which is not a value `vehicles`
        // has held since January. The guard it was testing said the same
        // thing, so the test passed while the guard could never fire and a
        // genset could really be fitted to a scrapped truck. Both now use the
        // constant.
        $retired = $this->vehicle(['status' => Vehicle::STATUS_RETIRED]);
        $genset = $this->genset('GS-0014');

        // It would strand the unit: the truck never moves and the genset reads
        // as in service.
        $this->actingAs($this->user())
            ->postJson("/api/v1/fleet/gensets/{$genset->id}/fit", ['vehicle_id' => $retired->id])
            ->assertStatus(422);
    }

    /* ── Coming off ─────────────────────────────────────────────── */

    public function test_unfitting_returns_the_unit_to_the_yard_as_idle(): void
    {
        $vehicle = $this->vehicle();
        $genset = $this->genset('GS-0020', ['vehicle_id' => $vehicle->id]);

        $off = $this->svc()->unfit($genset->id, self::COMPANY, $this->user()->id);

        $this->assertNull($off->vehicle_id);
        // "Active" on a unit sitting in the yard reads as working AND in use,
        // and the register is what somebody checks before ordering another.
        $this->assertSame('IDLE', $off->status);
    }

    public function test_a_unit_under_repair_stays_under_repair_when_it_comes_off(): void
    {
        $vehicle = $this->vehicle();
        $genset = $this->genset('GS-0021', ['vehicle_id' => $vehicle->id]);
        $this->svc()->update($genset->id, self::COMPANY, ['status' => 'IN_MAINTENANCE'], $this->user()->id);

        $off = $this->svc()->unfit($genset->id, self::COMPANY, $this->user()->id);

        // Taking it off does not repair it.
        $this->assertSame('IN_MAINTENANCE', $off->status);
    }

    public function test_unfitting_something_that_is_not_fitted_is_refused(): void
    {
        $genset = $this->genset('GS-0022');

        $this->actingAs($this->user())
            ->postJson("/api/v1/fleet/gensets/{$genset->id}/unfit")
            ->assertStatus(422);
    }

    public function test_a_fitted_unit_cannot_be_retired_out_from_under_a_truck(): void
    {
        $vehicle = $this->vehicle();
        $genset = $this->genset('GS-0023', ['vehicle_id' => $vehicle->id]);

        // Retiring is a decision about an asset; taking it off is a physical
        // act. Doing the second implicitly leaves a reefer reporting no genset
        // and nobody knowing why.
        $this->actingAs($this->user())
            ->putJson("/api/v1/fleet/gensets/{$genset->id}", ['status' => 'RETIRED'])
            ->assertStatus(422);

        $this->assertSame($vehicle->id, $genset->fresh()->vehicle_id);
    }

    /* ── What the register answers ──────────────────────────────── */

    public function test_the_register_counts_the_spares_available_to_fit(): void
    {
        $this->genset('GS-0030', ['vehicle_id' => $this->vehicle()->id]);
        $this->genset('GS-0031');
        $this->genset('GS-0032');

        $data = $this->actingAs($this->user())
            ->getJson('/api/v1/fleet/gensets')->assertOk()->json('data');

        // The question the register is usually opened for: a unit failed on the
        // road, what is there to put on instead.
        $this->assertSame(2, $data['spare']);
    }

    public function test_the_register_names_reefers_running_without_a_power_unit(): void
    {
        $withGenset = $this->vehicle();
        $without = $this->vehicle();
        $this->vehicle(['vehicle_type' => 'truck']);   // not a reefer, not expected
        $this->genset('GS-0040', ['vehicle_id' => $withGenset->id]);

        $data = $this->actingAs($this->user())
            ->getJson('/api/v1/fleet/gensets')->assertOk()->json('data');

        // Either the genset was never registered, or it came off and nobody
        // said so. Both are worth seeing before a load is booked onto it.
        $plates = collect($data['reefers_without_genset'])->pluck('registration_number');
        $this->assertTrue($plates->contains($without->registration_number));
        $this->assertFalse($plates->contains($withGenset->registration_number));
    }

    public function test_a_retired_reefer_is_not_reported_as_missing_a_genset(): void
    {
        $this->vehicle(['status' => Vehicle::STATUS_RETIRED]);

        $data = $this->actingAs($this->user())
            ->getJson('/api/v1/fleet/gensets')->assertOk()->json('data');

        $this->assertSame([], $data['reefers_without_genset']);
    }

    public function test_the_register_can_be_filtered_to_what_is_unfitted(): void
    {
        $this->genset('GS-0050', ['vehicle_id' => $this->vehicle()->id]);
        $this->genset('GS-0051');

        $data = $this->actingAs($this->user())
            ->getJson('/api/v1/fleet/gensets?unfitted_only=1')->assertOk()->json('data');

        $this->assertCount(1, $data['gensets']);
        $this->assertSame('GS0051', $data['gensets'][0]['serial_number']);
    }

    public function test_the_passport_shows_the_unit_fitted_to_that_vehicle(): void
    {
        $vehicle = $this->vehicle();
        $this->genset('GS-0060', ['vehicle_id' => $vehicle->id]);

        $gensets = $this->actingAs($this->user())
            ->getJson("/api/v1/fleet/vehicles/{$vehicle->id}/passport")
            ->assertOk()->json('data.gensets');

        $this->assertCount(1, $gensets);
        $this->assertSame('GS0060', $gensets[0]['serial_number']);
    }

    /* ── Tenancy ────────────────────────────────────────────────── */

    public function test_one_company_never_sees_anothers_gensets(): void
    {
        $this->genset('GS-OURS');
        app(GensetService::class)->create(self::OTHER, ['serial_number' => 'GS-THEIRS'], $this->user(self::OTHER)->id);

        $data = $this->actingAs($this->user())
            ->getJson('/api/v1/fleet/gensets')->assertOk()->json('data');

        $this->assertCount(1, $data['gensets']);
        $this->assertSame('GSOURS', $data['gensets'][0]['serial_number']);
    }

    public function test_a_genset_cannot_be_fitted_to_another_companys_vehicle(): void
    {
        $theirs = $this->vehicle([], self::OTHER);
        $genset = $this->genset('GS-0070');

        $this->actingAs($this->user())
            ->postJson("/api/v1/fleet/gensets/{$genset->id}/fit", ['vehicle_id' => $theirs->id])
            ->assertStatus(404);

        $this->assertNull($genset->fresh()->vehicle_id);
    }

    public function test_retiring_a_vehicle_frees_its_genset(): void
    {
        $vehicle = $this->vehicle();
        $genset = $this->genset('GS-0080', ['vehicle_id' => $vehicle->id]);

        $this->actingAs($this->user())
            ->deleteJson("/api/v1/fleet/vehicles/{$vehicle->id}")
            ->assertOk();

        // The unit outlives the truck — that is the point of it being its own
        // asset. It must not retire alongside it.
        $this->assertNull($genset->fresh()->vehicle_id);
        $this->assertNotSame('retired', $genset->fresh()->status);
    }
}
