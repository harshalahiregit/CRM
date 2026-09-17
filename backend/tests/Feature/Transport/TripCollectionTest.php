<?php

namespace Tests\Feature\Transport;

use App\Events\Transport\CollectionRecorded;
use App\Exceptions\BusinessException;
use App\Exceptions\ResourceNotFoundException;
use App\Models\Tenant;
use App\Models\Transport\TransportOrder;
use App\Models\Transport\TransportTrip;
use App\Models\Transport\TripCollection;
use App\Models\User;
use App\Services\Transport\TripBillingService;
use App\Services\Transport\TripCollectionService;
use App\Services\Transport\TripDocumentService;
use App\Support\Transport\CollectionStatus;
use App\Support\Transport\TripStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SNG-TRN-016 — "Due dates, blockers, follow-up and audit trail."
 *
 * Four nouns, and each has tests below. The fourth is served by the shared
 * audit trail rather than a private history table, so it is asserted by
 * counting entries on the record itself.
 *
 * ── THE FULL CHAIN IS EXERCISED ONCE ────────────────────────────────────
 * pod_verified -> billable (015) -> billed (Accounts, via markInvoiced)
 * -> collection_pending (016). That walk is the thing most likely to break
 * silently when somebody edits the state machine, and it crosses two module
 * boundaries, so it gets its own test rather than being assumed.
 *
 * ── AND WHAT TRANSPORT MUST NOT DO ──────────────────────────────────────
 * Recording a receipt moves a TRACKED balance. EVT-011's payload is
 * `receipt_id, invoice_id, amount` — accounting identifiers this module does
 * not create — so the event test asserts `receipt_id` is null rather than
 * fabricated. That failing would mean somebody started inventing accounting
 * records here.
 */
class TripCollectionTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private TripCollectionService $collections;
    private TripBillingService $billing;
    private TripDocumentService $documents;
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

        $this->collections = app(TripCollectionService::class);
        $this->billing     = app(TripBillingService::class);
        $this->documents   = app(TripDocumentService::class);
        $this->actor       = User::create([
            'tenant_id' => self::TENANT_A, 'name' => 'Accounts', 'role' => 'staff',
            'internal_role' => 'accounts', 'email' => 'acc-'.Str::random(6).'@test.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function trip(int $tenantId = self::TENANT_A, string $freight = '10000.00'): TransportTrip
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
        $trip->forceFill(['status' => TripStatus::DELIVERED, 'approved_freight' => $freight])->save();

        return $trip->fresh();
    }

    /** A trip walked all the way to `billed`, the way the real chain does it. */
    private function billedTrip(string $freight = '10000.00', int $tenantId = self::TENANT_A): TransportTrip
    {
        $trip = $this->trip($tenantId, $freight);

        $doc = $this->documents->file($trip, UploadedFile::fake()->image('pod.jpg'), [], $tenantId, $this->actor);
        $this->documents->verify($doc, $tenantId, $this->actor);          // -> pod_verified

        $bill = $this->billing->prepare($trip->fresh(), $tenantId, $this->actor);   // -> billable
        $bill->markInvoiced(9001, $this->actor->id);                                // -> billed (Accounts)

        return $trip->fresh();
    }

    private function openCollection(?string $due = null, string $freight = '10000.00'): TripCollection
    {
        return $this->collections->open($this->billedTrip($freight), self::TENANT_A, $due, $this->actor);
    }

    /* ── The chain across two modules ─────────────────────────────────── */

    public function test_the_whole_chain_walks_from_pod_to_collection_pending(): void
    {
        $trip = $this->billedTrip();

        $this->assertSame(TripStatus::BILLED, $trip->status,
            'markInvoiced is Accounts door and should have walked STT-010');

        $this->collections->open($trip, self::TENANT_A, null, $this->actor);

        $this->assertSame(TripStatus::COLLECTION_PENDING, $trip->fresh()->status);   // STT-011
    }

    public function test_a_trip_with_no_prepared_bill_has_nothing_to_collect(): void
    {
        // STT-011's guard is "Receivable exists".
        $this->expectException(BusinessException::class);
        $this->collections->open($this->trip(), self::TENANT_A, null, $this->actor);
    }

    public function test_the_amount_due_comes_from_the_frozen_bill(): void
    {
        $collection = $this->openCollection(null, '45500.50');

        $this->assertSame('45500.50', (string) $collection->amount_due);
        $this->assertSame(CollectionStatus::PENDING, $collection->status);
        $this->assertSame('45500.50', $collection->outstanding);
    }

    public function test_opening_twice_returns_the_same_receivable(): void
    {
        $trip  = $this->billedTrip();
        $first = $this->collections->open($trip, self::TENANT_A, null, $this->actor);
        $again = $this->collections->open($trip->fresh(), self::TENANT_A, null, $this->actor);

        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, TripCollection::forTenant(self::TENANT_A)->count());
    }

    /* ── Receipts, and the arithmetic ─────────────────────────────────── */

    public function test_a_part_payment_moves_the_status_and_the_balance(): void
    {
        $c = $this->openCollection();

        $this->collections->record($c, '2500.00', self::TENANT_A, $this->actor);

        $this->assertSame(CollectionStatus::PART_PAID, $c->fresh()->status);
        $this->assertSame('7500.00', $c->fresh()->outstanding);
    }

    public function test_paying_the_balance_settles_it(): void
    {
        $c = $this->openCollection();

        $this->collections->record($c, '4000.00', self::TENANT_A, $this->actor);
        $this->collections->record($c->fresh(), '6000.00', self::TENANT_A, $this->actor);

        $fresh = $c->fresh();
        $this->assertSame(CollectionStatus::SETTLED, $fresh->status);
        $this->assertSame('0.00', $fresh->outstanding);
        $this->assertNotNull($fresh->settled_at);
    }

    public function test_receipts_do_not_drift(): void
    {
        $c = $this->openCollection(null, '0.30');

        $this->collections->record($c, '0.10', self::TENANT_A, $this->actor);
        $this->collections->record($c->fresh(), '0.20', self::TENANT_A, $this->actor);

        // 0.1 + 0.2 must settle exactly, not leave 0.00000000000000004 owing.
        $this->assertSame(CollectionStatus::SETTLED, $c->fresh()->status);
        $this->assertSame('0.00', $c->fresh()->outstanding);
    }

    public function test_overpayment_is_refused(): void
    {
        $c = $this->openCollection();

        // A negative balance would make the ageing total meaningless, and a
        // refund is Accounts' decision rather than a tracking one.
        $this->expectException(BusinessException::class);
        $this->collections->record($c, '10000.01', self::TENANT_A, $this->actor);
    }

    public function test_a_zero_or_negative_receipt_is_refused(): void
    {
        $c = $this->openCollection();
        $refused = 0;

        foreach (['0', '0.00', '-5.00', 'abc'] as $bad) {
            try {
                $this->collections->record($c->fresh(), $bad, self::TENANT_A, $this->actor);
            } catch (BusinessException) {
                $refused++;
            }
        }

        $this->assertSame(4, $refused);
        $this->assertSame('0.00', (string) $c->fresh()->amount_received);
    }

    /* ── Blockers ─────────────────────────────────────────────────────── */

    public function test_a_blocker_does_not_hide_the_balance(): void
    {
        $c = $this->openCollection();
        $this->collections->record($c, '2500.00', self::TENANT_A, $this->actor);
        $this->collections->block($c->fresh(), 'customer disputes the detention charge', self::TENANT_A, $this->actor);

        $fresh = $c->fresh();

        // Part-paid AND blocked is the case somebody actually has to chase.
        // A "blocked" status would have made it unrepresentable.
        $this->assertTrue($fresh->isBlocked());
        $this->assertSame(CollectionStatus::PART_PAID, $fresh->status);
        $this->assertSame('7500.00', $fresh->outstanding);
    }

    public function test_a_blocker_needs_a_reason_and_can_be_cleared(): void
    {
        $c = $this->openCollection();

        try {
            $this->collections->block($c, '  ', self::TENANT_A, $this->actor);
            $this->fail('a blank blocker reason should have been refused');
        } catch (BusinessException) {
            // expected
        }

        $this->collections->block($c->fresh(), 'awaiting signed POD copy', self::TENANT_A, $this->actor);
        $this->assertTrue($c->fresh()->isBlocked());

        $this->collections->clearBlocker($c->fresh(), self::TENANT_A, $this->actor);
        $this->assertFalse($c->fresh()->isBlocked());
    }

    public function test_blocked_receivables_can_be_listed(): void
    {
        $blocked = $this->openCollection();
        $this->openCollection();
        $this->collections->block($blocked, 'disputed', self::TENANT_A, $this->actor);

        $ids = TripCollection::forTenant(self::TENANT_A)->blocked()->pluck('id');

        $this->assertSame([$blocked->id], $ids->all());
    }

    /* ── Due dates and ageing ─────────────────────────────────────────── */

    public function test_the_ageing_report_buckets_by_days_overdue(): void
    {
        $this->openCollection(now()->subDays(5)->toDateString(),  '100.00');   // 1_30
        $this->openCollection(now()->subDays(45)->toDateString(), '200.00');   // 31_60
        $this->openCollection(now()->addDays(10)->toDateString(), '400.00');   // current
        $this->openCollection(now()->subDays(120)->toDateString(), '800.00');  // over_90

        $ageing = $this->collections->ageing(self::TENANT_A);

        $this->assertSame('100.00', $ageing['buckets']['1_30']);
        $this->assertSame('200.00', $ageing['buckets']['31_60']);
        $this->assertSame('400.00', $ageing['buckets']['current']);
        $this->assertSame('800.00', $ageing['buckets']['over_90']);
        $this->assertSame('1500.00', $ageing['total']);
    }

    public function test_settled_receivables_leave_the_ageing_report(): void
    {
        $c = $this->openCollection(now()->subDays(10)->toDateString(), '500.00');

        $this->assertSame('500.00', $this->collections->ageing(self::TENANT_A)['total']);

        $this->collections->record($c, '500.00', self::TENANT_A, $this->actor);

        $this->assertSame('0.00', $this->collections->ageing(self::TENANT_A)['total']);
    }

    public function test_a_receivable_with_no_due_date_counts_as_current(): void
    {
        $this->openCollection(null, '750.00');

        $this->assertSame('750.00', $this->collections->ageing(self::TENANT_A)['buckets']['current']);
    }

    /* ── Follow-up ────────────────────────────────────────────────────── */

    public function test_the_follow_up_queue_returns_what_is_due_today_or_earlier(): void
    {
        $now   = $this->openCollection();
        $later = $this->openCollection();

        $this->collections->followUp($now, self::TENANT_A, now()->subDay()->toDateString(), 'called, no answer', $this->actor);
        $this->collections->followUp($later, self::TENANT_A, now()->addDays(7)->toDateString(), 'agreed to pay', $this->actor);

        $ids = $this->collections->followUpQueue(self::TENANT_A)->pluck('id');

        $this->assertTrue($ids->contains($now->id));
        $this->assertFalse($ids->contains($later->id));
    }

    public function test_following_up_records_who_and_when(): void
    {
        $c = $this->openCollection();

        $this->collections->followUp($c, self::TENANT_A, null, 'left a voicemail', $this->actor);

        $fresh = $c->fresh();
        $this->assertNotNull($fresh->last_followed_up_at);
        $this->assertSame($this->actor->id, (int) $fresh->last_followed_up_by);
    }

    public function test_a_settled_receivable_leaves_the_follow_up_queue(): void
    {
        $c = $this->openCollection();
        $this->collections->followUp($c, self::TENANT_A, now()->subDay()->toDateString(), null, $this->actor);
        $this->collections->record($c->fresh(), '10000.00', self::TENANT_A, $this->actor);

        $this->assertCount(0, $this->collections->followUpQueue(self::TENANT_A));
    }

    /* ── The audit trail — the fourth noun ────────────────────────────── */

    public function test_every_act_lands_in_one_timeline(): void
    {
        $c = $this->openCollection();                                                  // opened
        $this->collections->record($c, '1000.00', self::TENANT_A, $this->actor);       // received + status
        $this->collections->block($c->fresh(), 'disputed', self::TENANT_A, $this->actor);       // blocked
        $this->collections->followUp($c->fresh(), self::TENANT_A, null, 'chased', $this->actor); // followed up

        // opened, status change, received, blocked, followed up.
        $this->assertGreaterThanOrEqual(5, $c->auditTrail()->count());
    }

    /* ── The event, and the wall ──────────────────────────────────────── */

    public function test_recording_emits_collection_recorded_without_inventing_a_receipt_id(): void
    {
        Event::fake([CollectionRecorded::class]);
        $c = $this->openCollection();

        $this->collections->record($c, '2500.00', self::TENANT_A, $this->actor);

        Event::assertDispatched(CollectionRecorded::class, function (CollectionRecorded $e) use ($c) {
            $p = $e->payload();

            // receipt_id and posting_id are Accounts' records. Transport does
            // not create them and must never fabricate one.
            return $p['receipt_id'] === null
                && $p['amount'] === '2500.00'
                && $p['collection_id'] === $c->id
                && $p['invoice_id'] === 9001;     // known, because Accounts filled it in
        });
    }

    public function test_the_event_carries_this_receipt_not_the_running_total(): void
    {
        Event::fake([CollectionRecorded::class]);
        $c = $this->openCollection();

        $this->collections->record($c, '1000.00', self::TENANT_A, $this->actor);
        $this->collections->record($c->fresh(), '1500.00', self::TENANT_A, $this->actor);

        Event::assertDispatched(CollectionRecorded::class,
            fn (CollectionRecorded $e) => $e->payload()['amount'] === '1500.00');
    }

    /* ── Tenancy ──────────────────────────────────────────────────────── */

    public function test_receivables_are_tenant_scoped(): void
    {
        $this->openCollection(null, '500.00');

        $this->assertSame('500.00', $this->collections->ageing(self::TENANT_A)['total']);
        $this->assertSame('0.00', $this->collections->ageing(self::TENANT_B)['total']);
    }

    public function test_another_tenants_receivable_cannot_be_recorded_against(): void
    {
        $c = $this->openCollection();

        $this->expectException(ResourceNotFoundException::class);
        $this->collections->record($c, '100.00', self::TENANT_B, $this->actor);
    }

    public function test_another_tenants_trip_reads_as_not_found(): void
    {
        $foreign = $this->trip(self::TENANT_B);

        $this->expectException(ResourceNotFoundException::class);
        $this->collections->open($foreign, self::TENANT_A, null, $this->actor);
    }
}
