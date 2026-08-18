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
 * Payments against an invoice: the balance arithmetic and the status it drives.
 *
 * This is the part of Sales where a silent error costs real money — an invoice
 * that reads Paid when it isn't, or a balance that drifts from the payments
 * recorded against it.
 */
class PaymentAndBalanceTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();
    }

    private function admin(): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'email' => 'a'.uniqid().'@test.com',
            'password' => bcrypt('secret'), 'role' => 'admin', 'status' => 'active',
        ]);
    }

    /** A 10,000 + 18% = 11,800 invoice. */
    private function invoice(): SalesInvoice
    {
        $client = Client::create(['tenant_id' => self::TENANT, 'company' => 'Acme']);

        $id = $this->postJson('/api/sales/invoices', [
            'client_id' => $client->id,
            'date' => '2026-08-01', 'due_date' => '2026-08-31',
            'line_items' => [
                ['item_name' => 'Work', 'qty' => 1, 'rate' => 10000, 'tax' => 18, 'discount' => 0],
            ],
        ])->assertStatus(201)->json('id');

        return SalesInvoice::find($id);
    }

    public function test_a_new_invoice_starts_fully_outstanding(): void
    {
        Sanctum::actingAs($this->admin());
        $invoice = $this->invoice();

        $this->assertEquals(11800, round((float) $invoice->total, 2));
        $this->assertEquals(11800, round((float) $invoice->balance, 2));
    }

    public function test_a_partial_payment_reduces_the_balance_without_marking_it_paid(): void
    {
        Sanctum::actingAs($this->admin());
        $invoice = $this->invoice();

        $this->postJson("/api/sales/invoices/{$invoice->id}/payments", [
            'amount' => 4000, 'date' => '2026-08-05', 'mode' => 'Bank Transfer',
        ])->assertSuccessful();

        $invoice->refresh();
        $this->assertEquals(7800, round((float) $invoice->balance, 2));
        $this->assertNotSame('Paid', $invoice->status);
    }

    public function test_paying_the_full_amount_clears_the_balance_and_marks_it_paid(): void
    {
        Sanctum::actingAs($this->admin());
        $invoice = $this->invoice();

        $this->postJson("/api/sales/invoices/{$invoice->id}/payments", [
            'amount' => 11800, 'date' => '2026-08-05', 'mode' => 'Bank Transfer',
        ])->assertSuccessful();

        $invoice->refresh();
        $this->assertEquals(0, round((float) $invoice->balance, 2));
        $this->assertSame('Paid', $invoice->status);
    }

    public function test_several_payments_sum_correctly(): void
    {
        Sanctum::actingAs($this->admin());
        $invoice = $this->invoice();

        foreach ([2000, 3000, 6800] as $amount) {
            $this->postJson("/api/sales/invoices/{$invoice->id}/payments", [
                'amount' => $amount, 'date' => '2026-08-05', 'mode' => 'Cash',
            ])->assertSuccessful();
        }

        $invoice->refresh();
        $this->assertEquals(0, round((float) $invoice->balance, 2));
        $this->assertSame('Paid', $invoice->status);
    }
}
