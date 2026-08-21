<?php

namespace Tests\Feature\Sales;

use App\Models\Customer\Client;
use App\Models\Sales\SalesInvoice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The auto-overdue sweep that runs when the invoice list is read.
 *
 * Listing invoices writes: InvoiceService::list() calls updateOverdueStatus()
 * on every row. That is deliberate, but it makes the guard conditions load
 * bearing — a wrong one silently rewrites real invoices whenever anybody opens
 * a screen, and Customer Health reads those same rows.
 *
 * The Draft case is the one that actually bit: opening the list promoted an
 * unissued draft with a past due date to Overdue, which pulled it into the
 * health denominator and moved the customer's score with no user action.
 */
class OverdueSweepTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'Tenant 1', 'slug' => 'tenant-1',
            'subdomain' => 'tenant1', 'status' => 'active',
        ])->save();
    }

    private ?User $actor = null;

    /** One admin for the whole test: the creator of the invoices and the caller. */
    private function actor(): User
    {
        return $this->actor ??= User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'email' => 'a'.uniqid().'@test.com',
            'password' => bcrypt('secret'), 'role' => 'admin', 'status' => 'active',
        ]);
    }

    private function invoice(string $status, string $dueDate, float $balance = 5000): SalesInvoice
    {
        $client = Client::create(['tenant_id' => self::TENANT, 'company' => 'Acme Pvt Ltd']);

        return SalesInvoice::create([
            'tenant_id'  => self::TENANT,
            'client_id'  => $client->id,
            'created_by' => $this->actor()->id,
            'number'    => 'INV-'.uniqid(),
            'date'      => '2026-01-01',
            'due_date'  => $dueDate,
            'status'    => $status,
            'total'     => 5000,
            'paid'      => 5000 - $balance,
            'balance'   => $balance,
        ]);
    }

    public function test_a_draft_is_never_promoted_to_overdue(): void
    {
        $inv = $this->invoice('Draft', '2020-01-01');

        Sanctum::actingAs($this->actor());
        $this->getJson('/api/sales/invoices')->assertOk();

        // A draft has never been issued, so it cannot be late.
        $this->assertSame('Draft', $inv->fresh()->status);
    }

    public function test_an_issued_invoice_past_its_due_date_becomes_overdue(): void
    {
        $inv = $this->invoice('Unpaid', '2020-01-01');

        Sanctum::actingAs($this->actor());
        $this->getJson('/api/sales/invoices')->assertOk();

        $this->assertSame('Overdue', $inv->fresh()->status);
    }

    public function test_a_future_due_date_is_left_alone(): void
    {
        $inv = $this->invoice('Unpaid', '2099-01-01');

        Sanctum::actingAs($this->actor());
        $this->getJson('/api/sales/invoices')->assertOk();

        $this->assertSame('Unpaid', $inv->fresh()->status);
    }

    public function test_a_settled_invoice_is_left_alone(): void
    {
        $paid      = $this->invoice('Paid', '2020-01-01', balance: 0);
        $cancelled = $this->invoice('Cancelled', '2020-01-01');

        Sanctum::actingAs($this->actor());
        $this->getJson('/api/sales/invoices')->assertOk();

        $this->assertSame('Paid', $paid->fresh()->status);
        $this->assertSame('Cancelled', $cancelled->fresh()->status);
    }

    public function test_listing_twice_does_not_rewrite_an_already_overdue_invoice(): void
    {
        $inv = $this->invoice('Unpaid', '2020-01-01');

        Sanctum::actingAs($this->actor());
        $this->getJson('/api/sales/invoices')->assertOk();

        $touched = $inv->fresh()->updated_at;

        $this->getJson('/api/sales/invoices')->assertOk();

        // Write-on-read is tolerable once; on every page view it is not.
        $this->assertEquals($touched, $inv->fresh()->updated_at);
    }
}
