<?php

namespace Tests\Feature\Stos;

use App\Domains\Fleet\Models\TyreFitment;
use App\Domains\Fleet\Models\TyreMaster;
use App\Domains\Fleet\Models\Vehicle;
use App\Domains\Fleet\Services\TyreMasterService;
use App\Domains\Fleet\Services\TyreService;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * STOS-MAINT — the casing as an asset (T-36 / T-37 / T-38).
 *
 * `tyre_fitments.tyre_id` was a bare string: enough to answer "what is on this
 * axle" and nothing else. A tyre outlives the vehicle it is fitted to — that is
 * the whole economics of retreading — so the two questions a workshop actually
 * asks needed the casing to be a row:
 *
 *   what has it cost per kilometre, across every truck and every retread?
 *   what is in the store right now?
 */
class TyreMasterTest extends TestCase
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

    private function user(): User
    {
        return User::create([
            'tenant_id' => self::COMPANY, 'name' => 'Ops', 'role' => 'admin',
            'email' => 'ops-'.Str::random(6).'@test.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function svc(): TyreMasterService
    {
        return app(TyreMasterService::class);
    }

    private function vehicle(array $over = []): Vehicle
    {
        return Vehicle::create(array_merge([
            'company_id' => self::COMPANY,
            'registration_number' => 'MH12AB'.random_int(1000, 9999),
            'vehicle_type' => 'truck', 'status' => Vehicle::STATUS_AVAILABLE,
            'compliance_status' => 'compliant',
        ], $over));
    }

    private function fit(Vehicle $v, string $serial, string $position, ?float $odometer = null, ?float $depth = null): TyreFitment
    {
        return app(TyreService::class)->fit(self::COMPANY, [
            'vehicle_id' => $v->id, 'tyre_id' => $serial, 'position' => $position,
            'odometer_at_fitment' => $odometer, 'tread_depth' => $depth,
        ], 1);
    }

    /* ── T-36: the casing is an asset ───────────────────────────────── */

    public function test_the_store_can_be_listed_before_anything_is_fitted(): void
    {
        // The question that had no answer before this: a casing in the rack was
        // invisible, because `tyre_fitments` only ever held tyres somebody had
        // already put on a truck.
        $this->svc()->register(self::COMPANY, [
            'serial_number' => 'CASING-001', 'brand' => 'Apollo',
            'size' => '295/80 R22.5', 'purchase_cost' => 22000,
        ], 1);

        $list = $this->svc()->register_list(self::COMPANY);

        $this->assertSame(1, $list['counts']['in_stock']);
        $this->assertSame(0, $list['counts']['fitted']);
        $this->assertNull($list['tyres'][0]['fitted_to']);
    }

    public function test_fitting_an_unregistered_casing_registers_it(): void
    {
        // A yard fitting a spare at six in the morning must not be refused.
        // The row it creates has no cost, which is the honest "not measured"
        // state and shows on the register as something to complete.
        $v = $this->vehicle();
        $this->fit($v, 'CASING-NEW', 'front_left');

        $master = TyreMaster::forCompany(self::COMPANY)->where('serial_number', 'CASING-NEW')->first();

        $this->assertNotNull($master);
        $this->assertSame(TyreMaster::FITTED, $master->status);
        $this->assertNull($master->purchase_cost);
    }

    public function test_the_same_casing_cannot_be_registered_twice(): void
    {
        $this->svc()->register(self::COMPANY, ['serial_number' => 'CASING-DUP'], 1);

        $this->expectException(\App\Exceptions\BusinessException::class);
        $this->svc()->register(self::COMPANY, ['serial_number' => 'casing-dup'], 1);
    }

    public function test_removing_a_tyre_puts_the_casing_back_in_the_store(): void
    {
        $v = $this->vehicle();
        $fitment = $this->fit($v, 'CASING-002', 'front_left', 10000);

        app(TyreService::class)->remove($fitment->id, self::COMPANY, [
            'status' => TyreFitment::REMOVED, 'odometer_at_removal' => 60000,
        ], 1);

        $master = TyreMaster::forCompany(self::COMPANY)->where('serial_number', 'CASING-002')->first();
        $this->assertSame(TyreMaster::IN_STOCK, $master->status);
    }

    /* ── T-36: what it cost per kilometre ───────────────────────────── */

    public function test_cost_per_km_spans_every_truck_the_casing_has_been_on(): void
    {
        $a = $this->vehicle(['registration_number' => 'MH12TYRE01']);
        $b = $this->vehicle(['registration_number' => 'MH12TYRE02']);

        $master = $this->svc()->register(self::COMPANY, [
            'serial_number' => 'CASING-003', 'purchase_cost' => 20000,
        ], 1);

        // 40,000 km on the first truck.
        $first = $this->fit($a, 'CASING-003', 'front_left', 10000);
        app(TyreService::class)->remove($first->id, self::COMPANY, [
            'status' => TyreFitment::REMOVED, 'odometer_at_removal' => 50000,
        ], 1);

        // 10,000 km on the second. A string on a fitment could never add these
        // together — that is the whole reason the casing is a row.
        $second = $this->fit($b, 'CASING-003', 'rear_inner_left', 5000);
        app(TyreService::class)->remove($second->id, self::COMPANY, [
            'status' => TyreFitment::REMOVED, 'odometer_at_removal' => 15000,
        ], 1);

        $economics = $this->svc()->economics($master->id, self::COMPANY);

        $this->assertSame(50000.0, $economics['km_run']);
        $this->assertSame(0.4, $economics['cost_per_km']);
    }

    public function test_a_retread_is_counted_and_paid_for(): void
    {
        $master = $this->svc()->register(self::COMPANY, [
            'serial_number' => 'CASING-004', 'purchase_cost' => 20000,
        ], 1);

        $this->svc()->retread($master->id, self::COMPANY, 6000, 14.0, 1);

        $fresh = $master->fresh();
        $this->assertSame(1, $fresh->retread_count);
        $this->assertSame(TyreMaster::RETREADED, $fresh->status);

        // A casing on its second life has been paid for twice. Judging it on
        // the sticker price alone flatters retreading exactly when somebody is
        // deciding whether to do it again.
        $this->assertSame(26000.0, $fresh->lifetime_cost);
        $this->assertSame(2, $this->svc()->economics($master->id, self::COMPANY)['lives']);
    }

    public function test_a_fitted_casing_cannot_be_sent_to_the_retreader(): void
    {
        $v = $this->vehicle();
        $this->fit($v, 'CASING-005', 'front_left');
        $master = TyreMaster::forCompany(self::COMPANY)->where('serial_number', 'CASING-005')->first();

        $this->expectException(\App\Exceptions\BusinessException::class);
        $this->svc()->retread($master->id, self::COMPANY, 5000, null, 1);
    }

    public function test_scrapping_demands_a_reason(): void
    {
        $master = $this->svc()->register(self::COMPANY, ['serial_number' => 'CASING-006'], 1);

        $this->expectException(\App\Exceptions\BusinessException::class);
        $this->svc()->scrap($master->id, self::COMPANY, null, 1);
    }

    public function test_fitted_and_retreaded_cannot_be_typed_in(): void
    {
        $master = $this->svc()->register(self::COMPANY, ['serial_number' => 'CASING-007'], 1);

        foreach ([TyreMaster::FITTED, TyreMaster::RETREADED] as $status) {
            try {
                $this->svc()->update($master->id, self::COMPANY, ['status' => $status], 1);
                $this->fail($status.' should not be settable by hand');
            } catch (\App\Exceptions\BusinessException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }

    /* ── T-37: rotation is one act, not four ────────────────────────── */

    public function test_rotation_swaps_two_positions_in_one_operation(): void
    {
        $v = $this->vehicle();
        $left  = $this->fit($v, 'CASING-L', 'front_left', 10000, 14.0);
        $right = $this->fit($v, 'CASING-R', 'front_right', 10000, 12.0);

        $swapped = $this->svc()->rotate(self::COMPANY, $left->id, $right->id, 50000, 1);

        $this->assertCount(2, $swapped);

        $nowLeft = TyreFitment::forCompany(self::COMPANY)
            ->where('position', 'front_left')->whereIn('status', TyreFitment::ON_VEHICLE)->first();

        $this->assertSame('CASING-R', $nowLeft->tyre_id);
        // Tread carried across: the tyre did not grow tread by moving axle, and
        // a blank here would hide a worn casing.
        $this->assertSame('12.00', $nowLeft->tread_depth);
    }

    public function test_rotation_does_not_look_like_the_casing_went_to_the_store(): void
    {
        // Recording it as four separate acts writes two rows that are lies —
        // the casings never went into the rack and never came back out — and
        // cost-per-km built from that would count a swap as a new fitting.
        $v = $this->vehicle();
        $left  = $this->fit($v, 'CASING-L2', 'front_left', 10000);
        $right = $this->fit($v, 'CASING-R2', 'front_right', 10000);

        $this->svc()->rotate(self::COMPANY, $left->id, $right->id, 50000, 1);

        $master = TyreMaster::forCompany(self::COMPANY)->where('serial_number', 'CASING-L2')->first();
        $this->assertSame(TyreMaster::FITTED, $master->status);

        // And the distance still adds up, because both ends used one odometer.
        $this->assertSame(40000.0, $this->svc()->economics($master->id, self::COMPANY)['km_run']);
    }

    public function test_tyres_on_different_assets_are_not_rotated(): void
    {
        $a = $this->vehicle(['registration_number' => 'MH12ROTA01']);
        $b = $this->vehicle(['registration_number' => 'MH12ROTB01']);

        $first  = $this->fit($a, 'CASING-X', 'front_left', 1000);
        $second = $this->fit($b, 'CASING-Y', 'front_left', 1000);

        // Moving a tyre between trucks is a removal and a fitting. Calling it a
        // rotation would say a casing moved without ever coming off.
        $this->expectException(\App\Exceptions\BusinessException::class);
        $this->svc()->rotate(self::COMPANY, $first->id, $second->id, 2000, 1);
    }

    /* ── T-38: the wear forecast ────────────────────────────────────── */

    public function test_the_forecast_projects_a_replacement_from_two_measurements(): void
    {
        $v = $this->vehicle();

        $master = $this->svc()->register(self::COMPANY, [
            'serial_number' => 'CASING-008', 'purchase_cost' => 20000,
            'new_tread_depth' => 16.0, 'scrap_tread_depth' => 3.0,
        ], 1);

        // 16 mm at 10,000 km; 8 mm at 90,000 km. 8 mm over 80,000 km is
        // 1 mm per 10,000 km, so 5 mm above the floor is 50,000 km left.
        $first = $this->fit($v, 'CASING-008', 'front_left', 10000, 16.0);
        app(TyreService::class)->remove($first->id, self::COMPANY, [
            'status' => TyreFitment::REMOVED, 'odometer_at_removal' => 90000,
        ], 1);
        $this->fit($v, 'CASING-008', 'front_right', 90000, 8.0);

        $wear = $this->svc()->economics($master->id, self::COMPANY)['wear'];

        $this->assertSame(1.0, $wear['mm_per_10000km']);
        $this->assertSame(8.0, $wear['current_depth']);
        $this->assertSame(50000, $wear['km_remaining']);
        $this->assertSame('measured', $wear['basis']);
    }

    public function test_one_measurement_forecasts_nothing_and_says_so(): void
    {
        // A projection nobody can check is a projection nobody acts on, so the
        // thin-evidence cases are named rather than guessed at.
        $v = $this->vehicle();
        $master = $this->svc()->register(self::COMPANY, [
            'serial_number' => 'CASING-009', 'scrap_tread_depth' => 3.0,
        ], 1);

        $this->fit($v, 'CASING-009', 'front_left', 10000, 16.0);

        $wear = $this->svc()->economics($master->id, self::COMPANY)['wear'];

        $this->assertNull($wear['mm_per_10000km']);
        $this->assertSame(16.0, $wear['current_depth']);
        $this->assertSame('one_measurement', $wear['basis']);
    }

    public function test_without_a_scrap_depth_the_rate_is_known_but_the_date_is_not(): void
    {
        $v = $this->vehicle();
        $master = $this->svc()->register(self::COMPANY, ['serial_number' => 'CASING-010'], 1);

        $first = $this->fit($v, 'CASING-010', 'front_left', 10000, 16.0);
        app(TyreService::class)->remove($first->id, self::COMPANY, [
            'status' => TyreFitment::REMOVED, 'odometer_at_removal' => 90000,
        ], 1);
        $this->fit($v, 'CASING-010', 'front_right', 90000, 8.0);

        $wear = $this->svc()->economics($master->id, self::COMPANY)['wear'];

        $this->assertSame(1.0, $wear['mm_per_10000km']);
        $this->assertNull($wear['replace_by_km']);
        $this->assertSame('no_scrap_depth_set', $wear['basis']);
    }

    public function test_a_casing_with_no_distance_has_no_cost_per_km(): void
    {
        // Printing 0.00 would read as "free".
        $master = $this->svc()->register(self::COMPANY, [
            'serial_number' => 'CASING-011', 'purchase_cost' => 20000,
        ], 1);

        $economics = $this->svc()->economics($master->id, self::COMPANY);

        $this->assertSame(0.0, $economics['km_run']);
        $this->assertNull($economics['cost_per_km']);
    }

    /* ── The HTTP surface ───────────────────────────────────────────── */

    public function test_the_endpoints_register_rotate_and_report(): void
    {
        $admin = $this->user();
        $v = $this->vehicle();

        $this->actingAs($admin)->postJson('/api/v1/fleet/tyres', [
            'serial_number' => 'CASING-HTTP', 'brand' => 'MRF',
            'purchase_cost' => 18000, 'scrap_tread_depth' => 3.0,
        ])->assertCreated();

        $left  = $this->fit($v, 'CASING-HTTP', 'front_left', 1000, 15.0);
        $right = $this->fit($v, 'CASING-HTTP2', 'front_right', 1000, 15.0);

        $this->actingAs($admin)->postJson('/api/v1/fleet/tyres/rotate', [
            'first_fitment_id' => $left->id, 'second_fitment_id' => $right->id, 'odometer' => 40000,
        ])->assertCreated();

        $list = $this->actingAs($admin)->getJson('/api/v1/fleet/tyres')->assertOk()->json('data');
        $this->assertSame(2, $list['counts']['fitted']);

        $id = collect($list['tyres'])->firstWhere('serial_number', 'CASING-HTTP')['id'];
        $this->actingAs($admin)->getJson("/api/v1/fleet/tyres/{$id}/economics")
            ->assertOk()->assertJsonPath('data.serial_number', 'CASING-HTTP');
    }

    public function test_rotate_is_a_word_and_not_a_tyre_id(): void
    {
        // `/tyres/rotate` sits beside `/tyres/{tyre}`. The numeric constraint
        // on the detail routes is what keeps them apart.
        $this->actingAs($this->user())
            ->postJson('/api/v1/fleet/tyres/rotate', [
                'first_fitment_id' => 1, 'second_fitment_id' => 2,
            ])->assertStatus(404);
    }
}
