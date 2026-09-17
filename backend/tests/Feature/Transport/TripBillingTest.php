<?php

namespace Tests\Feature\Transport;

use App\Events\Transport\BillingPrepared;
use App\Exceptions\BusinessException;
use App\Exceptions\ResourceNotFoundException;
use App\Models\Tenant;
use App\Models\Transport\TransportOrder;
use App\Models\Transport\TransportTrip;
use App\Models\Transport\TripBill;
use App\Models\User;
use App\Services\Transport\TripBillingService;
use App\Services\Transport\TripDocumentService;
use App\Support\Transport\ExceptionStatus;
use App\Support\Transport\TripBillStatus;
use App\Support\Transport\TripStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SNG-TRN-015 — "No billing without defined preconditions."
 *
 * Five words, and every test here is one of them. The preconditions are 014's:
 * a verified POD, or an approved exception waiving one, and the trip standing
 * in `pod_verified` where STT-009 starts.
 *
 * ── THE BOUNDARY IS AS IMPORTANT AS THE GATE ────────────────────────────
 * Step 11 puts the invoice on the other side of a wall:
 *
 *   EVT-010  InvoicePosted       Producer: Accounts
 *   EVT-011  CollectionRecorded  Producer: Accounts/Collections
 *
 * and FORBID-002 / LOCK-004 forbid Transport writing accounting at all. So the
 * last block of this file asserts what this module does NOT do — that preparing
 * billing leaves invoice_id NULL, and that nothing in Transport can set it.
 * Those tests fail the day somebody "helpfully" makes this service raise an
 * invoice, which is exactly when they should.
 */
class TripBillingTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_A = 1;
    private const TENANT_B = 2;

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

        $this->billing   = app(TripBillingService::class);
        $this->documents = app(TripDocumentService::class);
        $this->actor     = User::create([
            'tenant_id' => self::TENANT_A, 'name' => 'Accounts', 'role' => 'staff',
            'internal_role' => 'accounts', 'email' => 'acc-'.Str::random(6).'@test.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function trip(?string $status = null, int $tenantId = self::TENANT_A): TransportTrip
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
        $trip->forceFill([
            'status' => $status ?? TripStatus::APPROVED, 'approved_freight' => '45000.00',
        ])->save();

        return $trip->fresh();
    }

    /** A trip with a verified POD, standing where STT-009 begins. */
    private function billableTrip(): TransportTrip
    {
        $trip = $this->trip(TripStatus::DELIVERED);
        $doc  = $this->documents->file(
            $trip, UploadedFile::fake()->image('pod.jpg'), [], self::TENANT_A, $this->actor
        );
        $this->documents->verify($doc, self::TENANT_A, $this->actor);

        return $trip->fresh();   // STT-008 moved it to pod_verified
    }

    /* ── The preconditions ────────────────────────────────────────────── */

    public function test_a_trip_with_no_pod_cannot_be_billed(): void
    {
        $trip = $this->trip(TripStatus::DELIVERED);

        $this->assertFalse($this->billing->readiness($trip, self::TENANT_A)['preparable']);

        $this->expectException(BusinessException::class);
        $this->billing->prepare($trip, self::TENANT_A, $this->actor);
    }

    public function test_an_unverified_pod_is_not_enough(): void
    {
        $trip = $this->trip(TripStatus::DELIVERED);
        $this->documents->file($trip, UploadedFile::fake()->image('pod.jpg'), [], self::TENANT_A, $this->actor);

        $this->expectException(BusinessException::class);
        $this->billing->prepare($trip->fresh(), self::TENANT_A, $this->actor);
    }

    public function test_a_verified_pod_makes_a_trip_billable(): void
    {
        $trip = $this->billableTrip();

        $this->assertSame(TripStatus::POD_VERIFIED, $trip->status,
            'STT-008 should have moved the trip when the POD was verified');

        $readiness = $this->billing->readiness($trip, self::TENANT_A);
        $this->assertTrue($readiness['preparable']);
        $this->assertSame('45000.00', $readiness['amount']);
    }

    public function test_preparing_moves_the_trip_to_billable_and_records_the_basis(): void
    {
        $trip = $this->billableTrip();

        $bill = $this->billing->prepare($trip, self::TENANT_A, $this->actor);

        $this->assertSame(TripStatus::BILLABLE, $trip->fresh()->status);   // STT-009
        $this->assertSame(TripBillStatus::PREPARED, $bill->status);
        $this->assertSame('verified_pod', $bill->basis);
        $this->assertSame('45000.00', (string) $bill->billable_amount);
    }

    public function test_a_trip_that_is_not_pod_verified_is_refused_with_a_useful_reason(): void
    {
        // The waiver arm makes the POD check pass, but the trip is still sitting
        // in `approved` — so the refusal must be about the STATE, not the POD.
        $trip = $this->trip(TripStatus::APPROVED);
        $this->waive($trip);

        $readiness = $this->billing->readiness($trip, self::TENANT_A);

        $this->assertFalse($readiness['preparable']);
        $this->assertStringContainsString('POD has been verified', $readiness['reason']);
    }

    public function test_a_waived_exception_is_recorded_as_the_basis(): void
    {
        $trip = $this->trip(TripStatus::POD_VERIFIED);
        $this->waive($trip);

        $bill = $this->billing->prepare($trip, self::TENANT_A, $this->actor);

        $this->assertSame('exception_waiver', $bill->basis,
            'which arm let the money through has to survive the decision');
    }

    /* ── One bill per trip ────────────────────────────────────────────── */

    public function test_preparing_twice_returns_the_same_bill(): void
    {
        $trip = $this->billableTrip();

        $first  = $this->billing->prepare($trip, self::TENANT_A, $this->actor);
        $second = $this->billing->prepare($trip->fresh(), self::TENANT_A, $this->actor);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, TripBill::forTenant(self::TENANT_A)->count(),
            'a second linkage row would invite a second invoice');
    }

    public function test_readiness_says_so_once_billing_is_prepared(): void
    {
        $trip = $this->billableTrip();
        $this->billing->prepare($trip, self::TENANT_A, $this->actor);

        $readiness = $this->billing->readiness($trip->fresh(), self::TENANT_A);

        $this->assertFalse($readiness['preparable']);
        $this->assertTrue($readiness['already_billed']);
    }

    /* ── The amount is frozen ─────────────────────────────────────────── */

    public function test_amending_the_trip_afterwards_does_not_restate_the_bill(): void
    {
        $trip = $this->billableTrip();
        $bill = $this->billing->prepare($trip, self::TENANT_A, $this->actor);

        $trip->fresh()->forceFill(['approved_freight' => '99999.00'])->save();

        $this->assertSame('45000.00', (string) $bill->fresh()->billable_amount,
            'an invoice Accounts has already raised must not silently change');
    }

    public function test_the_amount_is_a_string_not_a_float(): void
    {
        $bill = $this->billing->prepare($this->billableTrip(), self::TENANT_A, $this->actor);

        // FIN-06. This figure is handed to Accounts to raise an invoice from.
        $this->assertIsString((string) $bill->billable_amount);
        $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', (string) $bill->billable_amount);
    }

    /* ── The event ────────────────────────────────────────────────────── */

    public function test_preparing_emits_billing_prepared(): void
    {
        Event::fake([BillingPrepared::class]);
        $trip = $this->billableTrip();

        $bill = $this->billing->prepare($trip, self::TENANT_A, $this->actor);

        Event::assertDispatched(BillingPrepared::class, fn (BillingPrepared $e) => $e->payload() === [
            'bill_id' => $bill->id, 'trip_id' => $trip->id,
            'amount' => '45000.00', 'currency' => 'INR',
        ]);
    }

    /* ── Tenancy ──────────────────────────────────────────────────────── */

    public function test_another_tenants_trip_reads_as_not_found(): void
    {
        $foreign = $this->trip(TripStatus::POD_VERIFIED, self::TENANT_B);

        $this->expectException(ResourceNotFoundException::class);
        $this->billing->prepare($foreign, self::TENANT_A, $this->actor);
    }

    /* ── THE WALL: what Transport must never do ───────────────────────── */

    public function test_preparing_billing_does_not_create_an_invoice(): void
    {
        $bill = $this->billing->prepare($this->billableTrip(), self::TENANT_A, $this->actor);

        // EVT-010 InvoicePosted is produced by ACCOUNTS. Transport stops here.
        $this->assertNull($bill->invoice_id);
        $this->assertFalse($bill->isInvoiced());
        $this->assertSame(TripBillStatus::PREPARED, $bill->status);
    }

    public function test_invoice_id_cannot_be_mass_assigned_from_transport(): void
    {
        $bill = $this->billing->prepare($this->billableTrip(), self::TENANT_A, $this->actor);

        $bill->update(['invoice_id' => 4242, 'status' => TripBillStatus::INVOICED]);

        // The one column belonging to the other module is the one a Transport
        // caller cannot set by posting a field.
        $this->assertNull($bill->fresh()->invoice_id);
    }

    public function test_accounts_can_complete_the_linkage_through_the_one_door(): void
    {
        $bill = $this->billing->prepare($this->billableTrip(), self::TENANT_A, $this->actor);

        $bill->markInvoiced(4242, $this->actor->id);

        $this->assertSame(4242, $bill->fresh()->invoice_id);
        $this->assertSame(TripBillStatus::INVOICED, $bill->fresh()->status);
        $this->assertTrue($bill->fresh()->isInvoiced());
    }

    public function test_awaiting_invoice_is_the_queue_accounts_will_read(): void
    {
        $billed  = $this->billing->prepare($this->billableTrip(), self::TENANT_A, $this->actor);
        $pending = $this->billing->prepare($this->billableTrip(), self::TENANT_A, $this->actor);

        $billed->markInvoiced(1, null);

        $queue = TripBill::forTenant(self::TENANT_A)->awaitingInvoice()->pluck('id');

        $this->assertTrue($queue->contains($pending->id));
        $this->assertFalse($queue->contains($billed->id));
    }

    /* ── Helper ───────────────────────────────────────────────────────── */

    private function waive(TransportTrip $trip): void
    {
        DB::table('trip_exceptions')->insert([
            'tenant_id' => (int) $trip->tenant_id, 'trip_id' => $trip->id,
            'exception_number' => 'EXC-'.Str::random(6),
            'category' => 'documentation', 'severity' => 'medium',
            'status' => ExceptionStatus::WAIVED, 'source' => 'manual',
            'cause' => 'consignee refused to sign; gate log confirms delivery',
            'raised_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
