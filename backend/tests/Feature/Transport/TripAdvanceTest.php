<?php

namespace Tests\Feature\Transport;

use App\Events\Transport\AdvanceRequested;
use App\Exceptions\BusinessException;
use App\Models\Tenant;
use App\Models\Transport\TransportOrder;
use App\Models\Transport\TransportTrip;
use App\Models\Transport\TripAdvance;
use App\Models\User;
use App\Services\Transport\TransportPolicyService;
use App\Services\Transport\TripAdvanceService;
use App\Support\Transport\AdvanceStatus;
use App\Support\Transport\TripStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SNG-TRN-011 — advances are a control, not a form.
 *
 * The ticket's acceptance criterion is "Policy blocks/approves correctly;
 * exceptions escalate" and QA-004 is "Advance exceeds policy → approval or
 * escalation required; no unauthorised payment". Both are about refusals, so
 * most of what follows asserts that something does NOT happen.
 *
 * BR-P0-005 has two halves that are easy to half-implement, and each has a test
 * that fails if only the easy half is written:
 *
 *   the rule is about the SUM, not one request — four requests of 25% each
 *   must not fund a trip to 100%
 *
 *   committed includes approved-but-unpaid — counting only `paid` funds the
 *   same driver twice in the window between approval and disbursement
 */
class TripAdvanceTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private TripAdvanceService $advances;
    private TransportPolicyService $policies;
    private User $requester;
    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::TENANT_A => 'Alpha', self::TENANT_B => 'Bravo'] as $id => $name) {
            (new Tenant())->forceFill([
                'id' => $id, 'name' => $name, 'slug' => Str::slug($name),
                'subdomain' => Str::slug($name), 'status' => 'active',
            ])->save();
        }

        $this->advances = app(TripAdvanceService::class);
        $this->policies = app(TransportPolicyService::class);

        $this->requester = $this->user('ops');
        $this->approver  = $this->user('accounts');
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

    private function trip(string $freight = '100000.00', int $tenantId = self::TENANT_A): TransportTrip
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
        $trip->forceFill(['status' => TripStatus::APPROVED, 'approved_freight' => $freight])->save();

        return $trip->fresh();
    }

    private function request(TransportTrip $trip, string $amount, array $extra = []): TripAdvance
    {
        return $this->advances->request(
            $trip,
            array_merge(['amount_requested' => $amount, 'driver_id' => 4], $extra),
            self::TENANT_A,
            $this->requester
        );
    }

    /* ══════════ BR-P0-005 — the exposure rule ══════════ */

    /** 30% of 100,000 is 30,000, and 30,000 is allowed. */
    public function test_an_advance_inside_the_policy_is_accepted(): void
    {
        $advance = $this->request($this->trip(), '30000.00');

        $this->assertSame(AdvanceStatus::REQUESTED, $advance->status);
        $this->assertSame('30000.00', (string) $advance->amount_requested);
    }

    /** 30,000.01 is not. The refusal names the numbers, per BRWM §70. */
    public function test_an_advance_over_the_policy_is_refused_with_the_figures(): void
    {
        try {
            $this->request($this->trip(), '30000.01');
            $this->fail('an over-limit advance was accepted');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('₹30,000.00', $e->getMessage(), 'the limit should be named');
            $this->assertStringContainsString('exceed', $e->getMessage());
            $this->assertStringContainsString('override', $e->getMessage(), 'the message should say what would resolve it');
        }

        $this->assertSame(0, TripAdvance::count(), 'a refused request must not be stored');
    }

    /**
     * The rule is about the sum.
     *
     * Four requests of 25% each pass individually and must not pass together.
     * A check that looked only at the incoming amount would allow all four.
     */
    public function test_advances_are_counted_together_not_one_at_a_time(): void
    {
        $trip = $this->trip();

        $first = $this->request($trip, '20000.00');
        $this->advances->approve($first, [], self::TENANT_A, $this->approver);

        $this->expectException(BusinessException::class);
        $this->request($trip, '15000.00');   // 20,000 + 15,000 > 30,000
    }

    /**
     * Approved but not yet paid is still exposure.
     *
     * This is the window a naive implementation leaves open: counting only
     * `paid` lets a second advance be approved while the first is awaiting
     * disbursement, funding the same driver twice for one journey.
     */
    public function test_an_approved_but_unpaid_advance_still_counts(): void
    {
        $trip    = $this->trip();
        $granted = $this->advances->approve($this->request($trip, '25000.00'), [], self::TENANT_A, $this->approver);

        $this->assertSame(AdvanceStatus::APPROVED, $granted->status);
        $this->assertNotSame(AdvanceStatus::PAID, $granted->status, 'nothing has been disbursed');
        $this->assertSame('25000.00', $this->advances->exposureFor($trip->id, self::TENANT_A));

        $this->expectException(BusinessException::class);
        $this->request($trip, '10000.00');
    }

    /** A rejected request frees the money it was holding. */
    public function test_a_rejected_advance_stops_counting(): void
    {
        $trip = $this->trip();
        $this->advances->reject($this->request($trip, '30000.00'), 'Not needed', self::TENANT_A, $this->approver);

        $this->assertSame('0.00', $this->advances->exposureFor($trip->id, self::TENANT_A));
        $this->assertSame(AdvanceStatus::REQUESTED, $this->request($trip, '30000.00')->status);
    }

    /** The lower of the two caps wins — an absolute cap is not escaped by freight. */
    public function test_the_absolute_cap_beats_a_larger_percentage(): void
    {
        $this->policies->set(self::TENANT_A, 'advance.max_amount', '5000', $this->approver);

        $trip = $this->trip();   // 30% of 100,000 = 30,000, but the cap is 5,000

        $this->assertSame('5000.00', $this->advances->limitFor($trip, self::TENANT_A));
        $this->expectException(BusinessException::class);
        $this->request($trip, '6000.00');
    }

    /* ══════════ The override, and its limits ══════════ */

    /** BR-P0-005 permits an authorised override, and records that one was used. */
    public function test_an_authorised_override_lifts_the_limit_and_is_recorded(): void
    {
        $advance = $this->request($this->trip(), '50000.00', ['override_limit' => true]);

        $this->assertTrue($advance->limit_overridden);
        $this->assertSame('50000.00', (string) $advance->amount_requested);
    }

    /** A workspace may switch overrides off entirely — CMP §20. */
    public function test_an_override_is_refused_where_policy_forbids_it(): void
    {
        $this->policies->set(self::TENANT_A, 'advance.override_allowed', false, $this->approver);

        $this->expectException(BusinessException::class);
        $this->request($this->trip(), '50000.00', ['override_limit' => true]);
    }

    /** An override with nobody's name on it is not an override. */
    public function test_an_unattributed_override_is_refused(): void
    {
        $this->expectException(BusinessException::class);
        $this->advances->request(
            $this->trip(),
            ['amount_requested' => '50000.00', 'driver_id' => 4, 'override_limit' => true],
            self::TENANT_A,
            null
        );
    }

    /* ══════════ Segregation of duties — BRW §75, STOS-FIN §129 ══════════ */

    /** Nobody decides their own request, at any seniority. */
    public function test_the_requester_cannot_approve_their_own_advance(): void
    {
        $advance = $this->request($this->trip(), '10000.00');

        try {
            $this->advances->approve($advance, [], self::TENANT_A, $this->requester);
            $this->fail('a requester approved their own advance');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('second person', $e->getMessage());
        }
    }

    /** Nor rejects it — refusing is the same authority as allowing. */
    public function test_the_requester_cannot_reject_their_own_advance(): void
    {
        $advance = $this->request($this->trip(), '10000.00');

        $this->expectException(BusinessException::class);
        $this->advances->reject($advance, 'changed my mind', self::TENANT_A, $this->requester);
    }

    /* ══════════ Approval arithmetic — TRP-P0-008 ══════════ */

    /** Approving for less than was asked is the point of a policy limit. */
    public function test_an_advance_may_be_approved_for_less(): void
    {
        $advance = $this->advances->approve(
            $this->request($this->trip(), '30000.00'),
            ['amount_approved' => '15000.00', 'decision_reason' => 'Half now, half on arrival'],
            self::TENANT_A, $this->approver
        );

        $this->assertSame('30000.00', (string) $advance->amount_requested);
        $this->assertSame('15000.00', (string) $advance->amount_approved);
        $this->assertSame('15000.00', $this->advances->exposureFor($advance->trip_id, self::TENANT_A));
    }

    /** And never for more — "only approved amount payable". */
    public function test_an_advance_cannot_be_approved_for_more_than_requested(): void
    {
        $advance = $this->request($this->trip(), '10000.00');

        $this->expectException(BusinessException::class);
        $this->advances->approve($advance, ['amount_approved' => '10000.01'], self::TENANT_A, $this->approver);
    }

    /** A rejection has to say why, or the requester cannot act on it. */
    public function test_a_rejection_needs_a_reason(): void
    {
        $advance = $this->request($this->trip(), '10000.00');

        $this->expectException(BusinessException::class);
        $this->advances->reject($advance, '   ', self::TENANT_A, $this->approver);
    }

    /* ══════════ State machine ══════════ */

    /** Only the edges AdvanceStatus has built preconditions for. */
    public function test_a_decided_advance_cannot_be_decided_again(): void
    {
        $advance = $this->advances->approve($this->request($this->trip(), '10000.00'), [], self::TENANT_A, $this->approver);

        try {
            $this->advances->approve($advance, [], self::TENANT_A, $this->approver);
            $this->fail('an approved advance was approved twice');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('cannot be', $e->getMessage());
        }
    }

    /* ══════════ Shape rules ══════════ */

    /** DB-007 says "driver/supplier" — one of them, not both, not neither. */
    public function test_an_advance_names_exactly_one_counterparty(): void
    {
        $trip = $this->trip();

        foreach ([[], ['driver_id' => 4, 'supplier_id' => 9]] as $counterparty) {
            try {
                $this->advances->request(
                    $trip,
                    array_merge(['amount_requested' => '1000.00'], $counterparty),
                    self::TENANT_A, $this->requester
                );
                $this->fail('an advance was accepted with '.(count($counterparty) ? 'two' : 'no').' counterparties');
            } catch (BusinessException $e) {
                $this->assertStringContainsString('exactly one', $e->getMessage());
            }
        }
    }

    /** Another tenant's trip does not exist as far as this tenant is concerned. */
    public function test_an_advance_cannot_cross_a_tenant_boundary(): void
    {
        $this->expectException(\App\Exceptions\ResourceNotFoundException::class);
        $this->advances->request(
            $this->trip('100000.00', self::TENANT_B),
            ['amount_requested' => '1000.00', 'driver_id' => 4],
            self::TENANT_A, $this->requester
        );
    }

    /* ══════════ EVT-006 ══════════ */

    /** The registry's three payload fields, emitted on request. */
    public function test_requesting_emits_evt_006_with_the_registry_payload(): void
    {
        Event::fake([AdvanceRequested::class]);

        $advance = $this->request($this->trip(), '10000.00');

        Event::assertDispatched(AdvanceRequested::class, function (AdvanceRequested $e) use ($advance) {
            $this->assertSame(
                ['advance_id', 'trip_id', 'amount'],
                array_keys($e->payload()),
                'EVT-006 declares exactly three payload fields'
            );

            return $e->payload()['advance_id'] === $advance->id
                && $e->payload()['amount'] === '10000.00';
        });
    }

    /** Approving is not a request, so it must not re-emit EVT-006. */
    public function test_approving_does_not_emit_the_request_event_again(): void
    {
        $advance = $this->request($this->trip(), '10000.00');

        Event::fake([AdvanceRequested::class]);
        $this->advances->approve($advance, [], self::TENANT_A, $this->approver);

        Event::assertNotDispatched(AdvanceRequested::class);
    }

    /* ══════════ Audit — Step 13 FIN-01 ══════════ */

    /** Every decision leaves a trail naming who and what changed. */
    public function test_the_decision_is_audited_with_both_amounts(): void
    {
        $advance = $this->advances->approve(
            $this->request($this->trip(), '30000.00'),
            ['amount_approved' => '20000.00'],
            self::TENANT_A, $this->approver
        );

        $entry = $advance->auditTrail()->where('action', 'transport.advance.approved')->first();

        $this->assertNotNull($entry, 'an approval must be auditable');
        $this->assertSame('30000.00', $entry->context['amount_requested']);
        $this->assertSame('20000.00', $entry->context['amount_approved']);
    }
}
