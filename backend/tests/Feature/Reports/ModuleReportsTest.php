<?php

namespace Tests\Feature\Reports;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Helpdesk\HelpdeskReportService;
use App\Services\Sales\SalesReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Report sections for Sales and Help Desk.
 *
 * The brief asked for "a dedicated Report section in every single module".
 * Accounts, Purchase and Medical had one; Sales and Help Desk had a dashboard
 * and analytics, which is a different thing — those describe right now, and are
 * not filterable, groupable or exportable.
 *
 * The figures these tests pin are the ones easiest to get subtly wrong: what
 * counts as overdue, whether billing and collection are told apart, and how a
 * ticket that was never answered is averaged.
 */
class ModuleReportsTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant)->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active',
        ])->save();
    }

    private ?User $creator = null;

    /** A real user for the NOT NULL, FK-constrained created_by column. */
    private function creator(): User
    {
        return $this->creator ??= $this->admin();
    }

    private function admin(): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'admin-'.Str::random(6).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function client(string $company): int
    {
        return DB::table('clients')->insertGetId([
            'tenant_id' => self::TENANT, 'company' => $company,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function invoice(array $row): int
    {
        return DB::table('sales_invoices')->insertGetId(array_merge([
            'tenant_id' => self::TENANT, 'number' => 'INV-'.Str::random(5),
            'date' => now()->subDays(10)->toDateString(),
            'due_date' => now()->addDays(20)->toDateString(),
            'status' => 'Unpaid', 'total' => 1000, 'paid' => 0, 'balance' => 1000,
            // Both NOT NULL on this table.
            'created_by' => $this->creator()->id, 'created_at' => now(), 'updated_at' => now(),
        ], $row));
    }

    private function ticket(array $row): int
    {
        return DB::table('tickets')->insertGetId(array_merge([
            'tenant_id' => self::TENANT, 'subject' => 'Something broke',
            'status' => 'Open', 'priority' => 'Normal',
            'created_at' => now()->subDays(5), 'updated_at' => now(),
        ], $row));
    }

    private function salesReport(array $f = []): array
    {
        return app(SalesReportService::class)->build(self::TENANT, $f);
    }

    private function helpdeskReport(array $f = []): array
    {
        return app(HelpdeskReportService::class)->build(self::TENANT, $f);
    }

    /* ── Sales ──────────────────────────────────────────────────────────── */

    public function test_the_sales_report_totals_what_was_billed_and_collected(): void
    {
        $c = $this->client('Acme');
        $this->invoice(['client_id' => $c, 'total' => 1000, 'paid' => 1000, 'balance' => 0, 'status' => 'Paid']);
        $this->invoice(['client_id' => $c, 'total' => 500,  'paid' => 200,  'balance' => 300]);

        $t = $this->salesReport()['totals'];

        $this->assertSame(2, $t['invoices']);
        $this->assertEquals(1500, $t['billed']);
        $this->assertEquals(1200, $t['paid']);
        $this->assertEquals(300, $t['outstanding']);
        $this->assertEquals(80.0, $t['collection_rate']);
    }

    public function test_only_a_past_due_unpaid_invoice_counts_as_overdue(): void
    {
        // An unpaid invoice that is not yet due is not a problem. Counting it as
        // one makes the overdue figure useless for chasing anybody.
        $c = $this->client('Acme');
        $this->invoice(['client_id' => $c, 'due_date' => now()->addDays(10)->toDateString(), 'balance' => 1000]);
        $this->invoice(['client_id' => $c, 'due_date' => now()->subDays(10)->toDateString(), 'balance' => 700, 'total' => 700]);
        // Past due but fully paid — settled, so not overdue.
        $this->invoice(['client_id' => $c, 'due_date' => now()->subDays(10)->toDateString(), 'balance' => 0, 'paid' => 400, 'total' => 400]);

        $t = $this->salesReport()['totals'];

        $this->assertSame(1, $t['overdue_count']);
        $this->assertEquals(700, $t['overdue_value']);
    }

    public function test_collection_is_measured_separately_from_billing(): void
    {
        // An invoice billed in one month and paid in another belongs to the
        // first month's billing and the second month's collection. Reading the
        // payment off the invoice would credit it to the wrong period.
        $c = $this->client('Acme');
        $id = $this->invoice(['client_id' => $c, 'date' => now()->subMonths(2)->toDateString(), 'total' => 900, 'paid' => 900, 'balance' => 0]);
        DB::table('sales_payments')->insert([
            'tenant_id' => self::TENANT, 'invoice_id' => $id, 'date' => now()->toDateString(),
            'amount' => 900, 'created_by' => $this->creator()->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // This month: nothing billed, but 900 collected.
        $thisMonth = $this->salesReport(['from' => now()->startOfMonth()->toDateString()]);
        $this->assertEquals(0, $thisMonth['totals']['billed']);
        $this->assertEquals(900, $thisMonth['totals']['collected_in_period']);
    }

    public function test_the_sales_report_groups_by_customer(): void
    {
        $a = $this->client('Acme');
        $b = $this->client('Bravo');
        $this->invoice(['client_id' => $a, 'total' => 1000, 'balance' => 1000]);
        $this->invoice(['client_id' => $a, 'total' => 500, 'balance' => 500]);
        $this->invoice(['client_id' => $b, 'total' => 200, 'balance' => 200]);

        $rows = collect($this->salesReport()['by_customer'])->keyBy('client');

        $this->assertSame(2, $rows['Acme']['invoices']);
        $this->assertEquals(1500, $rows['Acme']['billed']);
        // Sorted by value, so the biggest customer is the first thing read.
        $this->assertSame('Acme', $this->salesReport()['by_customer'][0]['client']);
    }

    public function test_the_sales_report_endpoint_answers(): void
    {
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/sales/reports')->assertOk()->assertJsonStructure([
            'data' => ['totals', 'by_status', 'by_month', 'by_customer', 'by_agent', 'pipeline'],
        ]);
    }

    /* ── Help Desk ──────────────────────────────────────────────────────── */

    public function test_the_helpdesk_report_counts_volume_and_outcome(): void
    {
        $this->ticket(['status' => 'Open']);
        $this->ticket(['status' => 'Resolved', 'resolved_at' => now()->subDay()]);
        $this->ticket(['status' => 'Resolved', 'resolved_at' => now(), 'reopened_count' => 2]);

        $t = $this->helpdeskReport()['totals'];

        $this->assertSame(3, $t['tickets']);
        $this->assertSame(2, $t['resolved']);
        $this->assertSame(1, $t['open']);
        $this->assertSame(1, $t['reopened']);
        $this->assertEquals(66.7, $t['resolution_rate']);
    }

    public function test_an_unanswered_ticket_is_counted_not_averaged_as_zero(): void
    {
        // Averaging an unanswered ticket in as a zero would report the worst
        // cases as the best ones. It is counted separately instead — and that
        // count is the point.
        $this->ticket(['created_at' => now()->subHours(4), 'first_responded_at' => now()->subHours(3)]); // 60 min
        $this->ticket(['created_at' => now()->subHours(4)]);                                             // never answered

        $t = $this->helpdeskReport()['totals'];

        $this->assertSame(60, $t['avg_response_mins'], 'the average covers only answered tickets');
        $this->assertSame(1, $t['answered_count']);
        $this->assertSame(1, $t['never_answered']);
    }

    public function test_a_merged_ticket_is_not_counted_twice(): void
    {
        // A merged ticket is not its own piece of work; counting it would double
        // every merged conversation.
        $keep = $this->ticket([]);
        $this->ticket(['merged_into_id' => $keep]);

        $this->assertSame(1, $this->helpdeskReport()['totals']['tickets']);
    }

    public function test_a_breach_is_a_missed_promise_either_way(): void
    {
        // Past due and unresolved, or resolved after the due date — both missed.
        $this->ticket(['due_date' => now()->subDay(), 'status' => 'Open']);
        $this->ticket(['due_date' => now()->subDays(3), 'resolved_at' => now()->subDay(), 'status' => 'Resolved']);
        // Resolved before its due date — kept.
        $this->ticket(['due_date' => now()->addDay(), 'resolved_at' => now(), 'status' => 'Resolved']);

        $this->assertSame(2, $this->helpdeskReport()['totals']['sla_breached']);
    }

    public function test_the_helpdesk_report_groups_by_agent_and_priority(): void
    {
        $agent = $this->admin();
        $this->ticket(['assigned_to' => $agent->id, 'priority' => 'High']);
        $this->ticket(['assigned_to' => $agent->id, 'priority' => 'Low']);
        $this->ticket(['priority' => 'High']);

        $report = $this->helpdeskReport();

        $byAgent = collect($report['by_agent'])->keyBy('agent');
        $this->assertSame(2, $byAgent[$agent->name]['tickets']);
        $this->assertSame(1, $byAgent['Unassigned']['tickets']);

        $byPriority = collect($report['by_priority'])->keyBy('priority');
        $this->assertSame(2, $byPriority['High']['tickets']);
    }

    public function test_the_helpdesk_report_endpoint_answers(): void
    {
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/helpdesk/reports')->assertOk()->assertJsonStructure([
            'data' => ['totals', 'by_status', 'by_priority', 'by_agent', 'by_department', 'by_month'],
        ]);
    }

    /* ── Both are tenant-scoped ─────────────────────────────────────────── */

    public function test_neither_report_reads_another_tenants_data(): void
    {
        (new Tenant)->forceFill([
            'id' => 2, 'name' => 'T2', 'slug' => 't2', 'subdomain' => 't2', 'status' => 'active',
        ])->save();

        DB::table('sales_invoices')->insert([
            'tenant_id' => 2, 'number' => 'OTHER-1', 'date' => now()->toDateString(),
            'due_date' => now()->addDays(10)->toDateString(),
            'total' => 9999, 'paid' => 0, 'balance' => 9999, 'status' => 'Unpaid',
            'created_by' => $this->creator()->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('tickets')->insert([
            'tenant_id' => 2, 'subject' => 'Theirs', 'status' => 'Open',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(0, $this->salesReport()['totals']['invoices']);
        $this->assertSame(0, $this->helpdeskReport()['totals']['tickets']);
    }
}
