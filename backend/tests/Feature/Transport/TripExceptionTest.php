<?php

namespace Tests\Feature\Transport;

use App\Events\Transport\TripExceptionRaised;
use App\Exceptions\BusinessException;
use App\Exceptions\ResourceNotFoundException;
use App\Models\Tenant;
use App\Models\Transport\TransportAuditLog;
use App\Models\Transport\TransportOrder;
use App\Models\Transport\TransportTrip;
use App\Models\Transport\TripException;
use App\Models\User;
use App\Services\Transport\TripExceptionService;
use App\Support\Transport\ExceptionCategory;
use App\Support\Transport\ExceptionScope;
use App\Support\Transport\ExceptionSeverity;
use App\Support\Transport\ExceptionSlaState;
use App\Support\Transport\ExceptionStatus;
use App\Support\Transport\OrderStatus;
use App\Support\Transport\TripStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The exception register — SNG-TRN-013, unblocked 2026-09-18.
 *
 * Two edges and no others: STT-015 open → acknowledged, STT-016 acknowledged →
 * resolved. Step 9's seven statuses are the vocabulary; everything between the
 * two edges stays declared and unreachable.
 *
 * The refusals are tested harder than the happy path, because the happy path is
 * three columns and a status. What this work is actually for is what it will
 * NOT let you do: resolve without evidence, resolve without acknowledging,
 * delete an exception, or believe that a waiver does not exist.
 */
class TripExceptionTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private TripExceptionService $svc;
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

        $this->svc = app(TripExceptionService::class);
        $this->actor = User::create([
            'tenant_id' => self::TENANT_A, 'name' => 'Ops', 'role' => 'staff',
            'internal_role' => 'transport_operations',
            'email' => 'o-'.Str::random(6).'@test.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function trip(int $tenantId = self::TENANT_A): TransportTrip
    {
        $order = TransportOrder::create([
            'tenant_id' => $tenantId, 'order_number' => 'TO-'.Str::random(8), 'customer_id' => 1,
            'pickup_location' => ['address' => 'A'], 'delivery_location' => ['address' => 'B'],
            'required_at' => now()->addDays(3), 'service_type' => 'Container Haulage',
        ]);
        $order->forceFill(['order_status' => OrderStatus::APPROVED])->save();

        $trip = TransportTrip::create([
            'tenant_id' => $tenantId, 'order_id' => $order->id,
            'trip_number' => 'TRP-'.Str::random(8), 'customer_id' => 1,
        ]);
        $trip->forceFill(['status' => TripStatus::APPROVED])->save();

        return $trip->fresh();
    }

    private function raise(array $over = [], int $tenantId = self::TENANT_A): TripException
    {
        return $this->svc->raise([
            'category' => ExceptionCategory::OPERATIONAL,
            'severity' => ExceptionSeverity::HIGH,
            'cause'    => 'Vehicle held at the gate for two hours with no paperwork.',
            'trip_id'  => $this->trip($tenantId)->id,
            ...$over,
        ], $tenantId, $this->actor);
    }

    /* ══════════════ the machine ══════════════ */

    public function test_the_vocabulary_is_step_9s_and_only_step_9s(): void
    {
        // D-29's ruling: vocabulary from Step 9, edges from Step 11. `waived`
        // is the one status Step 9 does not contain, so it is out of ALL —
        // that is D-30, and it is why this assertion is exact rather than a
        // subset check.
        $this->assertSame(ExceptionStatus::STEP_9, ExceptionStatus::ALL);
        $this->assertNotContains(ExceptionStatus::WAIVED, ExceptionStatus::ALL);
    }

    public function test_only_step_11s_two_locked_edges_are_wired(): void
    {
        $this->assertTrue(ExceptionStatus::canTransition(ExceptionStatus::OPEN, ExceptionStatus::ACKNOWLEDGED));
        $this->assertTrue(ExceptionStatus::canTransition(ExceptionStatus::ACKNOWLEDGED, ExceptionStatus::RESOLVED));

        // No shortcut, and no route into the four declared-but-unreachable ones.
        $this->assertFalse(ExceptionStatus::canTransition(ExceptionStatus::OPEN, ExceptionStatus::RESOLVED));
        foreach ([ExceptionStatus::IN_PROGRESS, ExceptionStatus::MITIGATION_PLANNED,
                  ExceptionStatus::VERIFIED, ExceptionStatus::CLOSED] as $unreachable) {
            $intoIt = array_filter(ExceptionStatus::TRANSITIONS, fn (array $to) => in_array($unreachable, $to, true));
            $this->assertSame([], $intoIt, "nothing may transition INTO $unreachable");
        }
    }

    public function test_resolved_is_terminal_by_construction(): void
    {
        // The D-29 knot: SM-EXC calls `resolved` terminal, ENUM-004 puts
        // `closed` after it. `closed` is in the vocabulary and no edge leaves
        // `resolved`, so both halves of Step 11 are honoured at once.
        $this->assertContains(ExceptionStatus::CLOSED, ExceptionStatus::ALL);
        $this->assertSame([], ExceptionStatus::TRANSITIONS[ExceptionStatus::RESOLVED] ?? []);
    }

    /* ══════════════ raising ══════════════ */

    public function test_raising_numbers_the_exception_and_opens_it(): void
    {
        $e = $this->raise();

        $this->assertMatchesRegularExpression('/^EXC-\d{4}-\d{6}$/', $e->exception_number);
        $this->assertSame(ExceptionStatus::OPEN, $e->status);
        $this->assertSame(ExceptionScope::SOURCE_MANUAL, $e->source);
        $this->assertSame($this->actor->id, $e->raised_by);
    }

    public function test_the_sla_clock_is_set_from_severity(): void
    {
        $critical = $this->raise(['severity' => ExceptionSeverity::CRITICAL]);
        $low      = $this->raise(['severity' => ExceptionSeverity::LOW]);

        $this->assertNotNull($critical->due_at);
        $this->assertLessThan($low->sla_minutes, $critical->sla_minutes,
            'a critical exception must be due sooner than a low one');
        $this->assertSame(ExceptionSlaState::ON_TRACK, $critical->slaState());
    }

    public function test_an_overdue_exception_says_so(): void
    {
        $e = $this->raise(['severity' => ExceptionSeverity::CRITICAL]);

        $this->assertSame(ExceptionSlaState::OVERDUE, $e->slaState(now()->addDay()->toDateTimeString()));
        $this->assertLessThan(0, $e->minutesRemaining(now()->addDay()->toDateTimeString()));
    }

    public function test_it_emits_evt_008_with_the_locked_payload(): void
    {
        Event::fake([TripExceptionRaised::class]);
        $e = $this->raise();

        Event::assertDispatched(TripExceptionRaised::class, function (TripExceptionRaised $ev) {
            // EVT-008's Payload Core, and NOTHING beyond it.
            $this->assertSame(['exception_id', 'trip_id', 'severity'], array_keys($ev->payload()));

            return true;
        });
    }

    /* ── raising refusals ── */

    public function test_an_exception_with_no_cause_is_refused(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('Say what went wrong');
        $this->raise(['cause' => '   ']);
    }

    public function test_a_category_outside_ops_88s_eight_is_refused(): void
    {
        // D-37: Step 11's Enums sheet has no category enum, so OPS §88's list is
        // the only one any document gives — and it is closed, not a suggestion.
        try {
            $this->raise(['category' => 'weather']);
            $this->fail('an invented category must be refused');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('eight exception categories', $e->getMessage());
            $this->assertStringContainsString('OPS §88', $e->getMessage());
        }
    }

    public function test_a_severity_outside_ctr_011s_four_is_refused(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('CTR-011');
        $this->raise(['severity' => 'catastrophic']);
    }

    public function test_raising_against_another_tenants_trip_is_a_404(): void
    {
        $theirs = $this->trip(self::TENANT_B);

        $this->expectException(ResourceNotFoundException::class);
        $this->svc->raise([
            'category' => ExceptionCategory::OPERATIONAL,
            'severity' => ExceptionSeverity::LOW,
            'cause'    => 'Trying to reach across a tenant boundary.',
            'trip_id'  => $theirs->id,
        ], self::TENANT_A, $this->actor);
    }

    /* ══════════════ STT-015 ══════════════ */

    public function test_acknowledging_assigns_an_owner_and_starts_the_clock(): void
    {
        $e = $this->svc->acknowledge($this->raise(), $this->actor->id, self::TENANT_A, $this->actor);

        $this->assertSame(ExceptionStatus::ACKNOWLEDGED, $e->status);
        $this->assertSame($this->actor->id, $e->owner_id);
        $this->assertNotNull($e->acknowledged_at);
    }

    public function test_acknowledging_twice_is_refused(): void
    {
        $e = $this->svc->acknowledge($this->raise(), $this->actor->id, self::TENANT_A, $this->actor);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('already been acknowledged');
        $this->svc->acknowledge($e, $this->actor->id, self::TENANT_A, $this->actor);
    }

    /* ══════════════ STT-016 — where BR-P0-011 bites ══════════════ */

    public function test_resolving_records_the_evidence_and_stops_the_clock(): void
    {
        $e = $this->svc->acknowledge($this->raise(), $this->actor->id, self::TENANT_A, $this->actor);
        $r = $this->svc->resolve($e, 'Paperwork found and the vehicle released at 14:10.', self::TENANT_A, $this->actor);

        $this->assertSame(ExceptionStatus::RESOLVED, $r->status);
        $this->assertStringContainsString('Paperwork found', $r->resolution_note);
        $this->assertNotNull($r->resolved_at);
        $this->assertSame(ExceptionSlaState::STOPPED, $r->slaState());
    }

    public function test_resolving_without_evidence_is_refused_and_names_the_rule(): void
    {
        $e = $this->svc->acknowledge($this->raise(), $this->actor->id, self::TENANT_A, $this->actor);

        try {
            $this->svc->resolve($e, '  ', self::TENANT_A, $this->actor);
            $this->fail('BR-P0-011 must refuse a resolution with no evidence');
        } catch (BusinessException $ex) {
            $this->assertStringContainsString('BR-P0-011', $ex->getMessage());
        }

        $this->assertSame(ExceptionStatus::ACKNOWLEDGED, $e->fresh()->status);
    }

    public function test_the_refusal_says_the_waiver_is_not_built_not_that_none_exists(): void
    {
        // D-30. BR-P0-011 NAMES a waiver and names the Owner role. Telling a
        // user "this cannot be waived" would mislead them about their own rule
        // book — the same discipline BR-P0-017 carries on the closure side.
        $e = $this->svc->acknowledge($this->raise(), $this->actor->id, self::TENANT_A, $this->actor);

        try {
            $this->svc->resolve($e, '', self::TENANT_A, $this->actor);
            $this->fail('expected a refusal');
        } catch (BusinessException $ex) {
            $this->assertStringContainsString('not built yet', $ex->getMessage());
            $this->assertStringNotContainsString('cannot be waived', $ex->getMessage());
        }
    }

    public function test_an_open_exception_cannot_skip_straight_to_resolved(): void
    {
        try {
            $this->svc->resolve($this->raise(), 'Fixed it somehow, honestly.', self::TENANT_A, $this->actor);
            $this->fail('open → resolved is not a LOCKED edge');
        } catch (BusinessException $ex) {
            // The likeliest real mistake, so the message names the fix.
            $this->assertStringContainsString('Acknowledge this exception first', $ex->getMessage());
        }
    }

    public function test_another_tenants_exception_is_a_404_not_a_403(): void
    {
        $theirs = $this->raise(tenantId: self::TENANT_B);

        $this->expectException(ResourceNotFoundException::class);
        $this->svc->acknowledge($theirs, null, self::TENANT_A, $this->actor);
    }

    /* ══════════════ the register must never lose a row ══════════════ */

    public function test_an_exception_cannot_be_deleted(): void
    {
        // STOS-DB §20 and OPS §154: an exception "must never disappear". The
        // interesting question is always the one somebody wanted gone.
        $e = $this->raise();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot be deleted');
        $e->delete();
    }

    public function test_the_number_is_immutable(): void
    {
        $e = $this->raise();

        $this->expectException(\RuntimeException::class);
        $e->forceFill(['exception_number' => 'EXC-2020-000001'])->save();
    }

    /* ══════════════ the audit trail ══════════════ */

    public function test_the_audit_records_what_could_not_be_computed(): void
    {
        // OPS §87 requires a financial and a customer impact on every
        // exception, and D-32 records that no formula exists anywhere. Said on
        // the row rather than defaulted to zero, which would be a number
        // somebody might believe.
        $e = $this->raise();

        $row = TransportAuditLog::forTenant(self::TENANT_A)
            ->where('auditable_id', $e->id)
            ->where('action', 'transport.exception.raised')
            ->latest('id')->first();

        $this->assertNotNull($row);
        $this->assertEqualsCanonicalizing(['financial', 'customer'], $row->context['impact_not_computed'] ?? []);
        $this->assertStringContainsString('wall clock', $row->context['sla'] ?? '');
    }

    public function test_the_resolution_audit_records_the_deferred_evidence_forms(): void
    {
        $e = $this->svc->acknowledge($this->raise(), $this->actor->id, self::TENANT_A, $this->actor);
        $r = $this->svc->resolve($e, 'Released after the gate pass was reissued.', self::TENANT_A, $this->actor);

        $row = TransportAuditLog::forTenant(self::TENANT_A)
            ->where('auditable_id', $r->id)
            ->where('action', 'transport.exception.status_changed')
            ->latest('id')->first();

        $this->assertSame('note', $row->context['evidence'] ?? null);
        $this->assertStringContainsString('photo/document', $row->context['evidence_deferred'] ?? '');
    }

    /* ══════════════ the summary a screen needs ══════════════ */

    public function test_the_trip_summary_counts_what_changes_a_decision(): void
    {
        $trip = $this->trip();
        foreach ([ExceptionSeverity::CRITICAL, ExceptionSeverity::LOW] as $sev) {
            $this->svc->raise([
                'category' => ExceptionCategory::OPERATIONAL, 'severity' => $sev,
                'cause' => 'Something worth recording happened here.', 'trip_id' => $trip->id,
            ], self::TENANT_A, $this->actor);
        }

        $s = $this->svc->summaryForTrip($trip->id, self::TENANT_A);

        $this->assertSame(2, $s['total']);
        $this->assertSame(2, $s['open']);
        $this->assertSame(1, $s['critical_open']);
    }
}
