<?php

namespace Tests\Feature\Transport;

use App\Events\Transport\TripApproved;
use App\Exceptions\BusinessException;
use App\Models\Tenant;
use App\Models\Transport\TransportAuditLog;
use App\Models\Transport\TransportOrder;
use App\Models\Transport\TransportTrip;
use App\Models\User;
use App\Services\Transport\TransportTripService;
use App\Support\Transport\OrderStatus;
use App\Support\Transport\TransportPermission;
use App\Support\Transport\TripStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * STT-002 — `viability_pending → approved`.
 *
 * Until this shipped, NO trip created through the application could ever be
 * approved, so allocation, pre-trip and dispatch were all built and all
 * unreachable. D-58.
 *
 * Two things here are deliberately unusual and must not be "tidied":
 *   1. `test_the_margin_gate_is_still_deferred` is written to FAIL when
 *      SNG-TRN-008 lands. That is its job. See D-59.
 *   2. The Dispatcher denial is tested as hard as the grants. PERM-003 says N,
 *      and that N is the clearest evidence approval is a commercial decision
 *      rather than an operational step.
 */
class TripApprovalTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private TransportTripService $trips;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::TENANT_A => 'Alpha', self::TENANT_B => 'Bravo'] as $id => $n) {
            (new Tenant())->forceFill([
                'id' => $id, 'name' => $n, 'slug' => Str::slug($n),
                'subdomain' => Str::slug($n), 'status' => 'active',
            ])->save();
        }

        $this->trips = app(TransportTripService::class);
    }

    private function user(int $tenantId = self::TENANT_A, string $role = 'admin', ?string $internal = null): User
    {
        return User::create([
            'tenant_id' => $tenantId, 'name' => ucfirst($role), 'role' => $role, 'internal_role' => $internal,
            'email' => $role.'-'.Str::random(8).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    /** A trip sitting in viability_pending, built through the real transitions. */
    private function pendingTrip(int $tenantId = self::TENANT_A, ?float $freight = 45000): TransportTrip
    {
        $order = TransportOrder::create([
            'tenant_id' => $tenantId, 'order_number' => 'TO-'.Str::random(8), 'customer_id' => 7,
            'pickup_location' => ['address' => 'A'], 'delivery_location' => ['address' => 'B'],
            'required_at' => now()->addDays(3), 'service_type' => 'Container Haulage',
        ]);
        $order->forceFill(['order_status' => OrderStatus::APPROVED])->save();

        $trip = $this->trips->createFromOrder($order->id, [
            'route' => 'JNPT → Bhiwandi', 'approved_freight' => $freight,
        ], $tenantId, $this->user($tenantId));

        if ($freight === null) {
            // submitForViability refuses without freight, so this one is placed
            // directly — the state under test is the same.
            $trip->forceFill(['status' => TripStatus::VIABILITY_PENDING])->save();

            return $trip->fresh();
        }

        return $this->trips->submitForViability($trip, $tenantId, $this->user($tenantId));
    }

    /* ══════════ the happy path, and the chain it unblocks ══════════ */

    public function test_a_trip_awaiting_viability_can_be_approved(): void
    {
        $trip  = $this->pendingTrip();
        $actor = $this->user();

        $approved = $this->trips->approve($trip, self::TENANT_A, $actor);

        $this->assertSame(TripStatus::APPROVED, $approved->status);
        $this->assertSame($actor->id, $approved->approved_by);
        $this->assertNotNull($approved->approved_at);
    }

    public function test_the_whole_chain_is_now_reachable_without_touching_the_database(): void
    {
        // THE POINT OF THIS TICKET. Before STT-002 existed, a trip could reach
        // viability_pending and stop there forever — allocation, pre-trip and
        // dispatch were unreachable except from seeded data that bypassed the
        // state machine. This walks it with no forceFill on status at all.
        $trip = $this->pendingTrip();

        $this->assertSame(TripStatus::VIABILITY_PENDING, $trip->status);

        $approved = $this->trips->approve($trip, self::TENANT_A, $this->user());

        $this->assertSame(TripStatus::APPROVED, $approved->status);
        // approved is STT-004's starting state — allocation can now begin.
        $this->assertTrue(TripStatus::canTransition($approved->status, TripStatus::ALLOCATED));
    }

    public function test_the_transition_is_audited(): void
    {
        $trip = $this->pendingTrip();

        $this->trips->approve($trip, self::TENANT_A, $this->user());

        $entry = TransportAuditLog::forTenant(self::TENANT_A)
            ->forSubject(TransportTrip::class, $trip->id)
            ->where('action', 'transport.trip.status_changed')
            ->latest('id')->first();

        $this->assertNotNull($entry, 'STT-002 is marked Audit=Yes in the registry');
    }

    public function test_it_emits_evt_004_with_the_locked_payload(): void
    {
        Event::fake([TripApproved::class]);

        $trip  = $this->pendingTrip();
        $actor = $this->user();
        $this->trips->approve($trip, self::TENANT_A, $actor);

        Event::assertDispatched(TripApproved::class, function (TripApproved $e) use ($trip, $actor) {
            $p = $e->payload();

            // EVT-004 Payload Core is LOCKED at exactly these two fields.
            $this->assertSame(['trip_id', 'approved_by'], array_keys($p));
            $this->assertSame($trip->id, $p['trip_id']);
            $this->assertSame($actor->id, $p['approved_by']);

            return true;
        });
    }

    public function test_the_event_does_not_invent_an_approval_id(): void
    {
        // D-60. No approvals table exists, so no approval_id is fabricated —
        // not the audit row id, not a uuid. A fake identifier would satisfy a
        // consumer's de-duplication while keying on something the registry
        // never meant.
        $trip     = $this->pendingTrip();
        $approved = $this->trips->approve($trip, self::TENANT_A, $this->user());

        $key = (new TripApproved($approved))->idempotencyKey();

        $this->assertStringStartsWith($approved->id.'+', $key);
        $this->assertSame(
            $approved->id.'+'.$approved->approved_at->getTimestamp(),
            $key,
            'approved_at stands in for the absent approval_id — see D-60',
        );
    }

    /* ══════════ the refusals ══════════ */

    public function test_a_draft_trip_cannot_be_approved(): void
    {
        $trip = $this->pendingTrip();
        $trip->forceFill(['status' => TripStatus::DRAFT])->save();

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('Only a trip awaiting viability can be approved');

        $this->trips->approve($trip->fresh(), self::TENANT_A, $this->user());
    }

    public function test_an_already_approved_trip_cannot_be_approved_twice(): void
    {
        $trip     = $this->pendingTrip();
        $approved = $this->trips->approve($trip, self::TENANT_A, $this->user());

        $this->expectException(BusinessException::class);

        $this->trips->approve($approved, self::TENANT_A, $this->user());
    }

    public function test_a_trip_with_no_freight_cannot_be_approved(): void
    {
        // Not the margin gate — the same field check that lets a trip into
        // viability_pending at all. Approving a trip with no agreed price is
        // approving nothing.
        $trip = $this->pendingTrip(freight: null);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('nothing to approve');

        $this->trips->approve($trip, self::TENANT_A, $this->user());
    }

    public function test_a_refused_approval_writes_nothing(): void
    {
        $trip = $this->pendingTrip(freight: null);

        try {
            $this->trips->approve($trip, self::TENANT_A, $this->user());
        } catch (BusinessException) {
            // expected
        }

        $fresh = $trip->fresh();
        $this->assertSame(TripStatus::VIABILITY_PENDING, $fresh->status);
        $this->assertNull($fresh->approved_at);
        $this->assertNull($fresh->approved_by);
    }

    public function test_another_tenants_trip_cannot_be_approved(): void
    {
        $foreign = $this->pendingTrip(self::TENANT_B);

        $this->expectException(\App\Exceptions\ResourceNotFoundException::class);

        $this->trips->approve($foreign, self::TENANT_A, $this->user());
    }

    /* ══════════ PERM-003, including the denial ══════════ */

    public function test_the_permission_matrix_matches_perm_003_row_for_row(): void
    {
        $m = TransportPermission::MATRIX[TransportPermission::TRIP_APPROVE];

        foreach (['owner', 'operations', 'accounts', 'approver', 'admin'] as $granted) {
            $this->assertArrayHasKey($granted, $m, "PERM-003 grants {$granted}");
        }

        foreach (['dispatcher', 'driver', 'customer', 'supplier'] as $denied) {
            $this->assertArrayNotHasKey($denied, $m, "PERM-003 says N for {$denied}");
        }
    }

    public function test_a_dispatcher_cannot_approve_a_trip_over_http(): void
    {
        // THE DENIAL THAT MATTERS. A dispatcher may create, assign and dispatch
        // a trip. They may not approve one. Softening this would erase the
        // clearest evidence that approval is a commercial decision.
        $trip = $this->pendingTrip();
        Sanctum::actingAs($this->user(self::TENANT_A, 'staff', 'transport_dispatcher'));

        $this->patchJson('/api/transport/trips/'.$trip->id.'/approve')->assertForbidden();

        $this->assertSame(TripStatus::VIABILITY_PENDING, $trip->fresh()->status);
    }

    public function test_an_admin_can_approve_over_http(): void
    {
        $trip = $this->pendingTrip();
        Sanctum::actingAs($this->user(self::TENANT_A, 'admin'));

        $this->patchJson('/api/transport/trips/'.$trip->id.'/approve')
            ->assertOk()
            ->assertJsonPath('data.status', TripStatus::APPROVED)
            // The response says what was NOT checked — a user approving a trip
            // should know. D-59.
            ->assertJsonPath('message', 'Trip approved. The margin check is not yet enforced.');
    }

    public function test_a_client_identity_cannot_reach_the_approve_route(): void
    {
        $trip = $this->pendingTrip();
        Sanctum::actingAs($this->user(self::TENANT_A, 'client'));

        $this->patchJson('/api/transport/trips/'.$trip->id.'/approve')->assertForbidden();
    }

    /* ══════════ STT-003 — reject for correction ══════════ */

    public function test_a_trip_can_be_sent_back_for_correction(): void
    {
        $trip = $this->pendingTrip();

        $rejected = $this->trips->reject($trip, 'Freight is below the agreed rate card.', self::TENANT_A, $this->user());

        $this->assertSame(TripStatus::DRAFT, $rejected->status);
        $this->assertSame('Freight is below the agreed rate card.', $rejected->rejection_reason);
    }

    public function test_a_rejection_without_a_reason_is_refused(): void
    {
        // "Rejection reason" IS STT-003's precondition. A trip returning to
        // draft with no recorded objection cannot be corrected by whoever
        // receives it.
        $trip = $this->pendingTrip();

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('Give a reason');

        $this->trips->reject($trip, '   ', self::TENANT_A, $this->user());
    }

    public function test_a_refused_rejection_writes_nothing(): void
    {
        $trip = $this->pendingTrip();

        try {
            $this->trips->reject($trip, '', self::TENANT_A, $this->user());
        } catch (BusinessException) {
            // expected
        }

        $fresh = $trip->fresh();
        $this->assertSame(TripStatus::VIABILITY_PENDING, $fresh->status);
        $this->assertNull($fresh->rejection_reason);
    }

    public function test_only_a_trip_awaiting_viability_can_be_sent_back(): void
    {
        $trip     = $this->pendingTrip();
        $approved = $this->trips->approve($trip, self::TENANT_A, $this->user());

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('Only a trip awaiting viability can be sent back');

        $this->trips->reject($approved, 'Too late.', self::TENANT_A, $this->user());
    }

    public function test_the_rejection_is_audited_with_its_reason(): void
    {
        $trip = $this->pendingTrip();

        $this->trips->reject($trip, 'Vehicle type does not match the cargo.', self::TENANT_A, $this->user());

        $entry = TransportAuditLog::forTenant(self::TENANT_A)
            ->forSubject(TransportTrip::class, $trip->id)
            ->where('action', 'transport.trip.rejected')->sole();

        // The column is cleared on resubmit, so the audit is the only lasting
        // record of WHAT was said.
        $this->assertSame('Vehicle type does not match the cargo.', $entry->new_values['reason']);
    }

    public function test_a_rejected_trip_goes_round_the_loop_again(): void
    {
        // THE POINT OF STT-003. "Return to edit" means edit AND resubmit. A
        // trip that could be sent back but not sent forward again would be a
        // second dead end, one state earlier.
        $trip = $this->pendingTrip();

        $rejected = $this->trips->reject($trip, 'Freight too low.', self::TENANT_A, $this->user());
        $this->assertSame(TripStatus::DRAFT, $rejected->status);

        // Draft is editable again.
        $edited = $this->trips->update($rejected, ['approved_freight' => 61000], self::TENANT_A, $this->user());
        $this->assertSame('61000.00', (string) $edited->approved_freight);

        $resubmitted = $this->trips->submitForViability($edited, self::TENANT_A, $this->user());
        $this->assertSame(TripStatus::VIABILITY_PENDING, $resubmitted->status);

        // The objection has been answered, so it no longer hangs on the trip.
        $this->assertNull($resubmitted->rejection_reason, 'a resubmitted trip must not still show the old objection');

        $approved = $this->trips->approve($resubmitted, self::TENANT_A, $this->user());
        $this->assertSame(TripStatus::APPROVED, $approved->status);
        $this->assertNull($approved->rejection_reason);
    }

    public function test_it_can_go_round_more_than_once(): void
    {
        $trip = $this->pendingTrip();

        for ($i = 1; $i <= 3; $i++) {
            $trip = $this->trips->reject($trip, "Round {$i}: still not right.", self::TENANT_A, $this->user());
            $this->assertSame(TripStatus::DRAFT, $trip->status);
            $trip = $this->trips->submitForViability($trip, self::TENANT_A, $this->user());
        }

        $this->assertSame(TripStatus::APPROVED, $this->trips->approve($trip, self::TENANT_A, $this->user())->status);

        // Every round is in the history, even though only the last reason was
        // ever on the trip at one time.
        $this->assertSame(3, TransportAuditLog::forTenant(self::TENANT_A)
            ->forSubject(TransportTrip::class, $trip->id)
            ->where('action', 'transport.trip.rejected')->count());
    }

    public function test_rejecting_clears_any_earlier_approval_stamp(): void
    {
        // Belt and braces: a trip in draft has not been approved, and the two
        // records must never contradict each other.
        $trip = $this->pendingTrip();
        $trip->forceFill(['approved_at' => now(), 'approved_by' => $this->user()->id])->save();

        $rejected = $this->trips->reject($trip->fresh(), 'Sent back.', self::TENANT_A, $this->user());

        $this->assertNull($rejected->approved_at);
        $this->assertNull($rejected->approved_by);
    }

    public function test_a_dispatcher_cannot_reject_a_trip_over_http(): void
    {
        $trip = $this->pendingTrip();
        Sanctum::actingAs($this->user(self::TENANT_A, 'staff', 'transport_dispatcher'));

        $this->patchJson('/api/transport/trips/'.$trip->id.'/reject', ['reason' => 'No.'])->assertForbidden();

        $this->assertSame(TripStatus::VIABILITY_PENDING, $trip->fresh()->status);
    }

    public function test_the_reject_endpoint_requires_a_reason(): void
    {
        $trip = $this->pendingTrip();
        Sanctum::actingAs($this->user(self::TENANT_A, 'admin'));

        $this->patchJson('/api/transport/trips/'.$trip->id.'/reject', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    public function test_an_admin_can_reject_over_http(): void
    {
        $trip = $this->pendingTrip();
        Sanctum::actingAs($this->user(self::TENANT_A, 'admin'));

        $this->patchJson('/api/transport/trips/'.$trip->id.'/reject', ['reason' => 'Price needs renegotiating.'])
            ->assertOk()
            ->assertJsonPath('data.status', TripStatus::DRAFT)
            ->assertJsonPath('data.rejection_reason', 'Price needs renegotiating.');
    }

    /* ══════════ D-59 — the pinned absence ══════════ */

    /**
     * THIS TEST IS WRITTEN TO FAIL WHEN SNG-TRN-008 LANDS. THAT IS ITS JOB.
     *
     * STT-002's LOCKED precondition is "Margin policy passed" and it is not
     * enforced (D-59). When Trip Viability is built, this goes red, and whoever
     * built it must add the gate in TransportTripService::approve() and then
     * delete this test.
     *
     * The same technique pinned container_id out of EVT-002's payload. A
     * deferred precondition with a failing test cannot be forgotten; one with a
     * comment can.
     */
    public function test_the_margin_gate_is_still_deferred(): void
    {
        $source = file_get_contents(app_path('Services/Transport/TransportTripService.php'));
        $approve = substr($source, strpos($source, 'public function approve('));
        $approve = substr($approve, 0, strpos($approve, "\n    }") + 6);

        // CODE ONLY. The method's comments discuss the margin gate at length —
        // they are the record of why it is absent — so scanning the prose would
        // report the explanation as the thing it explains.
        $approve = preg_replace('#//.*$#m', '', $approve);

        // ONE mention of "margin" is expected and deliberate: the log key
        // 'margin_policy_checked' => false, which makes the deferral visible in
        // the logs as well as the code. It is removed before the check so it
        // cannot mask a real one appearing later.
        $marker = "'margin_policy_checked' => false,";
        $this->assertStringContainsString(
            $marker,
            $approve,
            'the deferral marker has been removed from approve() without the gate being added',
        );
        $approve = str_replace($marker, '', $approve);

        foreach (['margin', 'viability_decision', 'ViabilityService', 'ViabilityEngine'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase(
                $needle,
                $approve,
                "approve() now references '{$needle}'. If SNG-TRN-008 has landed, WIRE THE MARGIN "
                ."GATE into approve() and DELETE THIS TEST — see D-59. If it has not, something "
                .'has been half-added and the precondition is now ambiguous.',
            );
        }

        // And the trip carries no margin verdict to check against.
        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasColumn('transport_trips', 'margin_pct'),
            'a margin column exists — the gate is now buildable, so build it and delete this test',
        );
    }
}
