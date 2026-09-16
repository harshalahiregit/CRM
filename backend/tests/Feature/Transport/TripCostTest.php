<?php

namespace Tests\Feature\Transport;

use App\Exceptions\BusinessException;
use App\Exceptions\ResourceNotFoundException;
use App\Models\Tenant;
use App\Models\Transport\TransportOrder;
use App\Models\Transport\TransportTrip;
use App\Models\Transport\TripCost;
use App\Models\User;
use App\Services\Transport\TripCostService;
use App\Support\Transport\CostSource;
use App\Support\Transport\CostType;
use App\Support\Transport\TripStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SNG-TRN-012 — "Every cost is linked to trip and source."
 *
 * The acceptance criterion is one sentence and both halves are load-bearing, so
 * both get tests that fail if only one is implemented. Beyond that, the ticket
 * lists its own edge cases — "duplicate callbacks", "boundary dates, amounts,
 * concurrency, partial completion" — and QA-005 requires duplicates to be
 * "detected/handled per rule".
 *
 * The rule this ticket set has two layers, and they are tested separately
 * because they fail differently:
 *
 *   SYSTEM SOURCES are deduplicated by a unique index and a repeat is ABSORBED,
 *   returning the original row. A webhook that retries must not double the
 *   margin, and must not get an error it will only retry again.
 *
 *   MANUAL rows have no key to deduplicate on, so a look-alike is REPORTED and
 *   the caller may confirm past it. A driver can genuinely buy fuel twice.
 *
 * The normalisation tests matter more than they look. `cost_type` is free text
 * because CST-001 is a dangling pointer (D-58), and IDX-005 groups on it for
 * SNG-TRN-018's margin. If `Fuel` and `fuel` are two groups, the reconciliation
 * is wrong while looking right — the worst kind of wrong.
 */
class TripCostTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private TripCostService $costs;
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::TENANT_A => 'Alpha', self::TENANT_B => 'Bravo'] as $id => $name) {
            (new Tenant())->forceFill([
                'id' => $id, 'name' => $name, 'slug' => Str::slug($name),
                'subdomain' => Str::slug($name), 'status' => 'active',
            ])->save();
        }

        $this->costs = app(TripCostService::class);
        $this->actor = $this->user('accounts');
    }

    private function user(string $internalRole): User
    {
        return User::create([
            'tenant_id' => self::TENANT_A, 'name' => ucfirst($internalRole), 'role' => 'staff',
            'internal_role' => $internalRole,
            'email' => $internalRole.'-'.Str::random(6).'@test.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
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

    /** @param array<string,mixed> $overrides */
    private function record(TransportTrip $trip, array $overrides = [], ?User $actor = null): TripCost
    {
        return $this->costs->record($trip, array_merge([
            'cost_type' => 'fuel',
            'amount'    => '1200.50',
        ], $overrides), (int) $trip->tenant_id, $actor ?? $this->actor);
    }

    /* ── "linked to trip" ─────────────────────────────────────────────── */

    public function test_a_cost_is_recorded_against_its_trip(): void
    {
        $trip = $this->trip();
        $cost = $this->record($trip);

        $this->assertSame($trip->id, $cost->trip_id);
        $this->assertSame(self::TENANT_A, (int) $cost->tenant_id);
        $this->assertSame('1200.50', (string) $cost->amount);
    }

    public function test_a_trip_from_another_tenant_reads_as_not_found(): void
    {
        $foreign = $this->trip(self::TENANT_B);

        // DEP-004 plus the enumeration rule: "not found", never "forbidden".
        $this->expectException(ResourceNotFoundException::class);
        $this->costs->record($foreign, ['cost_type' => 'fuel', 'amount' => '10.00'], self::TENANT_A, $this->actor);
    }

    public function test_a_cost_is_never_written_without_a_trip(): void
    {
        $this->assertSame(0, TripCost::withTrashed()->count());

        try {
            $this->costs->record($this->trip(self::TENANT_B), ['cost_type' => 'fuel', 'amount' => '10.00'], self::TENANT_A);
        } catch (ResourceNotFoundException) {
            // expected
        }

        $this->assertSame(0, TripCost::withTrashed()->count(), 'a refused cost must leave no row');
    }

    /* ── "and source" ─────────────────────────────────────────────────── */

    public function test_source_defaults_to_manual(): void
    {
        $cost = $this->record($this->trip());

        $this->assertSame(CostSource::MANUAL, $cost->source);
        $this->assertNull($cost->source_ref);
    }

    public function test_an_unknown_source_is_refused(): void
    {
        $this->expectException(BusinessException::class);
        $this->record($this->trip(), ['source' => 'wherever']);
    }

    public function test_a_person_cannot_claim_a_system_source(): void
    {
        // Otherwise a hand-keyed row occupies an identity the deduplication
        // trusts, and puts a fabricated transaction id into 018's reconciliation.
        $this->expectException(BusinessException::class);
        $this->record($this->trip(), ['source' => CostSource::TELEMETRY, 'source_ref' => 'made-up']);
    }

    public function test_a_system_source_must_name_its_transaction(): void
    {
        $trip = $this->trip();

        $this->expectException(BusinessException::class);
        // actor null = the system itself, so the operator-writable check passes
        // and the source_ref requirement is what refuses it.
        $this->costs->record($trip, [
            'cost_type' => 'fuel', 'amount' => '500.00', 'source' => CostSource::TELEMETRY,
        ], self::TENANT_A, null);
    }

    public function test_a_system_source_with_a_ref_is_accepted(): void
    {
        $trip = $this->trip();

        $cost = $this->costs->record($trip, [
            'cost_type' => 'fuel', 'amount' => '500.00',
            'source' => CostSource::TELEMETRY, 'source_ref' => 'TELE-9001',
        ], self::TENANT_A, null);

        $this->assertSame(CostSource::TELEMETRY, $cost->source);
        $this->assertTrue($cost->isDeduplicated());
    }

    /* ── QA-005, duplicates. Two layers, two behaviours. ──────────────── */

    public function test_a_redelivered_system_cost_is_absorbed_not_doubled(): void
    {
        $trip = $this->trip();

        $payload = [
            'cost_type' => 'fuel', 'amount' => '500.00',
            'source' => CostSource::TELEMETRY, 'source_ref' => 'TELE-9001',
        ];

        $first  = $this->costs->record($trip, $payload, self::TENANT_A, null);
        $second = $this->costs->record($trip, $payload, self::TENANT_A, null);

        $this->assertSame($first->id, $second->id, 'a retry must return the original row');
        $this->assertSame(1, TripCost::forTenant(self::TENANT_A)->forTrip($trip->id)->count());
        $this->assertSame('500.00', $this->costs->totalFor($trip->id, self::TENANT_A));
    }

    public function test_one_source_transaction_may_carry_several_cost_types(): void
    {
        $trip = $this->trip();

        // A single fuel bill legitimately holding fuel and a service charge.
        // The unique index includes cost_type precisely so this is not refused.
        $this->costs->record($trip, [
            'cost_type' => 'fuel', 'amount' => '500.00',
            'source' => CostSource::IMPORT, 'source_ref' => 'BILL-1',
        ], self::TENANT_A, null);

        $this->costs->record($trip, [
            'cost_type' => 'toll', 'amount' => '60.00',
            'source' => CostSource::IMPORT, 'source_ref' => 'BILL-1',
        ], self::TENANT_A, null);

        $this->assertSame('560.00', $this->costs->totalFor($trip->id, self::TENANT_A));
    }

    public function test_a_manual_look_alike_is_reported_rather_than_silently_accepted(): void
    {
        $trip = $this->trip();
        $this->record($trip, ['incurred_on' => '2026-09-10']);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessageMatches('/confirm_duplicate/');
        $this->record($trip, ['incurred_on' => '2026-09-10']);
    }

    public function test_a_confirmed_manual_duplicate_is_accepted(): void
    {
        $trip = $this->trip();
        $this->record($trip, ['incurred_on' => '2026-09-10']);

        // A driver can genuinely buy fuel twice in a day. Refusing outright
        // would make correct data unenterable.
        $this->record($trip, ['incurred_on' => '2026-09-10', 'confirm_duplicate' => true]);

        $this->assertSame(2, TripCost::forTenant(self::TENANT_A)->forTrip($trip->id)->count());
        $this->assertSame('2401.00', $this->costs->totalFor($trip->id, self::TENANT_A));
    }

    public function test_a_different_amount_on_the_same_day_is_not_a_look_alike(): void
    {
        $trip = $this->trip();
        $this->record($trip, ['incurred_on' => '2026-09-10']);
        $this->record($trip, ['incurred_on' => '2026-09-10', 'amount' => '99.00']);

        $this->assertSame(2, TripCost::forTenant(self::TENANT_A)->forTrip($trip->id)->count());
    }

    /* ── cost_type normalisation — this is what keeps 018 honest ──────── */

    public function test_case_and_spacing_fold_onto_one_type(): void
    {
        $trip = $this->trip();

        $this->record($trip, ['cost_type' => 'Fuel',   'amount' => '10.00']);
        $this->record($trip, ['cost_type' => ' FUEL ', 'amount' => '20.00']);
        $this->record($trip, ['cost_type' => 'fuel',   'amount' => '30.00']);

        $breakdown = $this->costs->breakdownFor($trip->id, self::TENANT_A);

        $this->assertSame(['fuel' => '60.00'], $breakdown, 'three spellings must be one group');
    }

    public function test_hyphens_and_spaces_both_become_underscores(): void
    {
        $this->assertSame('driver_allowance', CostType::normalise('Driver Allowance'));
        $this->assertSame('driver_allowance', CostType::normalise('driver-allowance'));
        $this->assertSame('driver_allowance', CostType::normalise('  driver   allowance  '));
    }

    public function test_normalisation_holds_on_a_direct_create_not_only_via_the_service(): void
    {
        // The mutator is on the model for exactly this reason: seeders,
        // factories and imports never call the service, and a rule that guards
        // an aggregate has to hold on every write path.
        $trip = $this->trip();

        $cost = TripCost::create([
            'tenant_id' => self::TENANT_A, 'trip_id' => $trip->id,
            'cost_type' => 'Road TAX', 'amount' => '5.00', 'source' => CostSource::MANUAL,
        ]);

        $this->assertSame('road_tax', $cost->cost_type);
    }

    public function test_an_unlisted_cost_type_is_still_accepted(): void
    {
        // CST-001 does not exist, so there is no vocabulary to enforce and
        // rejecting an unlisted type would be enforcing an invented one.
        $cost = $this->record($this->trip(), ['cost_type' => 'ferry_charge']);

        $this->assertSame('ferry_charge', $cost->cost_type);
        $this->assertFalse(CostType::isKnown('ferry_charge'));
    }

    public function test_a_cost_type_longer_than_the_column_is_refused_not_truncated(): void
    {
        $this->expectException(BusinessException::class);
        $this->record($this->trip(), ['cost_type' => str_repeat('a', 41)]);
    }

    /* ── Amounts. FIN-06 territory. ───────────────────────────────────── */

    public function test_a_zero_or_negative_cost_is_refused(): void
    {
        $trip = $this->trip();

        $refused = 0;

        foreach (['0', '0.00', '-1.00', '-0.01', 'abc', ''] as $bad) {
            try {
                $this->record($trip, ['amount' => $bad]);
                $this->fail("an amount of '{$bad}' should have been refused");
            } catch (BusinessException) {
                $refused++;
            }
        }

        // Counted rather than merely caught: a test whose only outcome is an
        // empty catch block still passes when the guard is deleted.
        $this->assertSame(6, $refused);
        $this->assertSame('0.00', $this->costs->totalFor($trip->id, self::TENANT_A));
        $this->assertSame(0, TripCost::withTrashed()->count());
    }

    public function test_sums_do_not_drift(): void
    {
        $trip = $this->trip();

        // The classic float case: 0.1 + 0.2 must be 0.30, not 0.30000000000000004.
        $this->record($trip, ['cost_type' => 'a', 'amount' => '0.10']);
        $this->record($trip, ['cost_type' => 'b', 'amount' => '0.20']);

        $this->assertSame('0.30', $this->costs->totalFor($trip->id, self::TENANT_A));
    }

    public function test_many_small_costs_still_total_exactly(): void
    {
        $trip = $this->trip();

        for ($i = 0; $i < 100; $i++) {
            $this->record($trip, [
                'cost_type' => 'toll', 'amount' => '0.07',
                'source' => CostSource::IMPORT, 'source_ref' => 'T-'.$i,
            ], null);
        }

        $this->assertSame('7.00', $this->costs->totalFor($trip->id, self::TENANT_A));
    }

    /* ── Retraction ───────────────────────────────────────────────────── */

    public function test_retracting_removes_a_cost_from_the_total_but_keeps_the_row(): void
    {
        $trip = $this->trip();
        $cost = $this->record($trip);

        $this->assertSame('1200.50', $this->costs->totalFor($trip->id, self::TENANT_A));

        $this->costs->retract($cost, 'keyed against the wrong trip', self::TENANT_A, $this->actor);

        $this->assertSame('0.00', $this->costs->totalFor($trip->id, self::TENANT_A));
        $this->assertSame(1, TripCost::withTrashed()->forTenant(self::TENANT_A)->count(),
            'the row must survive for SNG-TRN-018 to explain the change');
    }

    public function test_retracting_requires_a_reason(): void
    {
        $cost = $this->record($this->trip());

        $this->expectException(BusinessException::class);
        $this->costs->retract($cost, '   ', self::TENANT_A, $this->actor);
    }

    public function test_another_tenants_cost_cannot_be_retracted(): void
    {
        $cost = $this->record($this->trip());

        $this->expectException(ResourceNotFoundException::class);
        $this->costs->retract($cost, 'not mine', self::TENANT_B, $this->actor);
    }

    /* ── Tenancy on every read ────────────────────────────────────────── */

    public function test_costs_are_tenant_scoped(): void
    {
        $tripA = $this->trip(self::TENANT_A);
        $tripB = $this->trip(self::TENANT_B);

        $this->record($tripA, ['amount' => '10.00']);
        $this->costs->record($tripB, ['cost_type' => 'fuel', 'amount' => '999.00'], self::TENANT_B, null);

        $this->assertSame('10.00', $this->costs->totalFor($tripA->id, self::TENANT_A));
        $this->assertSame('999.00', $this->costs->totalFor($tripB->id, self::TENANT_B));
        $this->assertCount(0, $this->costs->forTrip($tripB->id, self::TENANT_A));
    }

    /* ── The breakdown 018 consumes ───────────────────────────────────── */

    public function test_the_breakdown_groups_by_type_and_is_ordered(): void
    {
        $trip = $this->trip();

        $this->record($trip, ['cost_type' => 'toll', 'amount' => '60.00']);
        $this->record($trip, ['cost_type' => 'fuel', 'amount' => '500.00']);
        $this->record($trip, ['cost_type' => 'fuel', 'amount' => '250.00',
            'source' => CostSource::IMPORT, 'source_ref' => 'X-1'], null);

        $this->assertSame(
            ['fuel' => '750.00', 'toll' => '60.00'],
            $this->costs->breakdownFor($trip->id, self::TENANT_A)
        );
    }

    public function test_a_retracted_cost_leaves_the_breakdown(): void
    {
        $trip = $this->trip();
        $keep = $this->record($trip, ['cost_type' => 'fuel', 'amount' => '500.00']);
        $drop = $this->record($trip, ['cost_type' => 'toll', 'amount' => '60.00']);

        $this->costs->retract($drop, 'duplicate of a telemetry row', self::TENANT_A, $this->actor);

        $this->assertSame(['fuel' => '500.00'], $this->costs->breakdownFor($trip->id, self::TENANT_A));
        $this->assertNotNull($keep->fresh());
    }

    /* ── The trip relation ────────────────────────────────────────────── */

    public function test_a_trip_exposes_its_costs(): void
    {
        $trip = $this->trip();
        $this->record($trip);

        $this->assertCount(1, $trip->fresh()->costs);
    }

    /* ── Auditing ─────────────────────────────────────────────────────── */

    public function test_recording_and_retracting_are_both_audited(): void
    {
        $trip = $this->trip();
        $cost = $this->record($trip);

        $this->assertSame(1, $cost->auditTrail()->count());

        $this->costs->retract($cost, 'wrong trip', self::TENANT_A, $this->actor);

        $this->assertSame(2, $cost->auditTrail()->count());
    }

    public function test_an_absorbed_duplicate_is_not_audited_again(): void
    {
        $trip = $this->trip();

        $payload = [
            'cost_type' => 'fuel', 'amount' => '500.00',
            'source' => CostSource::TELEMETRY, 'source_ref' => 'TELE-1',
        ];

        $first = $this->costs->record($trip, $payload, self::TENANT_A, null);
        $this->costs->record($trip, $payload, self::TENANT_A, null);

        // A trail that logs non-events is one nobody reads.
        $this->assertSame(1, $first->auditTrail()->count());
    }

    /* ── What this ticket deliberately did NOT build ──────────────────── */

    public function test_there_is_no_approval_state_on_a_cost(): void
    {
        // approval_status is FLD-013 and belongs to DB-008 trip_expenses, a
        // separate LOCKED table. Collapsing the two is FORBID-005. D-58.
        $cost = $this->record($this->trip());

        $this->assertArrayNotHasKey('approval_status', $cost->getAttributes());
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('trip_costs', 'approval_status'));
    }

    public function test_the_expense_source_is_declared_but_unreachable_by_hand(): void
    {
        // Declared so the reconciliation path has somewhere to land once the
        // cost/expense boundary is ruled, and so nobody invents a second
        // spelling for it in the meantime.
        $this->assertContains(CostSource::EXPENSE, CostSource::ALL);
        $this->assertNotContains(CostSource::EXPENSE, CostSource::OPERATOR_WRITABLE);
    }
}
