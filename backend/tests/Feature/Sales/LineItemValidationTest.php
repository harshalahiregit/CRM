<?php

namespace Tests\Feature\Sales;

use App\Models\Customer\Client;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Line-item rules on the money documents.
 *
 * StoreInvoiceRequest, UpdateInvoiceRequest, StoreEstimateRequest,
 * UpdateEstimateRequest and StoreCreditNoteRequest all declared
 * `line_items => nullable|array` and then nothing — no `line_items.*` rules at
 * all. A negative qty or rate went straight through and produced a
 * negative-total document that the ledger then had to carry. The sibling
 * StoreProposalRequest had the rules the whole time; these are the same ones.
 *
 * The cases here are the ones that reach the database if the rules are absent,
 * plus the happy path, because validation that rejects everything is not a fix.
 */
class LineItemValidationTest extends TestCase
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

        Sanctum::actingAs(User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'email' => 'a'.uniqid().'@test.com',
            'password' => bcrypt('secret'), 'role' => 'admin', 'status' => 'active',
        ]));
    }

    private function client(): Client
    {
        return Client::create(['tenant_id' => self::TENANT, 'company' => 'Acme Pvt Ltd']);
    }

    private function line(array $over = []): array
    {
        return array_merge(['item_name' => 'Consulting', 'qty' => 2, 'rate' => 15000, 'tax' => 18, 'discount' => 0], $over);
    }

    private function invoice(array $lines): array
    {
        return ['client_id' => $this->client()->id, 'date' => '2026-08-01', 'due_date' => '2026-08-31', 'line_items' => $lines];
    }

    private function estimate(array $lines): array
    {
        return ['client_id' => $this->client()->id, 'date' => '2026-08-01', 'expiry_date' => '2026-09-01', 'line_items' => $lines];
    }

    /** @return array<string, array{0: string, 1: array}> */
    public static function documents(): array
    {
        return [
            'invoice'     => ['/api/sales/invoices', 'invoice'],
            'estimate'    => ['/api/sales/estimates', 'estimate'],
            'credit note' => ['/api/sales/credit-notes', 'invoice'],
        ];
    }

    /**
     * @dataProvider documents
     */
    public function test_a_negative_quantity_is_rejected(string $url, string $shape): void
    {
        $payload = $shape === 'estimate'
            ? $this->estimate([$this->line(['qty' => -5])])
            : $this->invoice([$this->line(['qty' => -5])]);

        $this->postJson($url, $payload)
             ->assertStatus(422)
             ->assertJsonValidationErrors('line_items.0.qty');
    }

    /**
     * @dataProvider documents
     */
    public function test_a_negative_rate_is_rejected(string $url, string $shape): void
    {
        $payload = $shape === 'estimate'
            ? $this->estimate([$this->line(['rate' => -1000])])
            : $this->invoice([$this->line(['rate' => -1000])]);

        $this->postJson($url, $payload)
             ->assertStatus(422)
             ->assertJsonValidationErrors('line_items.0.rate');
    }

    /**
     * @dataProvider documents
     */
    public function test_a_line_with_no_name_is_rejected(string $url, string $shape): void
    {
        $line = $this->line();
        unset($line['item_name']);

        $payload = $shape === 'estimate' ? $this->estimate([$line]) : $this->invoice([$line]);

        $this->postJson($url, $payload)
             ->assertStatus(422)
             ->assertJsonValidationErrors('line_items.0.item_name');
    }

    /** Tax is a percentage; 150% is a typo, not a rate. */
    public function test_a_tax_rate_above_one_hundred_percent_is_rejected(): void
    {
        $this->postJson('/api/sales/invoices', $this->invoice([$this->line(['tax' => 150])]))
             ->assertStatus(422)
             ->assertJsonValidationErrors('line_items.0.tax');
    }

    public function test_a_negative_discount_is_rejected(): void
    {
        $this->postJson('/api/sales/invoices', $this->invoice([$this->line(['discount' => -50])]))
             ->assertStatus(422)
             ->assertJsonValidationErrors('line_items.0.discount');
    }

    /** Validation that rejects everything is not a fix. */
    public function test_an_ordinary_invoice_still_saves(): void
    {
        $this->postJson('/api/sales/invoices', $this->invoice([$this->line()]))
             ->assertStatus(201);
    }

    /** Zero is legitimate — a free line on an otherwise billable document. */
    public function test_a_zero_quantity_or_rate_is_allowed(): void
    {
        $this->postJson('/api/sales/invoices', $this->invoice([
            $this->line(['qty' => 0]),
            $this->line(['rate' => 0]),
        ]))->assertStatus(201);
    }

    /** An invoice with no lines at all is still legal — line_items is nullable. */
    public function test_a_document_with_no_lines_is_still_accepted(): void
    {
        $payload = $this->invoice([]);
        unset($payload['line_items']);

        $this->postJson('/api/sales/invoices', $payload)->assertStatus(201);
    }

    /** Update must be guarded too, or the rule is a create-time formality. */
    public function test_updating_an_invoice_with_a_negative_quantity_is_rejected(): void
    {
        $id = $this->postJson('/api/sales/invoices', $this->invoice([$this->line()]))->json('id');

        $this->putJson("/api/sales/invoices/{$id}", $this->invoice([$this->line(['qty' => -3])]))
             ->assertStatus(422)
             ->assertJsonValidationErrors('line_items.0.qty');
    }
}
