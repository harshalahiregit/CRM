<?php

namespace Tests\Feature\Sales;

use App\Models\Customer\Client;
use App\Models\Sales\Estimate;
use App\Models\Sales\Lead;
use App\Models\Sales\LeadStatus;
use App\Models\Sales\SalesInvoice;
use App\Models\Sales\SalesPayment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * An export must contain what the screen was showing.
 *
 * Three separate ways it did not:
 *
 *  - Leads narrowed by status_id and Payments by mode, but both passed only
 *    `search` to the export, so the CSV held every row regardless of the tab.
 *  - The Estimates "Expired" tab is derived in the browser (Sent + past
 *    valid_until). Export forwarded the literal string to a `status` column
 *    nothing ever writes, so the file came back empty for the tab on screen.
 *  - Conversely a "Sent" export included estimates the screen had already moved
 *    into Expired.
 *
 * These assert the file and the list agree.
 */
class ExportMatchesTheScreenTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();

        $this->actor = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'email' => 'a'.uniqid().'@t.local',
            'password' => bcrypt('secret'), 'role' => 'admin', 'status' => 'active',
        ]);
        Sanctum::actingAs($this->actor);
    }

    /** Rows of the streamed CSV, header included. */
    private function csv(string $type, array $query = []): array
    {
        $res = $this->get('/api/sales/export/'.$type.'?'.http_build_query($query))->assertOk();

        $body = $res->streamedContent() !== '' ? $res->streamedContent() : $res->getContent();
        if ($body === '' && method_exists($res->baseResponse, 'getFile')) {
            $body = file_get_contents($res->baseResponse->getFile()->getPathname());
        }

        return array_values(array_filter(explode("\n", trim($body))));
    }

    /** Real pipeline statuses — leads.status_id is a foreign key. */
    private function statuses(): array
    {
        return [
            LeadStatus::create(['tenant_id' => self::TENANT, 'name' => 'New',       'sort_order' => 1])->id,
            LeadStatus::create(['tenant_id' => self::TENANT, 'name' => 'Qualified', 'sort_order' => 2])->id,
        ];
    }

    private function client(): Client
    {
        return Client::create(['tenant_id' => self::TENANT, 'company' => 'Acme']);
    }

    // ── Estimates: "Expired" is derived, never stored ────────────────────────

    private function estimate(string $status, ?string $validUntil, string $ref): Estimate
    {
        return Estimate::create([
            'tenant_id' => self::TENANT, 'reference' => $ref, 'subject' => 'Job '.$ref,
            'client_id' => $this->client()->id, 'date' => '2026-01-01',
            'valid_until' => $validUntil, 'status' => $status, 'created_by' => $this->actor->id,
            'subtotal' => 100, 'tax_total' => 0, 'discount_total' => 0, 'total' => 100,
        ]);
    }

    public function test_exporting_the_expired_tab_returns_the_estimates_the_screen_calls_expired(): void
    {
        $this->estimate('Sent',     '2020-01-01', 'EXPIRED-1');   // past  -> Expired on screen
        $this->estimate('Sent',     '2099-01-01', 'LIVE-1');      // future-> still Sent
        $this->estimate('Accepted', '2020-01-01', 'ACCEPTED-1');  // past but decided -> not Expired

        $csv = implode("\n", $this->csv('estimates', ['status' => 'Expired']));

        $this->assertStringContainsString('EXPIRED-1', $csv,
            'the tab on screen showed this one, so the file must contain it');
        $this->assertStringNotContainsString('LIVE-1', $csv);
        $this->assertStringNotContainsString('ACCEPTED-1', $csv,
            'a decided estimate is not expired, whatever its valid_until says');
    }

    public function test_exporting_the_sent_tab_excludes_the_ones_the_screen_moved_to_expired(): void
    {
        $this->estimate('Sent', '2020-01-01', 'EXPIRED-2');
        $this->estimate('Sent', '2099-01-01', 'LIVE-2');

        $csv = implode("\n", $this->csv('estimates', ['status' => 'Sent']));

        $this->assertStringContainsString('LIVE-2', $csv);
        $this->assertStringNotContainsString('EXPIRED-2', $csv,
            'the Sent tab hides expired estimates, so its export must too');
    }

    // ── Leads: status_id never reached the exporter ──────────────────────────

    public function test_exporting_leads_honours_the_status_tab(): void
    {
        [$new, $qualified] = $this->statuses();
        Lead::create(['tenant_id' => self::TENANT, 'name' => 'LEAD-A', 'status_id' => $new,       'created_by' => $this->actor->id]);
        Lead::create(['tenant_id' => self::TENANT, 'name' => 'LEAD-B', 'status_id' => $qualified, 'created_by' => $this->actor->id]);

        $csv = implode("\n", $this->csv('leads', ['status_id' => $new]));

        $this->assertStringContainsString('LEAD-A', $csv);
        $this->assertStringNotContainsString('LEAD-B', $csv,
            'the export dumped every lead regardless of the status tab');
    }

    public function test_exporting_leads_with_no_tab_selected_still_returns_everything(): void
    {
        [$new, $qualified] = $this->statuses();
        Lead::create(['tenant_id' => self::TENANT, 'name' => 'LEAD-C', 'status_id' => $new,       'created_by' => $this->actor->id]);
        Lead::create(['tenant_id' => self::TENANT, 'name' => 'LEAD-D', 'status_id' => $qualified, 'created_by' => $this->actor->id]);

        $csv = implode("\n", $this->csv('leads'));

        $this->assertStringContainsString('LEAD-C', $csv);
        $this->assertStringContainsString('LEAD-D', $csv);
    }

    // ── Payments: mode never reached the exporter ────────────────────────────

    public function test_exporting_payments_honours_the_mode_chips(): void
    {
        $inv = SalesInvoice::create([
            'tenant_id' => self::TENANT, 'number' => 'INV-X', 'client_id' => $this->client()->id,
            'date' => '2026-01-01', 'due_date' => '2026-02-01', 'currency' => 'INR',
            'subtotal' => 500, 'tax_total' => 0, 'discount_total' => 0, 'total' => 500,
            'paid' => 0, 'balance' => 500, 'status' => 'Draft', 'created_by' => $this->actor->id,
        ]);

        SalesPayment::create(['tenant_id' => self::TENANT, 'invoice_id' => $inv->id,
            'date' => '2026-01-05', 'amount' => 100, 'mode' => 'Cash', 'transaction_id' => 'PAY-CASH', 'created_by' => $this->actor->id]);
        SalesPayment::create(['tenant_id' => self::TENANT, 'invoice_id' => $inv->id,
            'date' => '2026-01-06', 'amount' => 200, 'mode' => 'UPI', 'transaction_id' => 'PAY-UPI', 'created_by' => $this->actor->id]);

        $csv = implode("\n", $this->csv('payments', ['mode' => 'Cash']));

        $this->assertStringContainsString('PAY-CASH', $csv);
        $this->assertStringNotContainsString('PAY-UPI', $csv,
            'the mode chips narrowed the screen but the export ignored them');
    }
}
