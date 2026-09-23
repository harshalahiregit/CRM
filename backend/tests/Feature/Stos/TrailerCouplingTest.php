<?php

namespace Tests\Feature\Stos;

use App\Domains\Fleet\Models\Trailer;
use App\Domains\Fleet\Models\Vehicle;
use App\Domains\Fleet\Models\VehicleLiveStatus;
use App\Domains\Fleet\Services\TrailerService;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * STOS-FLEET — trailers as their own master, and the coupling between (T-54).
 *
 * The case the whole feature exists for is "which trailer was under that truck
 * on the 14th", asked when a load spoils, a claim is filed, or a tyre fails.
 * A `vehicles.trailer_id` column answers only "which one is under it now",
 * which is the least useful version of that question.
 */
class TrailerCouplingTest extends TestCase
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

    private function svc(): TrailerService
    {
        return app(TrailerService::class);
    }

    private function vehicle(array $over = [], int $company = self::COMPANY): Vehicle
    {
        $v = Vehicle::create(array_merge([
            'company_id' => $company,
            'registration_number' => 'MH12AB'.random_int(1000, 9999),
            'vehicle_type' => 'truck', 'status' => Vehicle::STATUS_AVAILABLE,
            'compliance_status' => 'compliant', 'gps_device_id' => 'DEV-'.Str::random(6),
        ], $over));

        VehicleLiveStatus::create([
            'vehicle_id' => $v->id, 'company_id' => $company,
            'latitude' => '19.07609500', 'longitude' => '72.87765800',
            'last_ping_at' => now(),
        ]);

        return $v;
    }

    private function trailer(array $over = [], int $company = self::COMPANY): Trailer
    {
        return $this->svc()->register($company, array_merge([
            'trailer_number' => 'MH12TR'.random_int(1000, 9999),
            'trailer_type' => 'flatbed',
        ], $over), 1);
    }

    /* ── A trailer is a master, not a vehicle type ──────────────────── */

    public function test_a_trailer_is_registered_on_its_own_register(): void
    {
        $t = $this->trailer(['trailer_number' => 'MH 12 TR 0001', 'trailer_type' => 'reefer']);

        $this->assertSame('MH12TR0001', $t->registration_normalized);
        $this->assertSame(Trailer::STATUS_AVAILABLE, $t->status);

        // And it is NOT in the vehicle fleet — that is the whole point of T-54.
        $this->assertSame(0, Vehicle::forCompany(self::COMPANY)->count());
    }

    public function test_a_trailer_has_no_puc_because_it_has_no_engine(): void
    {
        // The clearest test that this is not a copied vehicle: a compliance
        // sweep demanding an emissions certificate from a box on wheels would
        // ground a legal trailer for a document that cannot be obtained.
        $this->assertArrayNotHasKey('puc_expiry', Trailer::EXPIRY_DOCUMENTS);
        $this->assertCount(4, Trailer::EXPIRY_DOCUMENTS);
        $this->assertArrayHasKey('puc_expiry', Vehicle::EXPIRY_DOCUMENTS);
    }

    public function test_the_same_plate_cannot_be_registered_twice(): void
    {
        $this->trailer(['trailer_number' => 'MH12TR9999']);

        $this->expectException(\App\Exceptions\BusinessException::class);
        // Spaced differently, same trailer.
        $this->trailer(['trailer_number' => 'MH 12 TR 9999']);
    }

    /* ── Coupling ───────────────────────────────────────────────────── */

    public function test_coupling_records_the_pairing_and_marks_the_trailer(): void
    {
        $v = $this->vehicle();
        $t = $this->trailer();

        $a = $this->svc()->couple(self::COMPANY, $v->id, $t->id, 1);

        $this->assertSame($v->id, (int) $a->vehicle_id);
        $this->assertNull($a->uncoupled_at);
        $this->assertSame(Trailer::STATUS_COUPLED, $t->fresh()->status);
    }

    public function test_coupling_the_same_pair_again_is_not_an_error(): void
    {
        $v = $this->vehicle();
        $t = $this->trailer();

        $first = $this->svc()->couple(self::COMPANY, $v->id, $t->id, 1);
        $second = $this->svc()->couple(self::COMPANY, $v->id, $t->id, 1);

        // Somebody confirming, not a second coupling. One row, not two.
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, DB::table('vehicle_trailer_assignments')->count());
    }

    public function test_a_trailer_cannot_be_under_two_tractors(): void
    {
        $first = $this->vehicle(['registration_number' => 'MH12FIRST1']);
        $second = $this->vehicle(['registration_number' => 'MH12SECND1']);
        $t = $this->trailer(['trailer_number' => 'MH12TRBUSY']);

        $this->svc()->couple(self::COMPANY, $first->id, $t->id, 1);

        try {
            $this->svc()->couple(self::COMPANY, $second->id, $t->id, 1);
            $this->fail('a trailer under two tractors should be refused');
        } catch (\App\Exceptions\BusinessException $e) {
            // The message names where it is, so somebody can go and get it.
            $this->assertStringContainsString('MH12FIRST1', $e->getMessage());
        }
    }

    public function test_a_tractor_cannot_pull_two_trailers(): void
    {
        $v = $this->vehicle();
        $a = $this->trailer(['trailer_number' => 'MH12TRONE1']);
        $b = $this->trailer(['trailer_number' => 'MH12TRTWO1']);

        $this->svc()->couple(self::COMPANY, $v->id, $a->id, 1);

        $this->expectException(\App\Exceptions\BusinessException::class);
        $this->svc()->couple(self::COMPANY, $v->id, $b->id, 1);
    }

    public function test_the_database_holds_the_line_when_the_service_is_bypassed(): void
    {
        // The unique index on the stored generated column is the backstop for
        // two people coupling the same trailer from two screens in the same
        // second. The service refuses first; this is what holds if it does not.
        $v = $this->vehicle();
        $other = $this->vehicle(['registration_number' => 'MH12OTHER1']);
        $t = $this->trailer();

        $this->svc()->couple(self::COMPANY, $v->id, $t->id, 1);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('vehicle_trailer_assignments')->insert([
            'company_id' => self::COMPANY, 'vehicle_id' => $other->id, 'trailer_id' => $t->id,
            'coupled_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /* ── What coupling refuses ──────────────────────────────────────── */

    public function test_a_retired_trailer_is_not_coupled(): void
    {
        $v = $this->vehicle();
        $t = $this->trailer();
        $t->update(['status' => Trailer::STATUS_RETIRED]);

        $this->expectException(\App\Exceptions\BusinessException::class);
        $this->svc()->couple(self::COMPANY, $v->id, $t->id, 1);
    }

    public function test_a_working_trailer_is_not_coupled_to_a_retired_truck(): void
    {
        // It would strand the trailer: the tractor never moves and the trailer
        // reads as in service. Same reasoning as a genset on a scrapped truck.
        $v = $this->vehicle(['status' => Vehicle::STATUS_RETIRED]);
        $t = $this->trailer();

        $this->expectException(\App\Exceptions\BusinessException::class);
        $this->svc()->couple(self::COMPANY, $v->id, $t->id, 1);
    }

    public function test_a_trailer_with_lapsed_papers_is_not_coupled(): void
    {
        $v = $this->vehicle();
        $t = $this->trailer(['fitness_expiry' => now()->subDay()->toDateString()]);

        // The compliance sweep runs on registration, so it is already blocked.
        $this->assertSame(Trailer::STATUS_COMPLIANCE_BLOCKED, $t->fresh()->status);

        try {
            $this->svc()->couple(self::COMPANY, $v->id, $t->id, 1);
            $this->fail('a trailer with a lapsed document should be refused');
        } catch (\App\Exceptions\BusinessException $e) {
            $this->assertStringContainsString('lapsed', $e->getMessage());
        }
    }

    /* ── Uncoupling, and the history that is the point ──────────────── */

    public function test_uncoupling_closes_the_row_and_keeps_it(): void
    {
        $v = $this->vehicle();
        $t = $this->trailer();

        $this->svc()->couple(self::COMPANY, $v->id, $t->id, 1);
        $closed = $this->svc()->uncouple(self::COMPANY, $t->id, 1, 'Dropped at the yard');

        $this->assertNotNull($closed->uncoupled_at);
        $this->assertSame(Trailer::STATUS_AVAILABLE, $t->fresh()->status);

        // The row stays. "Which trailer was under that truck on the 14th" is
        // the question this feature exists to answer.
        $this->assertSame(1, DB::table('vehicle_trailer_assignments')->count());
    }

    public function test_the_history_says_which_trailer_was_under_which_truck(): void
    {
        $v = $this->vehicle(['registration_number' => 'MH12HIST01']);
        $first = $this->trailer(['trailer_number' => 'MH12TRHIS1']);
        $second = $this->trailer(['trailer_number' => 'MH12TRHIS2']);

        $this->svc()->couple(self::COMPANY, $v->id, $first->id, 1);
        $this->svc()->uncouple(self::COMPANY, $first->id, 1);
        $this->svc()->couple(self::COMPANY, $v->id, $second->id, 1);

        $history = $this->svc()->history(self::COMPANY, $v->id);

        $this->assertCount(2, $history);
        $this->assertSame('MH12TRHIS2', $history[0]['trailer_number']);
        $this->assertTrue($history[0]['open']);
        $this->assertSame('MH12TRHIS1', $history[1]['trailer_number']);
        $this->assertFalse($history[1]['open']);
    }

    public function test_a_trailer_sent_to_the_workshop_does_not_come_off_into_the_yard(): void
    {
        // Uncoupling is not a repair. A trailer taken off for work stays under
        // maintenance rather than being offered to the next tractor.
        $v = $this->vehicle();
        $t = $this->trailer();

        $this->svc()->couple(self::COMPANY, $v->id, $t->id, 1);
        $t->update(['status' => Trailer::STATUS_UNDER_MAINTENANCE]);

        $this->svc()->uncouple(self::COMPANY, $t->id, 1);

        $this->assertSame(Trailer::STATUS_UNDER_MAINTENANCE, $t->fresh()->status);
    }

    public function test_a_coupled_trailer_cannot_be_retired_from_the_form(): void
    {
        $v = $this->vehicle();
        $t = $this->trailer();
        $this->svc()->couple(self::COMPANY, $v->id, $t->id, 1);

        try {
            $this->svc()->update($t->id, self::COMPANY, ['status' => Trailer::STATUS_RETIRED], 1);
            $this->fail('retiring a coupled trailer should be refused');
        } catch (\App\Exceptions\BusinessException $e) {
            $this->assertStringContainsString('Uncouple', $e->getMessage());
        }
    }

    public function test_coupled_and_blocked_cannot_be_typed_in(): void
    {
        $t = $this->trailer();

        foreach ([Trailer::STATUS_COUPLED, Trailer::STATUS_COMPLIANCE_BLOCKED] as $status) {
            try {
                $this->svc()->update($t->id, self::COMPANY, ['status' => $status], 1);
                $this->fail($status.' should not be settable by hand');
            } catch (\App\Exceptions\BusinessException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }

    /* ── The payoff: a lapsed trailer stops the tractor ─────────────── */

    public function test_a_blocked_trailer_blocks_the_truck_it_is_under(): void
    {
        $v = $this->vehicle(['registration_number' => 'MH12PULL01']);
        $t = $this->trailer(['trailer_number' => 'MH12TRBAD1']);

        $this->svc()->couple(self::COMPANY, $v->id, $t->id, 1);

        // Its fitness lapses while it is out. The truck's own papers are clean.
        $t->update(['fitness_expiry' => now()->subDay()->toDateString()]);
        DB::table('trailers')->where('id', $t->id)
            ->update(['status' => Trailer::STATUS_COMPLIANCE_BLOCKED, 'compliance_status' => 'expired']);

        $data = $this->actingAs($this->user())
            ->getJson('/api/v1/fleet/vehicles/eligible')->assertOk()->json('data');

        $excluded = collect($data['excluded'])->firstWhere('id', $v->id);

        $this->assertNotNull($excluded, 'a tractor pulling an illegal trailer cannot go out');
        $blocker = collect($excluded['blockers'])->firstWhere('code', 'trailer_compliance_blocked');
        $this->assertNotNull($blocker);

        // Names the TRAILER. Sending somebody to renew the truck's papers over
        // a trailer's fitness certificate is how an hour is wasted at a gate.
        $this->assertStringContainsString('MH12TRBAD1', $blocker['missing']);
    }

    public function test_an_uncoupled_blocked_trailer_does_not_block_anything(): void
    {
        $v = $this->vehicle();
        $this->trailer(['fitness_expiry' => now()->subDay()->toDateString()]);

        $data = $this->actingAs($this->user())
            ->getJson('/api/v1/fleet/vehicles/eligible')->assertOk()->json('data');

        $this->assertTrue(collect($data['eligible'])->pluck('id')->contains($v->id));
    }

    /* ── Tenancy ────────────────────────────────────────────────────── */

    public function test_another_companys_trailer_cannot_be_coupled(): void
    {
        $v = $this->vehicle();
        $theirs = $this->trailer([], self::OTHER);

        $this->expectException(\App\Exceptions\BusinessException::class);
        $this->svc()->couple(self::COMPANY, $v->id, $theirs->id, 1);
    }

    public function test_the_register_is_scoped_to_the_company(): void
    {
        $this->trailer(['trailer_number' => 'MH12MINE01']);
        $this->trailer(['trailer_number' => 'MH12THEIR1'], self::OTHER);

        $mine = $this->svc()->list(self::COMPANY);

        $this->assertCount(1, $mine['trailers']);
        $this->assertSame('MH12MINE01', $mine['trailers'][0]['trailer_number']);
    }

    /* ── The HTTP surface ───────────────────────────────────────────── */

    public function test_the_endpoints_register_couple_and_report(): void
    {
        $admin = $this->user();
        $v = $this->vehicle(['registration_number' => 'MH12HTTP01']);

        $trailer = $this->actingAs($admin)->postJson('/api/v1/fleet/trailers', [
            'trailer_number' => 'MH12TRHTTP', 'trailer_type' => 'skeletal',
            'capacity_tonnes' => 30, 'axles' => 3,
        ])->assertCreated()->json('data');

        $this->actingAs($admin)
            ->postJson("/api/v1/fleet/trailers/{$trailer['id']}/couple", ['vehicle_id' => $v->id])
            ->assertCreated();

        $list = $this->actingAs($admin)->getJson('/api/v1/fleet/trailers')
            ->assertOk()->json('data');

        $this->assertSame(1, $list['counts']['coupled']);
        $this->assertSame('MH12HTTP01', $list['trailers'][0]['coupled_to']);

        $history = $this->actingAs($admin)
            ->getJson('/api/v1/fleet/trailers/history?vehicle_id='.$v->id)
            ->assertOk()->json('data');

        $this->assertCount(1, $history);
        $this->assertTrue($history[0]['open']);
    }

    public function test_history_is_a_word_and_not_a_trailer_id(): void
    {
        // `/trailers/history` sits beside `/trailers/{trailer}`. The route
        // order and the numeric constraint have to keep those apart.
        $this->actingAs($this->user())
            ->getJson('/api/v1/fleet/trailers/history')
            ->assertOk();
    }
}
