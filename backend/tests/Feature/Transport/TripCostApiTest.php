<?php

namespace Tests\Feature\Transport;

use App\Models\Tenant;
use App\Models\Transport\TransportOrder;
use App\Models\Transport\TransportTrip;
use App\Models\Transport\TripCost;
use App\Models\User;
use App\Support\Transport\CostSource;
use App\Support\Transport\TripStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Trip cost API — SNG-TRN-012, over HTTP.
 *
 * Three endpoints, none of them in Step 11's API registry: the API-007 the
 * ticket cites is `POST .../exceptions`, another domain entirely, and no cost
 * endpoint exists anywhere in the registry (D-58). Same position as Consignment
 * under D-45, so the permission keys are constructed and the gates are applied
 * narrowly — which is exactly what needs testing, because a constructed gate
 * has no registry row to check it against.
 *
 * The refusals carry the weight. An endpoint that records correctly but answers
 * for another tenant, or lets a Customer read what a haul cost us, is a leak
 * that every happy-path assertion would pass straight through.
 */
class TripCostApiTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_A = 1;
    private const TENANT_B = 2;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::TENANT_A => 'Alpha', self::TENANT_B => 'Bravo'] as $id => $n) {
            (new Tenant())->forceFill([
                'id' => $id, 'name' => $n, 'slug' => Str::slug($n),
                'subdomain' => Str::slug($n), 'status' => 'active',
            ])->save();
        }
    }

    private function user(int $tenantId = self::TENANT_A, string $role = 'admin', ?string $internal = null): User
    {
        return User::create([
            'tenant_id' => $tenantId, 'name' => ucfirst($role), 'role' => $role, 'internal_role' => $internal,
            'email' => $role.'-'.Str::random(8).'@test.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function signIn(int $tenantId = self::TENANT_A, string $role = 'admin', ?string $internal = null): User
    {
        $u = $this->user($tenantId, $role, $internal);
        Sanctum::actingAs($u);

        return $u;
    }

    private function trip(int $tenantId = self::TENANT_A): TransportTrip
    {
        $order = TransportOrder::create([
            'tenant_id' => $tenantId, 'order_number' => 'TO-'.Str::random(8), 'customer_id' => 1,
            'pickup_location' => ['address' => 'JNPT'], 'delivery_location' => ['address' => 'Bhiwandi'],
            'required_at' => now()->addDays(2), 'service_type' => 'Container Haulage',
        ]);

        $trip = TransportTrip::create([
            'tenant_id' => $tenantId, 'order_id' => $order->id,
            'trip_number' => 'TRP-'.Str::random(8), 'customer_id' => 1,
        ]);
        $trip->forceFill(['status' => TripStatus::APPROVED, 'approved_freight' => '100000.00'])->save();

        return $trip->fresh();
    }

    /* ── Recording ────────────────────────────────────────────────────── */

    public function test_a_cost_can_be_recorded_over_http(): void
    {
        $this->signIn();
        $trip = $this->trip();

        $this->postJson("/api/transport/trips/{$trip->id}/costs", [
            'cost_type' => 'Fuel', 'amount' => '1200.50', 'incurred_on' => '2026-09-10',
        ])->assertStatus(201)
          ->assertJsonPath('data.cost_type', 'fuel')      // normalised on the way in
          ->assertJsonPath('data.amount', '1200.50')
          ->assertJsonPath('data.source', CostSource::MANUAL);
    }

    public function test_the_list_returns_the_total_and_the_breakdown(): void
    {
        $this->signIn();
        $trip = $this->trip();

        $this->postJson("/api/transport/trips/{$trip->id}/costs", ['cost_type' => 'fuel', 'amount' => '500.00']);
        $this->postJson("/api/transport/trips/{$trip->id}/costs", ['cost_type' => 'toll', 'amount' => '60.00']);

        $this->getJson("/api/transport/trips/{$trip->id}/costs")
            ->assertOk()
            // Totals are the server's, computed with bcmath. A screen adding
            // JavaScript numbers would drift from the figure 018 reports.
            ->assertJsonPath('data.total', '560.00')
            ->assertJsonPath('data.breakdown.fuel', '500.00')
            ->assertJsonPath('data.breakdown.toll', '60.00')
            ->assertJsonCount(2, 'data.costs');
    }

    public function test_known_types_are_offered_as_hints(): void
    {
        $this->signIn();
        $trip = $this->trip();

        // Sent so a screen can offer consistent spellings without hard-coding a
        // vocabulary the registry has never defined.
        $this->getJson("/api/transport/trips/{$trip->id}/costs")
            ->assertOk()
            ->assertJsonFragment(['known_types' => \App\Support\Transport\CostType::KNOWN]);
    }

    /* ── Validation, in the request layer ─────────────────────────────── */

    public function test_a_cost_without_a_type_or_amount_is_refused(): void
    {
        $this->signIn();
        $trip = $this->trip();

        $this->postJson("/api/transport/trips/{$trip->id}/costs", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cost_type', 'amount']);
    }

    public function test_a_future_dated_cost_is_refused(): void
    {
        $this->signIn();
        $trip = $this->trip();

        $this->postJson("/api/transport/trips/{$trip->id}/costs", [
            'cost_type' => 'fuel', 'amount' => '10.00',
            'incurred_on' => now()->addDay()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors(['incurred_on']);
    }

    public function test_more_than_two_decimal_places_is_refused_not_rounded(): void
    {
        $this->signIn();
        $trip = $this->trip();

        // 1200.999 quietly becoming 1201.00 is money invented by a rounding rule.
        $this->postJson("/api/transport/trips/{$trip->id}/costs", [
            'cost_type' => 'fuel', 'amount' => '1200.999',
        ])->assertStatus(422)->assertJsonValidationErrors(['amount']);
    }

    public function test_an_unknown_source_is_refused_at_the_request_layer(): void
    {
        $this->signIn();
        $trip = $this->trip();

        $this->postJson("/api/transport/trips/{$trip->id}/costs", [
            'cost_type' => 'fuel', 'amount' => '10.00', 'source' => 'wherever',
        ])->assertStatus(422)->assertJsonValidationErrors(['source']);
    }

    public function test_a_person_cannot_post_a_system_source(): void
    {
        $this->signIn();
        $trip = $this->trip();

        // Valid per the request layer, refused by the service — a hand-keyed
        // row must not occupy an identity the deduplication trusts.
        $this->postJson("/api/transport/trips/{$trip->id}/costs", [
            'cost_type' => 'fuel', 'amount' => '10.00',
            'source' => CostSource::TELEMETRY, 'source_ref' => 'made-up',
        ])->assertStatus(422);

        $this->assertSame(0, TripCost::withTrashed()->count());
    }

    /* ── Tenancy ──────────────────────────────────────────────────────── */

    public function test_another_tenants_trip_reads_as_not_found(): void
    {
        $this->signIn(self::TENANT_A);
        $foreign = $this->trip(self::TENANT_B);

        $this->getJson("/api/transport/trips/{$foreign->id}/costs")->assertStatus(404);
        $this->postJson("/api/transport/trips/{$foreign->id}/costs", [
            'cost_type' => 'fuel', 'amount' => '10.00',
        ])->assertStatus(404);
    }

    public function test_a_cost_from_another_trip_cannot_be_retracted_through_this_one(): void
    {
        $this->signIn();
        $tripA = $this->trip();
        $tripB = $this->trip();

        $this->postJson("/api/transport/trips/{$tripB->id}/costs", ['cost_type' => 'fuel', 'amount' => '10.00']);
        $cost = TripCost::firstOrFail();

        // A correct-looking URL pairing trip A with trip B's cost must not
        // quietly retract the wrong one.
        $this->deleteJson("/api/transport/trips/{$tripA->id}/costs/{$cost->id}", ['reason' => 'nope'])
            ->assertStatus(404);
    }

    /* ── The constructed permission gates ─────────────────────────────── */

    public function test_a_client_identity_cannot_reach_the_cost_surface_at_all(): void
    {
        // The coarse door: role:admin,staff keeps portal identities off the
        // staff surface entirely, before any permission key is consulted.
        $this->signIn(self::TENANT_A, 'client');
        $trip = $this->trip();

        $this->getJson("/api/transport/trips/{$trip->id}/costs")->assertStatus(403);
    }

    public function test_retracting_requires_a_reason(): void
    {
        $this->signIn();
        $trip = $this->trip();

        $this->postJson("/api/transport/trips/{$trip->id}/costs", ['cost_type' => 'fuel', 'amount' => '10.00']);
        $cost = TripCost::firstOrFail();

        $this->deleteJson("/api/transport/trips/{$trip->id}/costs/{$cost->id}", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);
    }

    public function test_retracting_removes_the_cost_from_the_total(): void
    {
        $this->signIn();
        $trip = $this->trip();

        $this->postJson("/api/transport/trips/{$trip->id}/costs", ['cost_type' => 'fuel', 'amount' => '500.00']);
        $cost = TripCost::firstOrFail();

        $this->deleteJson("/api/transport/trips/{$trip->id}/costs/{$cost->id}", [
            'reason' => 'keyed against the wrong trip',
        ])->assertOk();

        $this->getJson("/api/transport/trips/{$trip->id}/costs")
            ->assertOk()
            ->assertJsonPath('data.total', '0.00');

        // The row survives, so 018 can still explain why the margin moved.
        $this->assertSame(1, TripCost::withTrashed()->count());
    }

    /* ── Idempotency, over HTTP ───────────────────────────────────────── */

    public function test_a_confirmed_duplicate_is_accepted_over_http(): void
    {
        $this->signIn();
        $trip = $this->trip();

        $payload = ['cost_type' => 'fuel', 'amount' => '500.00', 'incurred_on' => '2026-09-10'];

        $this->postJson("/api/transport/trips/{$trip->id}/costs", $payload)->assertStatus(201);
        $this->postJson("/api/transport/trips/{$trip->id}/costs", $payload)->assertStatus(422);
        $this->postJson("/api/transport/trips/{$trip->id}/costs", $payload + ['confirm_duplicate' => true])
            ->assertStatus(201);

        $this->getJson("/api/transport/trips/{$trip->id}/costs")
            ->assertOk()
            ->assertJsonPath('data.total', '1000.00');
    }
}
