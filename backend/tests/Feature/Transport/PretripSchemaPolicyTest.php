<?php

namespace Tests\Feature\Transport;

use App\Models\Tenant;
use App\Models\Transport\TransportOrder;
use App\Models\Transport\TransportTrip;
use App\Models\Transport\TripPretripCheck;
use App\Models\User;
use App\Services\Transport\TransportPolicyService;
use App\Support\Transport\PretripCheckKey;
use App\Support\Transport\PretripReadiness;
use App\Support\Transport\PretripResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SNG-TRN-010 steps 2 and 3 — the table, the model, and the policy.
 *
 * Two things are load-bearing here and are tested hardest: tenant scoping (no
 * query in this module may cross a tenant), and the derived readiness rule,
 * which is the whole of OPS §29 and BRW-046 expressed as one function.
 */
class PretripSchemaPolicyTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private TransportPolicyService $policies;
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::TENANT_A => 'Alpha Transport', self::TENANT_B => 'Bravo Logistics'] as $id => $name) {
            (new Tenant())->forceFill([
                'id' => $id, 'name' => $name, 'slug' => Str::slug($name),
                'subdomain' => Str::slug($name), 'status' => 'active',
            ])->save();
        }

        $this->policies = app(TransportPolicyService::class);
        $this->actor = User::create([
            'tenant_id' => self::TENANT_A, 'name' => 'Supervisor', 'role' => 'staff',
            'internal_role' => 'transport_dispatcher',
            'email' => 's-'.Str::random(6).'@test.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function trip(int $tenantId = self::TENANT_A): TransportTrip
    {
        $order = TransportOrder::create([
            'tenant_id' => $tenantId, 'order_number' => 'TO-'.Str::random(8), 'customer_id' => 1,
            'pickup_location' => ['address' => 'A'], 'delivery_location' => ['address' => 'B'],
            'required_at' => now()->addDays(3), 'service_type' => 'Container Haulage',
        ]);

        return TransportTrip::create([
            'tenant_id' => $tenantId, 'order_id' => $order->id,
            'trip_number' => 'TRP-'.Str::random(8), 'customer_id' => 1,
        ]);
    }

    private function check(TransportTrip $trip, string $key, string $result, bool $critical = true, bool $completed = false): TripPretripCheck
    {
        $row = TripPretripCheck::create([
            'tenant_id' => $trip->tenant_id, 'trip_id' => $trip->id,
            'check_key' => $key, 'is_critical' => $critical,
            'result' => $result, 'detail' => 'because', 'evaluated_at' => now(),
        ]);

        if ($completed) {
            $row->forceFill(['completed_by' => $this->actor->id, 'completed_at' => now()])->save();
        }

        return $row->fresh();
    }

    /* ══════════ Step 2 · the table ══════════ */

    public function test_the_table_and_its_columns_exist(): void
    {
        $this->assertTrue(Schema::hasTable('trip_pretrip_checks'));

        foreach ([
            'tenant_id', 'trip_id', 'check_key', 'is_critical', 'result', 'detail',
            'remarks', 'evaluated_at', 'completed_by', 'completed_at',
            'overridden_by', 'overridden_at', 'override_reason',
            'created_by', 'updated_by', 'created_at', 'updated_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('trip_pretrip_checks', $column),
                "trip_pretrip_checks.{$column} is missing",
            );
        }
    }

    public function test_it_has_no_soft_deletes(): void
    {
        // Transactional record: a run is invalidated, never destroyed.
        $this->assertFalse(Schema::hasColumn('trip_pretrip_checks', 'deleted_at'));
        $this->assertNotContains(
            \Illuminate\Database\Eloquent\SoftDeletes::class,
            class_uses_recursive(TripPretripCheck::class),
        );
    }

    public function test_a_trip_cannot_hold_two_rows_for_the_same_check(): void
    {
        $trip = $this->trip();
        $this->check($trip, PretripCheckKey::ORDER_APPROVED, PretripResult::PASS);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->check($trip, PretripCheckKey::ORDER_APPROVED, PretripResult::PASS);
    }

    public function test_the_same_check_may_exist_for_two_different_trips(): void
    {
        $a = $this->trip();
        $b = $this->trip();

        $this->check($a, PretripCheckKey::ORDER_APPROVED, PretripResult::PASS);
        $this->check($b, PretripCheckKey::ORDER_APPROVED, PretripResult::PASS);

        $this->assertSame(2, TripPretripCheck::count());
    }

    public function test_the_uniqueness_is_scoped_by_tenant(): void
    {
        // Two tenants, and — worst case — the same trip id. Neither may collide.
        $a = $this->trip(self::TENANT_A);
        $b = $this->trip(self::TENANT_B);

        $this->check($a, PretripCheckKey::ORDER_APPROVED, PretripResult::PASS);
        $this->check($b, PretripCheckKey::ORDER_APPROVED, PretripResult::PASS);

        $this->assertSame(1, TripPretripCheck::forTenant(self::TENANT_A)->count());
        $this->assertSame(1, TripPretripCheck::forTenant(self::TENANT_B)->count());
    }

    public function test_a_row_defaults_to_pending_and_non_critical(): void
    {
        $row = new TripPretripCheck();

        $this->assertSame(PretripResult::PENDING, $row->result);
        $this->assertFalse($row->is_critical);
        $this->assertTrue($row->isPending());
        $this->assertFalse($row->isCompleted());
    }

    public function test_completion_fields_are_not_mass_assignable(): void
    {
        // The acceptance criterion goes through the service so the act is
        // audited — a caller must not be able to stamp a check by passing a field.
        $trip = $this->trip();

        $row = TripPretripCheck::create([
            'tenant_id' => $trip->tenant_id, 'trip_id' => $trip->id,
            'check_key' => PretripCheckKey::DRIVER_ASSIGNED,
            'completed_by' => $this->actor->id,
            'completed_at' => now(),
            'remarks' => 'sneaked in',
        ]);

        $this->assertNull($row->completed_by);
        $this->assertNull($row->completed_at);
        $this->assertNull($row->remarks);
    }

    public function test_override_columns_are_reserved_and_never_written(): void
    {
        $trip = $this->trip();

        $row = TripPretripCheck::create([
            'tenant_id' => $trip->tenant_id, 'trip_id' => $trip->id,
            'check_key' => PretripCheckKey::VEHICLE_ASSIGNED,
            'overridden_by' => $this->actor->id,
            'override_reason' => 'let it through',
        ]);

        // CMP-007 / BRW-049 / PLN-007 are all P1. Nothing may record an override
        // before the ticket that authorises and audits one exists.
        $this->assertNull($row->overridden_by);
        $this->assertNull($row->overridden_at);
        $this->assertNull($row->override_reason);
    }

    public function test_tenant_scope_isolates_rows(): void
    {
        $a = $this->trip(self::TENANT_A);
        $b = $this->trip(self::TENANT_B);

        $this->check($a, PretripCheckKey::ORDER_APPROVED, PretripResult::PASS);
        $this->check($b, PretripCheckKey::ORDER_APPROVED, PretripResult::FAIL);
        $this->check($b, PretripCheckKey::DRIVER_ASSIGNED, PretripResult::FAIL);

        $this->assertSame(1, TripPretripCheck::forTenant(self::TENANT_A)->count());
        $this->assertSame(2, TripPretripCheck::forTenant(self::TENANT_B)->count());
        $this->assertSame(
            0,
            TripPretripCheck::forTenant(self::TENANT_A)->forTrip($b->id)->count(),
            'a tenant must never reach another tenant trip by id',
        );
    }

    /* ══════════ Step 2 · the model ══════════ */

    public function test_scopes_select_the_right_rows(): void
    {
        $trip = $this->trip();
        $this->check($trip, PretripCheckKey::ORDER_APPROVED, PretripResult::PASS, true, true);
        $this->check($trip, PretripCheckKey::DRIVER_ASSIGNED, PretripResult::CRITICAL_FAIL);
        $this->check($trip, PretripCheckKey::VEHICLE_ASSIGNED, PretripResult::PENDING);

        $scoped = fn () => TripPretripCheck::forTenant(self::TENANT_A)->forTrip($trip->id);

        $this->assertSame(2, $scoped()->outstanding()->count());
        $this->assertSame(1, $scoped()->blocking()->count());
    }

    public function test_a_check_knows_its_label_and_category(): void
    {
        $trip = $this->trip();
        $row = $this->check($trip, PretripCheckKey::DRIVER_DOCUMENTS, PretripResult::PASS);

        $this->assertSame('Driver documents valid', $row->label());
        $this->assertSame(PretripCheckKey::CATEGORY_DRIVER, $row->category());
        $this->assertSame('Driver', $row->categoryLabel());
        $this->assertSame('Pass', $row->resultLabel());
    }

    /* ══════════ Step 2 · the derived readiness rule ══════════ */

    public function test_no_rows_reads_not_started(): void
    {
        $this->assertSame(PretripReadiness::NOT_STARTED, TripPretripCheck::readinessOf([]));
    }

    public function test_an_unanswered_row_reads_in_progress(): void
    {
        $trip = $this->trip();
        $this->check($trip, PretripCheckKey::ORDER_APPROVED, PretripResult::PASS, true, true);
        $this->check($trip, PretripCheckKey::DRIVER_ASSIGNED, PretripResult::PASS);

        $rows = TripPretripCheck::forTenant(self::TENANT_A)->forTrip($trip->id)->get();
        $this->assertSame(PretripReadiness::IN_PROGRESS, TripPretripCheck::readinessOf($rows));
    }

    public function test_all_completed_and_passing_reads_ready(): void
    {
        $trip = $this->trip();
        $this->check($trip, PretripCheckKey::ORDER_APPROVED, PretripResult::PASS, true, true);
        $this->check($trip, PretripCheckKey::DRIVER_ASSIGNED, PretripResult::PASS_WARNING, true, true);

        $rows = TripPretripCheck::forTenant(self::TENANT_A)->forTrip($trip->id)->get();
        $this->assertSame(PretripReadiness::READY, TripPretripCheck::readinessOf($rows));
        $this->assertTrue(PretripReadiness::permitsTransition(TripPretripCheck::readinessOf($rows)));
    }

    public function test_a_critical_failure_reads_blocked(): void
    {
        $trip = $this->trip();
        $this->check($trip, PretripCheckKey::ORDER_APPROVED, PretripResult::PASS, true, true);
        $this->check($trip, PretripCheckKey::DRIVER_DOCUMENTS, PretripResult::CRITICAL_FAIL, true, true);

        $rows = TripPretripCheck::forTenant(self::TENANT_A)->forTrip($trip->id)->get();
        $this->assertSame(PretripReadiness::BLOCKED, TripPretripCheck::readinessOf($rows));
        $this->assertFalse(PretripReadiness::permitsTransition(TripPretripCheck::readinessOf($rows)));
    }

    public function test_blocked_beats_in_progress(): void
    {
        // The ordering rule. A checklist with one critical failure and one
        // unanswered item is BLOCKED, not merely unfinished — otherwise a screen
        // would imply that answering the rest would release the trip.
        $trip = $this->trip();
        $this->check($trip, PretripCheckKey::DRIVER_DOCUMENTS, PretripResult::CRITICAL_FAIL, true, true);
        $this->check($trip, PretripCheckKey::VEHICLE_ASSIGNED, PretripResult::PENDING);

        $rows = TripPretripCheck::forTenant(self::TENANT_A)->forTrip($trip->id)->get();
        $this->assertSame(PretripReadiness::BLOCKED, TripPretripCheck::readinessOf($rows));
    }

    public function test_a_non_critical_failure_warns_but_does_not_block(): void
    {
        // BRW-052, and the reason PretripResult has two failure grades.
        $trip = $this->trip();
        $this->check($trip, PretripCheckKey::ORDER_APPROVED, PretripResult::PASS, true, true);
        $this->check($trip, PretripCheckKey::DRIVER_ASSIGNED, PretripResult::FAIL, false, true);

        $rows = TripPretripCheck::forTenant(self::TENANT_A)->forTrip($trip->id)->get();

        $this->assertSame(PretripReadiness::READY, TripPretripCheck::readinessOf($rows));
        $this->assertSame([], TripPretripCheck::blockersOf($rows));
        $this->assertCount(1, TripPretripCheck::warningsOf($rows), 'UX §36 — a warning is not a block');
    }

    public function test_blockers_name_the_check_and_the_reason(): void
    {
        // BRW-048: "If dispatch fails, Sangoe must display exact reason."
        $trip = $this->trip();
        $row = $this->check($trip, PretripCheckKey::DRIVER_DOCUMENTS, PretripResult::CRITICAL_FAIL, true, true);
        $row->forceFill(['detail' => 'Licence expired 01 Jan 2026 — assign a compliant driver.'])->save();

        $blockers = TripPretripCheck::blockersOf(
            TripPretripCheck::forTenant(self::TENANT_A)->forTrip($trip->id)->get()
        );

        $this->assertCount(1, $blockers);
        $this->assertStringContainsString('Driver documents valid', $blockers[0]);
        $this->assertStringContainsString('Licence expired', $blockers[0]);
        $this->assertStringContainsString('assign a compliant driver', $blockers[0]);
    }

    public function test_readiness_never_returns_the_unreachable_status(): void
    {
        $trip = $this->trip();
        $this->check($trip, PretripCheckKey::ORDER_APPROVED, PretripResult::CRITICAL_FAIL, true, true);

        $rows = TripPretripCheck::forTenant(self::TENANT_A)->forTrip($trip->id)->get();
        $this->assertNotSame(PretripReadiness::OVERRIDE_REQUIRED, TripPretripCheck::readinessOf($rows));
        $this->assertContains(TripPretripCheck::readinessOf($rows), PretripReadiness::REACHABLE);
    }

    /* ══════════ Step 3 · policy ══════════ */

    public function test_defaults_enable_exactly_the_generated_checks(): void
    {
        $policy = $this->policies->all(self::TENANT_A);

        $this->assertSame(PretripCheckKey::GENERATED, $policy['pretrip.checks.enabled']);
    }

    public function test_every_generated_check_has_a_criticality_default(): void
    {
        $policy = $this->policies->all(self::TENANT_A);

        foreach (PretripCheckKey::GENERATED as $key) {
            $this->assertArrayHasKey('pretrip.check.'.$key.'.critical', $policy, "{$key} has no criticality default");
            $this->assertTrue(
                $this->policies->pretripCheckIsCritical($policy, $key),
                "{$key} must default to blocking — it traces to a Hard/Critical rule",
            );
        }
    }

    public function test_type_restrictions_are_empty_by_default(): void
    {
        // CMP §10, the NO ASSUMPTION PRINCIPLE: a restriction must have a basis.
        $policy = $this->policies->all(self::TENANT_A);

        $this->assertSame([], $policy['pretrip.checks.vehicle_types']);
        $this->assertSame([], $policy['pretrip.checks.service_types']);
    }

    public function test_a_tenant_can_relax_a_check_to_non_blocking(): void
    {
        // CMP §20: "the blocking rule must be configurable". S6-004 likewise.
        $this->policies->set(self::TENANT_A, 'pretrip.check.driver.assigned.critical', false, $this->actor);

        $policy = $this->policies->all(self::TENANT_A);
        $this->assertFalse($this->policies->pretripCheckIsCritical($policy, PretripCheckKey::DRIVER_ASSIGNED));
        // and it must not leak to the other tenant
        $this->assertTrue($this->policies->pretripCheckIsCritical(
            $this->policies->all(self::TENANT_B), PretripCheckKey::DRIVER_ASSIGNED
        ));
    }

    public function test_a_tenant_can_disable_a_check(): void
    {
        $this->policies->set(self::TENANT_A, 'pretrip.checks.enabled', [
            PretripCheckKey::ORDER_APPROVED, PretripCheckKey::VEHICLE_ASSIGNED,
        ], $this->actor);

        $policy = $this->policies->all(self::TENANT_A);
        $this->assertTrue($this->policies->pretripCheckApplies($policy, PretripCheckKey::ORDER_APPROVED));
        $this->assertFalse($this->policies->pretripCheckApplies($policy, PretripCheckKey::DRIVER_DOCUMENTS));
    }

    public function test_enabling_an_unevaluatable_check_is_refused_with_a_reason(): void
    {
        // Accepting it would generate a row that can never leave `pending`.
        try {
            $this->policies->set(self::TENANT_A, 'pretrip.checks.enabled', [
                PretripCheckKey::ORDER_APPROVED, PretripCheckKey::VEHICLE_TYRES,
            ], $this->actor);
            $this->fail('a declared-but-unevaluatable check must be refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Tyres acceptable', $e->getMessage());
            $this->assertStringContainsString('cannot be evaluated yet', $e->getMessage());
        }
    }

    public function test_enabling_an_unknown_check_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->policies->set(self::TENANT_A, 'pretrip.checks.enabled', ['vehicle.wings'], $this->actor);
    }

    public function test_an_unknown_policy_key_is_still_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->policies->set(self::TENANT_A, 'pretrip.check.made.up.critical', true, $this->actor);
    }

    /* ── FRS TRP-P0-005 — "mandatory checklist by vehicle/service type" ── */

    public function test_a_check_can_be_restricted_to_a_vehicle_type(): void
    {
        $this->policies->set(self::TENANT_A, 'pretrip.checks.vehicle_types', [
            PretripCheckKey::VEHICLE_COMPLIANCE => ['Reefer 20ft'],
        ], $this->actor);

        $policy = $this->policies->all(self::TENANT_A);

        $this->assertTrue($this->policies->pretripCheckApplies($policy, PretripCheckKey::VEHICLE_COMPLIANCE, 'Reefer 20ft'));
        $this->assertFalse($this->policies->pretripCheckApplies($policy, PretripCheckKey::VEHICLE_COMPLIANCE, 'Flatbed'));
        // an unrestricted check is unaffected
        $this->assertTrue($this->policies->pretripCheckApplies($policy, PretripCheckKey::ORDER_APPROVED, 'Flatbed'));
    }

    public function test_vehicle_type_matching_is_case_and_space_insensitive(): void
    {
        // Both sides are free text; no document defines a controlled vocabulary,
        // so the tenant's own spelling is the vocabulary.
        $this->policies->set(self::TENANT_A, 'pretrip.checks.vehicle_types', [
            PretripCheckKey::VEHICLE_ASSIGNED => ['  REEFER 20ft '],
        ], $this->actor);

        $policy = $this->policies->all(self::TENANT_A);
        $this->assertTrue($this->policies->pretripCheckApplies($policy, PretripCheckKey::VEHICLE_ASSIGNED, 'reefer 20ft'));
    }

    public function test_a_check_can_be_restricted_to_a_service_type(): void
    {
        $this->policies->set(self::TENANT_A, 'pretrip.checks.service_types', [
            PretripCheckKey::DRIVER_DOCUMENTS => ['Container Haulage'],
        ], $this->actor);

        $policy = $this->policies->all(self::TENANT_A);

        $this->assertTrue($this->policies->pretripCheckApplies($policy, PretripCheckKey::DRIVER_DOCUMENTS, null, 'Container Haulage'));
        $this->assertFalse($this->policies->pretripCheckApplies($policy, PretripCheckKey::DRIVER_DOCUMENTS, null, 'Bulk'));
    }

    public function test_both_restrictions_must_pass(): void
    {
        $this->policies->set(self::TENANT_A, 'pretrip.checks.vehicle_types', [
            PretripCheckKey::VEHICLE_ASSIGNED => ['Reefer'],
        ], $this->actor);
        $this->policies->set(self::TENANT_A, 'pretrip.checks.service_types', [
            PretripCheckKey::VEHICLE_ASSIGNED => ['Cold Chain'],
        ], $this->actor);

        $policy = $this->policies->all(self::TENANT_A);

        $this->assertTrue($this->policies->pretripCheckApplies($policy, PretripCheckKey::VEHICLE_ASSIGNED, 'Reefer', 'Cold Chain'));
        $this->assertFalse($this->policies->pretripCheckApplies($policy, PretripCheckKey::VEHICLE_ASSIGNED, 'Reefer', 'Bulk'));
        $this->assertFalse($this->policies->pretripCheckApplies($policy, PretripCheckKey::VEHICLE_ASSIGNED, 'Flatbed', 'Cold Chain'));
    }

    public function test_a_trip_with_no_vehicle_yet_keeps_the_full_checklist(): void
    {
        // Never shorten the checklist of the trips that are least ready.
        $this->policies->set(self::TENANT_A, 'pretrip.checks.vehicle_types', [
            PretripCheckKey::VEHICLE_ASSIGNED => ['Reefer'],
        ], $this->actor);

        $policy = $this->policies->all(self::TENANT_A);
        $this->assertTrue($this->policies->pretripCheckApplies($policy, PretripCheckKey::VEHICLE_ASSIGNED, null));
        $this->assertTrue($this->policies->pretripCheckApplies($policy, PretripCheckKey::VEHICLE_ASSIGNED, ''));
    }

    public function test_restricting_an_unevaluatable_check_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->policies->set(self::TENANT_A, 'pretrip.checks.vehicle_types', [
            PretripCheckKey::REEFER_GENSET => ['Reefer'],
        ], $this->actor);
    }

    public function test_policy_changes_stay_inside_their_tenant(): void
    {
        $this->policies->set(self::TENANT_A, 'pretrip.checks.enabled', [PretripCheckKey::ORDER_APPROVED], $this->actor);

        $this->assertCount(1, $this->policies->all(self::TENANT_A)['pretrip.checks.enabled']);
        $this->assertSame(
            PretripCheckKey::GENERATED,
            $this->policies->all(self::TENANT_B)['pretrip.checks.enabled'],
            'tenant B must still see the declared default',
        );
    }
}
