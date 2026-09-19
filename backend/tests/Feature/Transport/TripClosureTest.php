<?php

namespace Tests\Feature\Transport;

use App\Events\Transport\TripClosed;
use App\Exceptions\BusinessException;
use App\Exceptions\ResourceNotFoundException;
use App\Models\Tenant;
use App\Models\Transport\TransportAuditLog;
use App\Models\Transport\TransportOrder;
use App\Models\Transport\TransportTrip;
use App\Models\Transport\TripBill;
use App\Models\Transport\TripCollection;
use App\Models\User;
use App\Services\Transport\TripBillingService;
use App\Services\Transport\TripClosureService;
use App\Services\Transport\TripCollectionService;
use App\Services\Transport\TripDocumentService;
use App\Support\Transport\ClosureScope;
use App\Support\Transport\OrderStatus;
use App\Support\Transport\TransportDocumentType;
use App\Support\Transport\TripStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Trip closure — STT-012, API-009, CTR-013, PERM-005, EVT-012, BR-P0-017.
 *
 * ── THIS SUITE PROVES SOMETHING UNUSUAL: THAT THE FEATURE IS UNREACHABLE ──
 * `collection_pending` is the only state STT-012 leaves from, and nothing can
 * reach it — TripBill::markInvoiced() has no caller and no route (D-106, P3's
 * surface). So the first test below asserts the gap EXISTS, and the rest reach
 * the state the only way anything can: by calling the model method directly,
 * exactly as a route would if one existed.
 *
 * A test that quietly worked around the gap would hide it. This one names it.
 */
class TripClosureTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private TripClosureService $closure;
    private TripDocumentService $documents;
    private TripBillingService $billing;
    private TripCollectionService $collections;
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        foreach ([self::TENANT_A => 'Alpha', self::TENANT_B => 'Bravo'] as $id => $name) {
            (new Tenant())->forceFill([
                'id' => $id, 'name' => $name, 'slug' => Str::slug($name),
                'subdomain' => Str::slug($name), 'status' => 'active',
            ])->save();
        }

        $this->closure     = app(TripClosureService::class);
        $this->documents   = app(TripDocumentService::class);
        $this->billing     = app(TripBillingService::class);
        $this->collections = app(TripCollectionService::class);

        $this->actor = User::create([
            'tenant_id' => self::TENANT_A, 'name' => 'Ops', 'role' => 'staff',
            'internal_role' => 'transport_operations',
            'email' => 'o-'.Str::random(6).'@test.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function trip(string $status, int $tenantId = self::TENANT_A): TransportTrip
    {
        $order = TransportOrder::create([
            'tenant_id' => $tenantId, 'order_number' => 'TO-'.Str::random(8), 'customer_id' => 1,
            'pickup_location' => ['address' => 'JNPT'], 'delivery_location' => ['address' => 'Bhiwandi'],
            'required_at' => now()->addDays(3), 'service_type' => 'Container Haulage',
        ]);
        $order->forceFill(['order_status' => OrderStatus::APPROVED])->save();

        $trip = TransportTrip::create([
            'tenant_id' => $tenantId, 'order_id' => $order->id,
            'trip_number' => 'TRP-'.Str::random(8), 'customer_id' => 1,
            'approved_freight' => '48000.00',
        ]);
        $trip->forceFill(['status' => $status])->save();

        return $trip->fresh();
    }

    /**
     * A trip standing at `collection_pending` with everything settled.
     *
     * Walked through P3's real services at EVERY step, which became true on
     * 2026-09-19. It used to call markInvoiced() on the model directly, with a
     * note saying that was the only way to call it anywhere — D-106. There is a
     * service method and a route now, so the fixture uses them and the shortcut
     * is gone.
     */
    private function closableTrip(int $tenantId = self::TENANT_A, bool $settle = true): TransportTrip
    {
        $trip = $this->trip(TripStatus::DELIVERED, $tenantId);

        $doc = $this->documents->file(
            $trip,
            UploadedFile::fake()->create('pod.pdf', 40, 'application/pdf'),
            ['document_type' => TransportDocumentType::POD],
            $tenantId, $this->actor,
        );
        $this->documents->verify($doc, $tenantId, $this->actor);   // STT-008

        $trip = $trip->fresh();
        $bill = $this->billing->prepare($trip, $tenantId, $this->actor);   // STT-009

        // STT-010, through the service that POST /trips/{id}/bill/invoiced
        // calls. D-106 closed: this no longer reaches past the service layer.
        $this->billing->markInvoiced($trip->fresh(), 9001, $tenantId, $this->actor);

        $collection = $this->collections->open($trip->fresh(), $tenantId, now()->addDays(30)->toDateString(), $this->actor);   // STT-011

        if ($settle) {
            $this->collections->record($collection, '48000.00', $tenantId, $this->actor, 'NEFT-1');
        }

        return $trip->fresh();
    }

    /* ═══════════ D-106 — CLOSED 2026-09-19. The guard, reversed ════════ */

    /**
     * markInvoiced() has a caller, so closure is genuinely reachable.
     *
     * This test used to assert the OPPOSITE — that no caller existed — and it
     * did its job: it went red the moment P3 added
     * `POST /trips/{id}/bill/invoiced`, and told whoever saw it to flip
     * ClosureScope::REACHABLE. Both are now done, so the same scan points the
     * other way and fails if the route is ever taken away again.
     *
     * The original wording is kept below, because the interesting thing about
     * this test is not what it asserts today but that it caught a three-state
     * gap nobody was looking at: markInvoiced() shipped with no caller, so
     * `collection_pending` was unoccupiable, so closure could never run.
     */
    public function test_marking_a_trip_invoiced_has_a_caller_so_closure_is_reachable(): void
    {
        $callers = [];

        foreach (['app', 'routes'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir)));
            foreach ($it as $file) {
                if ($file->isDir() || $file->getExtension() !== 'php') {
                    continue;
                }
                $src = file_get_contents($file->getPathname());
                // Comments stripped: every current mention of markInvoiced()
                // outside its own definition is prose explaining that it has no
                // caller, and prose is not a caller.
                // Comments AND string literals stripped. Every current mention
                // of markInvoiced() outside its own definition is prose — a
                // docblock or, in ClosureScope, a sentence in a constant
                // explaining that the method has no caller. Prose about a gap
                // is not a caller, and a scan that cannot tell the difference
                // would have to be weakened the first time someone wrote the
                // name down. (Same lesson as the D-63 seeder guard.)
                $src = preg_replace('#//[^\n]*|/\*.*?\*/#s', '', $src);
                $src = preg_replace('#\'(?:\\\\.|[^\'\\\\])*\'|"(?:\\\\.|[^"\\\\])*"#s', "''", $src);

                $invoked = preg_match('/(->|::)markInvoiced\s*\(/', $src) === 1;

                if ($invoked && ! str_contains($file->getPathname(), 'TripBill.php')) {
                    $callers[] = str_replace(base_path().'/', '', $file->getPathname());
                }
            }
        }

        $this->assertNotSame([], $callers,
            "markInvoiced() has lost its caller, so `billable → billed` cannot fire.\n"
            ."That makes `collection_pending` unoccupiable and closure dead again — D-106 reopens.\n"
            .'Restore POST /trips/{id}/bill/invoiced, or flip ClosureScope::REACHABLE back to false.'
        );

        $this->assertTrue(ClosureScope::REACHABLE, 'the scope must not deny a reachability it has');
        $this->assertNull(ClosureScope::UNREACHABLE_BECAUSE, 'a reason is only honest while there is one');
    }

    /**
     * The whole chain, walked in order, with nothing called directly.
     *
     * The test D-106 needed and nobody had: every earlier suite reached its own
     * state by shortcut, so no single test proved a trip could get from a
     * verified POD to closed through routes alone. This one does, and it is the
     * test that would have caught the missing route on the 17th.
     */
    public function test_a_trip_walks_from_billable_to_closed_without_shortcuts(): void
    {
        $trip = $this->closableTrip();

        $this->assertSame(TripStatus::COLLECTION_PENDING, $trip->status,
            'the chain must reach collection_pending through the service, not around it');
        $this->assertTrue(TripStatus::canTransition((string) $trip->status, TripStatus::CLOSED));
    }

    /* ═══════════════════════ the edge ═════════════════════════════════ */

    public function test_the_closure_edge_is_wired_and_is_terminal(): void
    {
        $this->assertTrue(TripStatus::canTransition(TripStatus::COLLECTION_PENDING, TripStatus::CLOSED));
        $this->assertSame(ClosureScope::EDGE, TripStatus::COLLECTION_PENDING.'->'.TripStatus::CLOSED);

        // Terminal, and that terminality is what stands in for EVT-012's
        // missing close_version (D-107).
        $this->assertSame([], TripStatus::TRANSITIONS[TripStatus::CLOSED] ?? []);
        $this->assertContains(TripStatus::CLOSED, TripStatus::TERMINAL);
    }

    public function test_settlement_pending_is_not_on_the_way(): void
    {
        // Step 9 puts SETTLEMENT_PENDING between collection_pending and closed.
        // Nothing gates it and trip_settlements does not exist, so under the
        // standing rule it stays declared and unreachable.
        $this->assertContains(TripStatus::SETTLEMENT_PENDING, TripStatus::ALL);
        $this->assertFalse(TripStatus::canTransition(TripStatus::COLLECTION_PENDING, TripStatus::SETTLEMENT_PENDING));
        $this->assertFalse(TripStatus::canTransition(TripStatus::SETTLEMENT_PENDING, TripStatus::CLOSED));
    }

    /* ═══════════════════════ happy path ═══════════════════════════════ */

    public function test_a_fully_settled_trip_closes(): void
    {
        $trip = $this->closableTrip();
        $this->assertSame(TripStatus::COLLECTION_PENDING, $trip->status, 'the fixture must reach the door');

        $closed = $this->closure->close($trip, 'Settled in full by NEFT on 17 September.', self::TENANT_A, $this->actor);

        $this->assertSame(TripStatus::CLOSED, $closed->status);
        $this->assertNotNull($closed->closed_at);
        $this->assertSame($this->actor->id, $closed->closed_by);
        $this->assertStringContainsString('Settled in full', $closed->closure_reason);
    }

    public function test_it_emits_evt_012_with_the_locked_payload(): void
    {
        Event::fake([TripClosed::class]);
        $trip = $this->closableTrip();

        $this->closure->close($trip, 'Settled in full by NEFT.', self::TENANT_A, $this->actor);

        Event::assertDispatched(TripClosed::class, function (TripClosed $e) use ($trip) {
            $payload = $e->payload();

            // EVT-012's Payload Core, and NOTHING beyond it.
            $this->assertSame(['trip_id', 'closure_timestamp'], array_keys($payload));
            $this->assertSame($trip->id, $payload['trip_id']);
            $this->assertNotNull($payload['closure_timestamp']);

            // D-107: no close_version exists, and none is fabricated.
            $this->assertStringStartsWith($trip->id.'+', $e->idempotencyKey());

            return true;
        });
    }

    public function test_the_event_tells_a_consumer_which_controls_never_ran(): void
    {
        // A consumer that treats closure as proof of a clean trip must be able
        // to see that two of the five controls could not be evaluated at all.
        Event::fake([TripClosed::class]);
        $this->closure->close($this->closableTrip(), 'Settled in full.', self::TENANT_A, $this->actor);

        Event::assertDispatched(TripClosed::class, function (TripClosed $e) {
            $this->assertSame('not_checked', $e->controls()['settlement'] ?? null);
            // `exceptions` was 'not_checked' too until 2026-09-19 — the register
            // was built on the 18th and this control kept saying otherwise.
            $this->assertSame('passed', $e->controls()['exceptions'] ?? null);
            $this->assertSame('passed', $e->controls()['pod'] ?? null);

            return true;
        });
    }

    /* ═══════════ the controls — the point of the whole feature ════════ */

    public function test_a_control_that_cannot_run_reports_that_it_did_not_run(): void
    {
        // TRP-P0-014: "no SILENT closure with unresolved critical exceptions".
        // trip_exceptions has a schema and no model, and trip_settlements does
        // not exist. Letting either default to `passed` is the single most
        // misleading thing this service could do.
        $readiness = $this->closure->readiness($this->closableTrip(), self::TENANT_A);

        $byKey = array_column($readiness['controls'], null, 'key');

        // ONE control cannot run now, not two. trip_settlements still does not
        // exist; the exception register was built on 2026-09-18 and this test
        // asserted a staleness rather than a rule until it was corrected.
        $this->assertSame('not_checked', $byKey['settlement']['state']);
        $this->assertNotSame('passed', $byKey['settlement']['state']);
        $this->assertStringContainsString('not built yet', $byKey['settlement']['message']);

        $this->assertNotSame('not_checked', $byKey['exceptions']['state'],
            'the exception register exists — this control must actually run');
    }

    public function test_an_unrunnable_control_does_not_block_closure(): void
    {
        // It must be VISIBLE, not blocking. Blocking on a check nobody can
        // perform would make closure impossible for a reason no user could fix.
        $readiness = $this->closure->readiness($this->closableTrip(), self::TENANT_A);

        $this->assertCount(1, $readiness['not_checked'], 'only supplier settlement cannot be checked now');
        $this->assertSame([], $readiness['blockers']);
        $this->assertTrue($readiness['closable']);
    }

    public function test_every_control_returns_a_sentence_not_a_boolean(): void
    {
        // FRS TRP-P0-014: "User sees exactly why a trip is blocked."
        // UX §35 forbids a screen that says only Blocked.
        foreach ($this->closure->readiness($this->trip(TripStatus::COLLECTION_PENDING), self::TENANT_A)['controls'] as $c) {
            $this->assertArrayHasKey('message', $c);
            $this->assertNotSame('', trim($c['message']), $c['key'].' must explain itself');
            $this->assertStringEndsWith('.', trim($c['message']), $c['key'].' reads as a sentence');
        }
    }

    /* ─────────────────────────── refusals ───────────────────────────── */

    public function test_a_trip_with_no_verified_pod_is_refused_and_told_why(): void
    {
        $trip = $this->trip(TripStatus::COLLECTION_PENDING);

        try {
            $this->closure->close($trip, 'Closing this one out.', self::TENANT_A, $this->actor);
            $this->fail('a trip with no POD must not close');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('no verified POD', $e->getMessage());
        }

        $this->assertSame(TripStatus::COLLECTION_PENDING, $trip->fresh()->status);
        $this->assertNull($trip->fresh()->closed_at, 'a refusal must not half-close the trip');
    }

    public function test_an_unpaid_trip_is_refused_with_the_amount_outstanding(): void
    {
        $trip = $this->closableTrip(settle: false);

        try {
            $this->closure->close($trip, 'Closing this one out.', self::TENANT_A, $this->actor);
            $this->fail('an unpaid trip must not close');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('still owes', $e->getMessage());
            $this->assertStringContainsString('48000', $e->getMessage());
        }
    }

    public function test_the_refusal_says_the_waiver_is_not_built_not_that_none_exists(): void
    {
        // BR-P0-017 NAMES a waiver and names the Owner role that may use it.
        // Unlike every other override this module refuses, that one is
        // specified — so telling a user "this cannot be waived" would mislead
        // them about their own rule book.
        $trip = $this->trip(TripStatus::COLLECTION_PENDING);

        try {
            $this->closure->close($trip, 'Closing this one out.', self::TENANT_A, $this->actor);
            $this->fail('expected a refusal');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('BR-P0-017', $e->getMessage());
            $this->assertStringContainsString('not built yet', $e->getMessage());
            $this->assertStringNotContainsString('cannot be waived', $e->getMessage());
        }
    }

    public function test_a_closure_reason_is_mandatory(): void
    {
        // CTR-013 | closure_reason | REQUIRED | non-empty. Checked in the
        // service as well as the FormRequest: a terminal state with no recorded
        // reason is a trip nobody can ever account for.
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('A closure reason is required');
        $this->closure->close($this->closableTrip(), '   ', self::TENANT_A, $this->actor);
    }

    public function test_a_trip_cannot_be_closed_twice(): void
    {
        $closed = $this->closure->close($this->closableTrip(), 'Settled in full.', self::TENANT_A, $this->actor);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('was already closed');
        $this->closure->close($closed, 'Settled in full, again.', self::TENANT_A, $this->actor);
    }

    public function test_a_trip_that_is_not_at_the_door_is_refused(): void
    {
        // CTR-013 says "Closure controls run first", and it means it: a trip
        // that is BOTH in the wrong state AND failing its controls hears about
        // the controls, because those are the things the user can act on. So
        // this fixture passes every control and is only in the wrong state.
        $trip = $this->closableTrip();
        $trip->forceFill(['status' => TripStatus::DELIVERED])->save();

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('Only a trip awaiting collection can be closed');
        $this->closure->close($trip->fresh(), 'Closing this one out.', self::TENANT_A, $this->actor);
    }

    public function test_the_controls_are_reported_before_the_state_is(): void
    {
        // The other half of CTR-013's ordering, stated as its own test so the
        // behaviour is deliberate rather than incidental: a trip in the wrong
        // state with failing controls is told what it can fix.
        try {
            $this->closure->close($this->trip(TripStatus::DELIVERED), 'Closing this one out.', self::TENANT_A, $this->actor);
            $this->fail('expected a refusal');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('no verified POD', $e->getMessage());
            $this->assertStringNotContainsString('Only a trip awaiting collection', $e->getMessage());
        }
    }

    public function test_another_tenants_trip_is_a_404_not_a_403(): void
    {
        $theirs = $this->trip(TripStatus::COLLECTION_PENDING, self::TENANT_B);

        $this->expectException(ResourceNotFoundException::class);
        $this->closure->close($theirs, 'Closing this one out.', self::TENANT_A, $this->actor);
    }

    public function test_readiness_on_another_tenants_trip_is_a_404_too(): void
    {
        // The read leaks as much as the write would: a 403 confirms the row.
        $this->expectException(ResourceNotFoundException::class);
        $this->closure->readiness($this->trip(TripStatus::COLLECTION_PENDING, self::TENANT_B), self::TENANT_A);
    }

    /* ───────────────────────── the audit row ────────────────────────── */

    public function test_the_audit_records_which_controls_actually_ran(): void
    {
        // The most important line in the audit trail: nobody reading a closed
        // trip in six months may mistake it for one that passed all five checks.
        $closed = $this->closure->close($this->closableTrip(), 'Settled in full.', self::TENANT_A, $this->actor);

        $row = TransportAuditLog::forTenant(self::TENANT_A)
            ->where('auditable_id', $closed->id)
            ->where('action', 'transport.trip.status_changed')
            ->latest('id')->first();

        $ctx = $row->context ?? [];

        $this->assertSame('not_checked', $ctx['controls']['settlement'] ?? null);
        $this->assertSame('passed', $ctx['controls']['exceptions'] ?? null);
        $this->assertSame(['settlement'], $ctx['not_checked'] ?? []);
        $this->assertSame('not_built', $ctx['profit_snapshot'] ?? null);
        $this->assertStringContainsString('BR-P0-017', $ctx['waiver'] ?? '');
    }

    /* ────────────────────── the scope declarations ──────────────────── */

    public function test_the_scope_quotes_perm_005_including_the_dispatcher_denial(): void
    {
        $matrix = \App\Support\Transport\TransportPermission::MATRIX[ClosureScope::PERMISSION];

        // PERM-005 verbatim — Owner Y, Operations Y, Dispatcher N, Accounts Y,
        // Approver Y, Driver N, Customer N, Supplier N, Admin Y.
        $this->assertEqualsCanonicalizing(
            ['owner', 'operations', 'accounts', 'approver', 'admin'],
            array_keys($matrix),
        );
        $this->assertArrayNotHasKey('dispatcher', $matrix, 'PERM-005 denies the Dispatcher, and that is the point');
        $this->assertArrayNotHasKey('driver', $matrix);
    }

    /* ══════════ TRP-P0-014, enforced since 2026-09-19 ══════════ */

    public function test_a_critical_exception_still_open_blocks_the_close(): void
    {
        // "No silent closure with unresolved critical exceptions." Until the
        // exception register was built this control could only report that it
        // had not run. It runs now, and the rule is a rule.
        $trip = $this->closableTrip();

        app(\App\Services\Transport\TripExceptionService::class)->raise([
            'category' => \App\Support\Transport\ExceptionCategory::OPERATIONAL,
            'severity' => \App\Support\Transport\ExceptionSeverity::CRITICAL,
            'cause'    => 'Seal was broken on arrival and nobody has accounted for it.',
            'trip_id'  => $trip->id,
        ], self::TENANT_A, $this->actor);

        try {
            $this->closure->close($trip->fresh(), 'Settled in full.', self::TENANT_A, $this->actor);
            $this->fail('a trip with an open critical exception must not close');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('critical exception', $e->getMessage());
            $this->assertStringContainsString('TRP-P0-014', $e->getMessage());
        }
    }

    public function test_a_non_critical_exception_does_not_block_the_close(): void
    {
        // The rule's own word is CRITICAL. Widening a Hard rule past its own
        // wording would be inventing one, and a low-severity note left open is
        // not what "silent closure" means.
        $trip = $this->closableTrip();

        app(\App\Services\Transport\TripExceptionService::class)->raise([
            'category' => \App\Support\Transport\ExceptionCategory::DOCUMENTATION,
            'severity' => \App\Support\Transport\ExceptionSeverity::LOW,
            'cause'    => 'A copy of the weighbridge slip is still to be filed.',
            'trip_id'  => $trip->id,
        ], self::TENANT_A, $this->actor);

        $closed = $this->closure->close($trip->fresh(), 'Settled in full by NEFT.', self::TENANT_A, $this->actor);

        $this->assertSame(TripStatus::CLOSED, $closed->status);

        $control = array_column(
            $this->closure->readiness($closed, self::TENANT_A)['controls'], null, 'key'
        )['exceptions'];

        // Passed, but it SAYS the exception is there. Passing silently would be
        // the thing TRP-P0-014 is against.
        $this->assertSame('passed', $control['state']);
        $this->assertStringContainsString('none of them critical', $control['message']);
    }

    public function test_a_resolved_exception_does_not_block_anything(): void
    {
        $trip = $this->closableTrip();
        $svc  = app(\App\Services\Transport\TripExceptionService::class);

        $e = $svc->raise([
            'category' => \App\Support\Transport\ExceptionCategory::OPERATIONAL,
            'severity' => \App\Support\Transport\ExceptionSeverity::CRITICAL,
            'cause'    => 'Held at the gate for two hours with no paperwork.',
            'trip_id'  => $trip->id,
        ], self::TENANT_A, $this->actor);

        $svc->resolve(
            $svc->acknowledge($e, $this->actor->id, self::TENANT_A, $this->actor),
            'Gate pass reissued and the vehicle released.',
            self::TENANT_A, $this->actor,
        );

        $closed = $this->closure->close($trip->fresh(), 'Settled in full by NEFT.', self::TENANT_A, $this->actor);

        $this->assertSame(TripStatus::CLOSED, $closed->status);
    }

    public function test_the_permission_key_is_quoted_from_api_009(): void
    {
        $this->assertSame('transport.trip.close', ClosureScope::PERMISSION);
        $this->assertSame('POST /api/v1/transport/trips/{trip}/close', ClosureScope::PATH);
    }
}
