<?php

namespace Tests\Feature\Customer;

use App\Models\Customer\Client;
use App\Models\Sales\SalesInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Customer\Customer360Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Overview tiles and Health must agree with the tabs beside them.
 *
 * Both read other modules with bare DB::table(), which bypasses SoftDeletes.
 * A tile counting deleted rows while the tab below it does not is a number
 * nobody can reconcile, and the worst case is quiet: soft-deleting one PROJECT
 * strands every live task on it — the tile keeps counting those tasks, the tab
 * drops them all at once, and nothing on screen explains the jump.
 */
class OverviewSoftDeleteTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Client $client;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Acme', 'slug' => 'acme', 'subdomain' => 'acme',
            'plan' => 'professional', 'status' => 'active',
        ]);
        $this->client = Client::create([
            'tenant_id' => $this->tenant->id, 'company' => 'Widget Ltd', 'active' => true,
        ]);
        $this->staff = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Admin', 'email' => 'a@x.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'active',
        ]);
        Sanctum::actingAs($this->staff);
    }

    private function kpi(string $key): int
    {
        $kpis = app(Customer360Service::class)->kpis($this->client->fresh());

        return (int) collect($kpis)->firstWhere('key', $key)['value'];
    }

    private function project(string $name, string $status = 'in_progress'): int
    {
        return DB::table('projects')->insertGetId([
            'tenant_id' => $this->tenant->id, 'customer_id' => $this->client->id,
            'name' => $name, 'status' => $status, 'start_date' => now()->toDateString(),
            'created_by' => $this->staff->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function ticket(string $subject, string $status = 'open'): int
    {
        return DB::table('tickets')->insertGetId([
            'tenant_id' => $this->tenant->id, 'customer_id' => $this->client->id,
            'subject' => $subject, 'status' => $status,
            'created_by' => $this->staff->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_a_deleted_project_stops_being_counted(): void
    {
        $this->project('Live one');
        $gone = $this->project('Deleted one');

        $this->assertSame(2, $this->kpi('projects'));

        DB::table('projects')->where('id', $gone)->update(['deleted_at' => now()]);

        $this->assertSame(1, $this->kpi('projects'), 'the tile still counts a deleted project');
    }

    public function test_a_deleted_ticket_stops_being_counted(): void
    {
        $this->ticket('Live');
        $gone = $this->ticket('Deleted');

        $this->assertSame(2, $this->kpi('tickets'));

        DB::table('tickets')->where('id', $gone)->update(['deleted_at' => now()]);

        $this->assertSame(1, $this->kpi('tickets'));
    }

    public function test_deleting_a_project_also_drops_its_tasks_from_the_tile(): void
    {
        $p = $this->project('Doomed');
        foreach (['A', 'B', 'C'] as $t) {
            DB::table('tasks')->insert([
                'tenant_id' => $this->tenant->id, 'name' => "Task {$t}",
                'rel_type' => 'project', 'rel_id' => $p, 'status' => 1,
                'start_date' => now()->toDateString(),
                'created_by' => $this->staff->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $before = $this->kpi('tasks');
        DB::table('projects')->where('id', $p)->update(['deleted_at' => now()]);
        $after = $this->kpi('tasks');

        // The tasks rows are untouched — they are only reachable through the
        // project, so the tile must stop counting them when it goes.
        $this->assertGreaterThan($after, $before, 'tasks on a deleted project are still counted');
        $this->assertSame(0, $after);
    }

    public function test_outstanding_excludes_a_deleted_invoice(): void
    {
        foreach ([['INV-1', 3000], ['INV-2', 5000]] as [$n, $bal]) {
            SalesInvoice::create([
                'tenant_id' => $this->tenant->id, 'client_id' => $this->client->id,
                'number' => $n, 'date' => now(), 'due_date' => now()->addDays(30),
                'status' => 'Unpaid', 'total' => $bal, 'paid' => 0, 'balance' => $bal,
                'created_by' => $this->staff->id,
            ]);
        }
        SalesInvoice::where('number', 'INV-2')->first()->delete();

        $kpis = app(Customer360Service::class)->kpis($this->client->fresh());
        $this->assertSame(3000.0, (float) collect($kpis)->firstWhere('key', 'outstanding')['value']);
    }

    public function test_health_ignores_deleted_tickets(): void
    {
        $this->ticket('Live', 'open');
        $gone = $this->ticket('Deleted', 'open');
        DB::table('tickets')->where('id', $gone)->update(['deleted_at' => now()]);

        $b = collect(app(\App\Services\Customer\CustomerHealthService::class)
            ->score($this->client->fresh())['breakdown'])->firstWhere('key', 'ticket_volume');

        // "1 open of 1 tickets", not 2 — a deleted ticket must not drag the
        // score down for a customer whose issue was withdrawn.
        $this->assertStringContainsString('of 1', $b['detail']);
    }
}
