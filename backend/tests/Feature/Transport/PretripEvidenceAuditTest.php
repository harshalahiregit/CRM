<?php

namespace Tests\Feature\Transport;

use App\Exceptions\BusinessException;
use App\Models\Tenant;
use App\Models\Transport\TransportAuditLog;
use App\Models\Transport\TransportDriver;
use App\Models\Transport\TransportOrder;
use App\Models\Transport\TransportTrip;
use App\Models\Transport\TransportVehicle;
use App\Models\Transport\TripAssignment;
use App\Models\Transport\TripPretripCheck;
use App\Models\User;
use App\Services\Transport\AllocationService;
use App\Services\Transport\PretripService;
use App\Services\Transport\TransportDriverService;
use App\Services\Transport\TransportPolicyService;
use App\Services\Transport\TransportVehicleService;
use App\Support\Transport\OrderStatus;
use App\Support\Transport\PretripCheckKey;
use App\Support\Transport\PretripReadiness;
use App\Support\Transport\PretripResult;
use App\Support\Transport\TripStatus;
use App\Support\Transport\VehicleStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use App\Domains\Fleet\Models\DriverProfile;
use App\Domains\Fleet\Models\Vehicle;
use Tests\Concerns\CreatesFleetResources;
use Tests\TestCase;

/**
 * SNG-TRN-010 step 9 — the evidence each governing rule actually requires.
 *
 * Ticket 009's step 9 found that refused allocations left no trace at all,
 * despite BR-P0-003 and BR-P0-004 naming an audit artefact in their Audit
 * Evidence column. This file is the equivalent sweep for pre-trip.
 *
 * ── WHAT THE SWEEP FOUND ──────────────────────────────────────────────────
 * There is NO BR-P0 rule for pre-trip checks. The S3 BRWM_Core_Rules sheet has
 * twenty rules and none of them covers dispatch readiness, so — unlike ticket
 * 009 — no Audit Evidence column mandates anything here. The enforcement rules
 * are BRW-046/BRW-052 in the BRWM narrative, OPS §30 and CMP §159, none of which
 * has an evidence column at all.
 *
 * Evidence is therefore written to ticket 009's standard rather than to the
 * letter of a requirement, and these tests hold it there.
 */
class PretripEvidenceAuditTest extends TestCase
{
    use RefreshDatabase;
    use CreatesFleetResources;

    private const TENANT_A = 1;

    private PretripService $pretrip;
    private AllocationService $alloc;
    private TransportVehicleService $vehicleSvc;
    private TransportDriverService $driverSvc;
    private TransportPolicyService $policies;
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT_A, 'name' => 'Alpha', 'slug' => 'alpha',
            'subdomain' => 'alpha', 'status' => 'active',
        ])->save();

        $this->pretrip    = app(PretripService::class);
        $this->alloc      = app(AllocationService::class);
        $this->vehicleSvc = app(TransportVehicleService::class);
        $this->driverSvc  = app(TransportDriverService::class);
        $this->policies   = app(TransportPolicyService::class);

        $this->actor = User::create([
            'tenant_id' => self::TENANT_A, 'name' => 'Supervisor', 'role' => 'staff',
            'internal_role' => 'transport_dispatcher',
            'email' => 's-'.Str::random(6).'@test.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function crewed(): array
    {
        $order = TransportOrder::create([
            'tenant_id' => self::TENANT_A, 'order_number' => 'TO-'.Str::random(8), 'customer_id' => 1,
            'pickup_location' => ['address' => 'A'], 'delivery_location' => ['address' => 'B'],
            'required_at' => now()->addDays(3), 'service_type' => 'Container Haulage',
        ]);
        $order->forceFill(['order_status' => OrderStatus::APPROVED])->save();

        $trip = TransportTrip::create([
            'tenant_id' => self::TENANT_A, 'order_id' => $order->id,
            'trip_number' => 'TRP-'.Str::random(8), 'customer_id' => 1,
        ]);
        $trip->forceFill(['status' => TripStatus::APPROVED])->save();

        $v = $this->fleetVehicle([
            'registration_number' => 'MH12AB'.self::uniqueSeq(4),
            'vehicle_type' => 'Trailer 40ft', 'capacity_tonnes' => 30,
        ], self::TENANT_A, $this->actor);
        $v = $this->moveFleetVehicle($v, Vehicle::STATUS_AVAILABLE);

        $d = $this->fleetDriver([
            'name' => 'Ramesh '.Str::random(4),
            'licence_number' => 'RJ14'.self::uniqueSeq(6),
            'licence_class' => 'HMV',
            'licence_valid_until' => now()->addYears(2)->toDateString(),
        ], self::TENANT_A, $this->actor);

        $this->alloc->assign($trip->fresh(), $v->id, $d->id, self::TENANT_A, $this->actor);

        return [$trip->fresh(), $v->fresh(), $d->fresh()];
    }

    private function ready(): array
    {
        [$trip, $v, $d] = $this->crewed();
        $this->pretrip->generate($trip, self::TENANT_A, $this->actor);
        foreach ($this->pretrip->checksFor($trip, self::TENANT_A) as $c) {
            $this->pretrip->complete($c, self::TENANT_A, $this->actor, 'seen');
        }

        return [$trip->fresh(), $v, $d];
    }

    private function actions(TransportTrip $trip): array
    {
        return $trip->auditTrail()->pluck('action')->all();
    }

    /* ══════════ BRW-046 / BRW-052 / OPS §30 — the block is evidenced ══════════ */

    public function test_a_refused_gate_leaves_evidence_that_survives_the_exception(): void
    {
        [$trip, , $driver] = $this->ready();
        $driver->forceFill(['licence_valid_until' => now()->subDay()])->save();
        $this->pretrip->generate($trip, self::TENANT_A, $this->actor);

        try {
            $this->pretrip->passPretrip($trip->fresh(), self::TENANT_A, $this->actor);
            $this->fail('expected a refusal');
        } catch (BusinessException) {
        }

        $entry = $trip->auditTrail()->where('action', 'transport.pretrip.refused')->first();

        $this->assertNotNull($entry, 'the refusal must outlive the exception that carried it');
        $this->assertSame(PretripReadiness::BLOCKED, $entry->context['readiness']);
        $this->assertNotEmpty($entry->context['blockers']);
        $this->assertSame($this->actor->id, $entry->actor_id);
    }

    public function test_the_refusal_records_every_check_not_only_the_failures(): void
    {
        // A review needs to know what was VERIFIED, not only what went wrong —
        // the same reasoning as the allocation refusal audit in ticket 009.
        [$trip, , $driver] = $this->ready();
        $driver->forceFill(['licence_valid_until' => now()->subDay()])->save();
        $this->pretrip->generate($trip, self::TENANT_A, $this->actor);

        try { $this->pretrip->passPretrip($trip->fresh(), self::TENANT_A, $this->actor); } catch (BusinessException) {}

        $ctx = $trip->auditTrail()->where('action', 'transport.pretrip.refused')->first()->context;

        $this->assertCount(5, $ctx['checks']);
        $keys = array_column($ctx['checks'], 'key');
        $this->assertSame(PretripCheckKey::GENERATED, $keys);
        foreach ($ctx['checks'] as $c) {
            $this->assertArrayHasKey('critical', $c);
            $this->assertArrayHasKey('result', $c);
            $this->assertArrayHasKey('detail', $c);
            $this->assertArrayHasKey('completed', $c);
        }
    }

    public function test_the_refusal_names_the_rules_it_enforces(): void
    {
        [$trip] = $this->crewed();
        $this->pretrip->generate($trip, self::TENANT_A, $this->actor);   // nothing confirmed

        try { $this->pretrip->passPretrip($trip->fresh(), self::TENANT_A, $this->actor); } catch (BusinessException) {}

        $ctx = $trip->auditTrail()->where('action', 'transport.pretrip.refused')->first()->context;

        $this->assertSame('BRW-046', $ctx['rule']);
        foreach (['BRW-046', 'BRW-052', 'OPS §30', 'CMP §159'] as $source) {
            $this->assertStringContainsString($source, $ctx['sources']);
        }
    }

    public function test_an_incomplete_refusal_records_what_was_outstanding(): void
    {
        [$trip] = $this->crewed();
        $this->pretrip->generate($trip, self::TENANT_A, $this->actor);
        $this->pretrip->complete($this->pretrip->checksFor($trip, self::TENANT_A)[0], self::TENANT_A, $this->actor);

        try { $this->pretrip->passPretrip($trip->fresh(), self::TENANT_A, $this->actor); } catch (BusinessException) {}

        $ctx = $trip->auditTrail()->where('action', 'transport.pretrip.refused')->first()->context;

        $this->assertSame(PretripReadiness::IN_PROGRESS, $ctx['readiness']);
        $this->assertCount(4, $ctx['incomplete']);
    }

    public function test_a_refusal_changes_nothing(): void
    {
        [$trip, $vehicle, $driver] = $this->ready();
        $driver->forceFill(['licence_valid_until' => now()->subDay()])->save();
        $this->pretrip->generate($trip, self::TENANT_A, $this->actor);

        $before = TripPretripCheck::forTenant(self::TENANT_A)->forTrip($trip->id)->get()
            ->map(fn ($c) => $c->result.'|'.($c->completed_at?->toIso8601String() ?? ''))->all();

        try { $this->pretrip->passPretrip($trip->fresh(), self::TENANT_A, $this->actor); } catch (BusinessException) {}

        $after = TripPretripCheck::forTenant(self::TENANT_A)->forTrip($trip->id)->get()
            ->map(fn ($c) => $c->result.'|'.($c->completed_at?->toIso8601String() ?? ''))->all();

        $this->assertSame($before, $after, 'a refused gate must not mutate the checklist');
        $this->assertSame(TripStatus::ALLOCATED, $trip->fresh()->status);
        $this->assertSame(VehicleStatus::ALLOCATED, $vehicle->fresh()->status);
    }

    public function test_repeated_refusals_each_leave_a_row(): void
    {
        [$trip] = $this->crewed();
        $this->pretrip->generate($trip, self::TENANT_A, $this->actor);

        foreach (range(1, 3) as $i) {
            try { $this->pretrip->passPretrip($trip->fresh(), self::TENANT_A, $this->actor); } catch (BusinessException) {}
        }

        $this->assertSame(3, $trip->auditTrail()->where('action', 'transport.pretrip.refused')->count());
    }

    /* ══════════ STT-005 "Audit Yes" ══════════ */

    public function test_the_transition_carries_the_evidence_it_relied_on(): void
    {
        [$trip] = $this->ready();
        $this->pretrip->passPretrip($trip, self::TENANT_A, $this->actor);

        $entry = $trip->auditTrail()->where('action', 'transport.trip.status_changed')->get()
            ->first(fn ($e) => ($e->new_values['status'] ?? null) === TripStatus::PRETRIP_OK);

        $this->assertNotNull($entry);
        $this->assertStringContainsString('STT-005', $entry->context['registry']);
        $this->assertStringContainsString('D-18', $entry->context['registry'], 'the deferred half must stay visible');
        $this->assertCount(5, $entry->context['checks']);
        foreach ($entry->context['checks'] as $c) {
            $this->assertNotNull($c['completed_by'], 'every check must name who confirmed it');
            $this->assertNotNull($c['completed_at']);
        }
    }

    /* ══════════ the gap this step found ══════════ */

    public function test_a_revoked_confirmation_is_recorded_in_its_own_right(): void
    {
        // Found during the step 9 sweep. Regeneration silently cleared a
        // person's confirmation when the underlying fact changed; the only trace
        // was a difference between two `generated` rows. An auditor asking "who
        // signed this off, and why does it no longer hold?" should not have to
        // diff two checklists.
        [$trip, , $driver] = $this->ready();

        $driver->forceFill(['licence_valid_until' => now()->subDay()])->save();
        $this->pretrip->generate($trip, self::TENANT_A, $this->actor);

        $entry = $trip->auditTrail()->where('action', 'transport.pretrip.confirmations_revoked')->first();

        $this->assertNotNull($entry, 'a revoked confirmation must be recorded');
        $this->assertCount(1, $entry->context['revoked']);

        $r = $entry->context['revoked'][0];
        $this->assertSame(PretripCheckKey::DRIVER_DOCUMENTS, $r['check']);
        $this->assertSame(PretripResult::PASS, $r['was_result']);
        $this->assertSame(PretripResult::CRITICAL_FAIL, $r['now_result']);
        $this->assertSame($this->actor->id, $r['completed_by'], 'it names WHOSE confirmation was revoked');
        $this->assertNotNull($r['completed_at']);
    }

    public function test_nothing_is_recorded_when_no_confirmation_is_revoked(): void
    {
        // The trail must not become a click log — the same line ticket 009 drew.
        [$trip] = $this->ready();

        $this->pretrip->generate($trip, self::TENANT_A, $this->actor);   // nothing changed

        $this->assertSame(
            0,
            $trip->auditTrail()->where('action', 'transport.pretrip.confirmations_revoked')->count(),
        );
    }

    public function test_an_unconfirmed_check_changing_result_is_not_a_revocation(): void
    {
        [$trip, , $driver] = $this->crewed();
        $this->pretrip->generate($trip, self::TENANT_A, $this->actor);   // evaluated, none confirmed

        $driver->forceFill(['licence_valid_until' => now()->subDay()])->save();
        $this->pretrip->generate($trip, self::TENANT_A, $this->actor);

        $this->assertSame(
            0,
            $trip->auditTrail()->where('action', 'transport.pretrip.confirmations_revoked')->count(),
            'nobody vouched for it, so nothing was revoked',
        );
    }

    /* ══════════ precondition failures stay OUT of the trail ══════════ */

    public function test_precondition_failures_are_not_audited(): void
    {
        // Ticket 009's standing ruling: a wrong-state call is not a conflict,
        // and logging it would turn the trail into a click log.
        [$trip] = $this->crewed();
        $trip->forceFill(['status' => TripStatus::DRAFT])->save();

        $before = $trip->auditTrail()->count();

        try { $this->pretrip->generate($trip->fresh(), self::TENANT_A, $this->actor); } catch (BusinessException) {}
        try { $this->pretrip->passPretrip($trip->fresh(), self::TENANT_A, $this->actor); } catch (BusinessException) {}

        $this->assertSame($before, $trip->fresh()->auditTrail()->count());
    }

    public function test_passing_twice_does_not_re_audit(): void
    {
        [$trip] = $this->ready();
        $this->pretrip->passPretrip($trip, self::TENANT_A, $this->actor);

        $before = $trip->fresh()->auditTrail()->count();
        try { $this->pretrip->passPretrip($trip->fresh(), self::TENANT_A, $this->actor); } catch (BusinessException) {}

        $this->assertSame($before, $trip->fresh()->auditTrail()->count());
    }

    /* ══════════ CMP §159 — the gate is deterministic ══════════ */

    public function test_the_gate_reads_the_stored_checklist_not_a_fresh_evaluation(): void
    {
        // CMP §159: "OPS should query CMP: Is this transaction compliant for
        // dispatch? CMP responds DETERMINISTICALLY." The answer a dispatcher was
        // shown is the answer the gate acts on — a gate that re-evaluated could
        // refuse a trip whose screen said READY a second earlier, with nothing
        // on that screen to explain it.
        [$trip, , $driver] = $this->ready();

        // The world changes, but nothing has regenerated the checklist.
        $driver->forceFill(['licence_valid_until' => now()->subDay()])->save();

        $moved = $this->pretrip->passPretrip($trip->fresh(), self::TENANT_A, $this->actor);

        $this->assertSame(TripStatus::PRETRIP_OK, $moved->status);
        // ...and the very next regeneration would have caught it, which is the
        // safety net that makes determinism affordable.
        $this->assertSame(
            PretripResult::PASS,
            $this->pretrip->checksFor($trip->fresh(), self::TENANT_A)
                ->firstWhere('check_key', PretripCheckKey::DRIVER_DOCUMENTS)->result,
        );
    }

    public function test_the_same_checklist_always_yields_the_same_verdict(): void
    {
        [$trip] = $this->ready();

        $a = $this->pretrip->readiness($trip, self::TENANT_A)['status'];
        $b = $this->pretrip->readiness($trip->fresh(), self::TENANT_A)['status'];
        $c = $this->pretrip->readiness($trip->fresh(), self::TENANT_A)['status'];

        $this->assertSame([PretripReadiness::READY, PretripReadiness::READY, PretripReadiness::READY], [$a, $b, $c]);
    }

    /* ══════════ the whole trail reads as a story ══════════ */

    public function test_a_full_run_leaves_a_readable_trail(): void
    {
        [$trip] = $this->crewed();
        $this->pretrip->generate($trip, self::TENANT_A, $this->actor);
        foreach ($this->pretrip->checksFor($trip, self::TENANT_A) as $c) {
            $this->pretrip->complete($c, self::TENANT_A, $this->actor);
        }
        $this->pretrip->passPretrip($trip->fresh(), self::TENANT_A, $this->actor);

        $actions = $this->actions($trip);

        $this->assertContains('transport.pretrip.generated', $actions);
        $this->assertContains('transport.trip.status_changed', $actions);

        // Completion is audited against the CHECK, not the trip — that is where
        // the question "who confirmed this one?" is asked.
        $completions = TransportAuditLog::where('tenant_id', self::TENANT_A)
            ->where('action', 'transport.pretrip.check_completed')->count();
        $this->assertSame(5, $completions);
    }

    public function test_the_release_cycle_leaves_a_readable_trail(): void
    {
        [$trip] = $this->ready();
        $this->pretrip->passPretrip($trip, self::TENANT_A, $this->actor);

        $assignment = TripAssignment::forTenant(self::TENANT_A)->forTrip($trip->id)->active()->firstOrFail();
        $this->alloc->release($assignment, self::TENANT_A, $this->actor, 'Breakdown');

        $actions = $this->actions($trip);
        $this->assertContains('transport.pretrip.invalidated', $actions);

        $entry = $trip->auditTrail()->where('action', 'transport.pretrip.invalidated')->first();
        $this->assertSame(PretripReadiness::READY, $entry->context['was'], 'what was discarded is named');
        $this->assertCount(5, $entry->context['checks']);
    }

    /* ══════════ scope boundaries, asserted as behaviour ══════════ */

    public function test_nothing_in_this_ticket_can_reach_dispatched(): void
    {
        [$trip] = $this->ready();
        $this->pretrip->passPretrip($trip, self::TENANT_A, $this->actor);

        // Updated 2026-09-10: dispatch is now a separate, separately-authorised
        // service. What this test guards is that PRE-TRIP never writes it —
        // passing the checklist must leave the trip at pretrip_ok and no further.
        $this->assertSame(TripStatus::PRETRIP_OK, $trip->fresh()->status);
        $this->assertSame(
            0,
            TransportTrip::where('status', TripStatus::DISPATCHED)->count(),
            'no PRE-TRIP code path writes `dispatched` — that is DispatchService',
        );
        // Updated 2026-09-17: dispatched → in_transit IS now wired (STT-006,
        // D-105). What this test guards is unchanged and is stated directly —
        // no PRE-TRIP path may skip a state, and none may record a departure.
        $this->assertFalse(TripStatus::canTransition(TripStatus::PRETRIP_OK, TripStatus::IN_TRANSIT));
        $this->assertSame(
            0,
            TransportTrip::whereNotNull('departed_at')->count(),
            'no pre-trip code path records a departure — that is DispatchService',
        );
    }

    public function test_no_override_is_ever_recorded(): void
    {
        [$trip, , $driver] = $this->ready();
        $driver->forceFill(['licence_valid_until' => now()->subDay()])->save();
        $this->pretrip->generate($trip, self::TENANT_A, $this->actor);

        foreach ($this->pretrip->checksFor($trip, self::TENANT_A) as $c) {
            $this->pretrip->complete($c, self::TENANT_A, $this->actor, 'aware');
        }

        // Every item seen, one still failing — and no override exists to clear it.
        foreach (TripPretripCheck::forTenant(self::TENANT_A)->forTrip($trip->id)->get() as $row) {
            $this->assertNull($row->overridden_by);
            $this->assertNull($row->overridden_at);
            $this->assertNull($row->override_reason);
        }
        $this->assertSame(PretripReadiness::BLOCKED, $this->pretrip->readiness($trip->fresh(), self::TENANT_A)['status']);
    }

    public function test_no_unreachable_check_is_ever_written(): void
    {
        [$trip] = $this->ready();

        $written = TripPretripCheck::forTenant(self::TENANT_A)->pluck('check_key')->unique()->all();
        foreach ($written as $k) {
            $this->assertTrue(PretripCheckKey::isGenerated($k), "{$k} must never be generated");
        }
    }

    public function test_photo_evidence_has_no_storage_path_in_this_ticket(): void
    {
        // D-22 — recorded, not quietly skipped.
        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasColumn('trip_pretrip_checks', 'file_path'),
        );
        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasColumn('trip_pretrip_checks', 'evidence'),
        );
    }
}
