<?php

namespace Tests\Feature\Customer;

use App\Models\Customer\Client;
use App\Models\Customer\ClientActivity;
use App\Models\Customer\ClientComplaint;
use App\Models\Customer\ClientVaultEntry;
use App\Models\Sales\SalesInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Shared\KickoffSubject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * §5 Timeline, §6 linked modules, §3 meetings and §15 the vault audit trail.
 *
 * The timeline reads seven modules. What matters is that it stays inside one
 * customer and one tenant while doing so, and that a module being absent costs
 * it that source rather than the whole screen.
 */
class CustomerTimelineTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Client $client;
    private User $user;

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
        $this->user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Admin', 'email' => 'a@x.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'active',
        ]);
        Sanctum::actingAs($this->user);
    }

    private function activity(string $subject, string $when): void
    {
        ClientActivity::create([
            'tenant_id' => $this->tenant->id, 'client_id' => $this->client->id,
            'type' => 'Call', 'subject' => $subject, 'occurred_at' => $when,
        ]);
    }

    // ── §5 Timeline ──────────────────────────────────────────────────────────

    public function test_the_timeline_gathers_events_from_several_modules(): void
    {
        $this->activity('Chased the PO', now()->subDays(2));
        ClientComplaint::create([
            'tenant_id' => $this->tenant->id, 'client_id' => $this->client->id,
            'kind' => 'Complaint', 'subject' => 'Late', 'raised_at' => now()->subDay(),
        ]);
        SalesInvoice::create([
            'tenant_id' => $this->tenant->id, 'client_id' => $this->client->id,
            'number' => 'INV-1', 'date' => now(), 'due_date' => now()->addDays(30),
            'status' => 'Unpaid', 'total' => 100, 'balance' => 100, 'created_by' => $this->user->id,
        ]);

        $types = collect($this->getJson("/api/customers/{$this->client->id}/timeline")->assertOk()->json('days'))
            ->flatMap(fn ($d) => collect($d['events'])->pluck('type'))->unique()->sort()->values();

        $this->assertEquals(['activity', 'complaint', 'invoice'], $types->all());
    }

    public function test_events_are_grouped_by_day_newest_first(): void
    {
        $this->activity('older', now()->subDays(3));
        $this->activity('newer', now()->subDay());

        $days = $this->getJson("/api/customers/{$this->client->id}/timeline")->json('days');

        $this->assertCount(2, $days);
        $this->assertTrue($days[0]['date'] > $days[1]['date']);
    }

    public function test_another_customers_events_never_appear(): void
    {
        $other = Client::create(['tenant_id' => $this->tenant->id, 'company' => 'Other Ltd', 'active' => true]);
        ClientActivity::create([
            'tenant_id' => $this->tenant->id, 'client_id' => $other->id,
            'type' => 'Call', 'subject' => 'THEIRS', 'occurred_at' => now(),
        ]);
        $this->activity('MINE', now());

        $labels = collect($this->getJson("/api/customers/{$this->client->id}/timeline")->json('days'))
            ->flatMap(fn ($d) => collect($d['events'])->pluck('label'))->implode(' ');

        $this->assertStringContainsString('MINE', $labels);
        $this->assertStringNotContainsString('THEIRS', $labels);
    }

    public function test_a_date_window_bounds_the_result(): void
    {
        $this->activity('inside', now()->subDays(2));
        $this->activity('outside', now()->subDays(40));

        $labels = collect($this->getJson(
            "/api/customers/{$this->client->id}/timeline?from=".now()->subWeek()->toDateString()
        )->json('days'))->flatMap(fn ($d) => collect($d['events'])->pluck('label'))->implode(' ');

        $this->assertStringContainsString('inside', $labels);
        $this->assertStringNotContainsString('outside', $labels);
    }

    public function test_counts_are_taken_before_the_type_filter(): void
    {
        $this->activity('a call', now());
        ClientComplaint::create([
            'tenant_id' => $this->tenant->id, 'client_id' => $this->client->id,
            'kind' => 'Complaint', 'subject' => 'Late', 'raised_at' => now(),
        ]);

        $res = $this->getJson("/api/customers/{$this->client->id}/timeline?types=activity")->assertOk();

        // Filtered down to activities, but the chips still know a complaint exists.
        $this->assertSame(1, $res->json('total'));
        $this->assertSame(1, $res->json('counts.complaint'));
    }

    public function test_every_event_carries_a_category(): void
    {
        $this->activity('x', now());

        $event = $this->getJson("/api/customers/{$this->client->id}/timeline")->json('days.0.events.0');

        $this->assertSame('relationship', $event['category']);
    }

    // ── §6 linked modules ────────────────────────────────────────────────────

    public function test_linked_endpoints_are_read_only_and_name_their_owner(): void
    {
        foreach (['projects' => 'Projects', 'tasks' => 'Tasks', 'delivery-notes' => 'Delivery Notes'] as $slug => $owner) {
            $res = $this->getJson("/api/customers/{$this->client->id}/linked/{$slug}")->assertOk();
            $this->assertTrue($res->json('read_only'));
            $this->assertSame($owner, $res->json('owned_by'));
        }
    }

    public function test_a_missing_module_yields_an_empty_set_rather_than_an_error(): void
    {
        \Illuminate\Support\Facades\Schema::drop('projects');

        $res = $this->getJson("/api/customers/{$this->client->id}/linked/projects")->assertOk();

        $this->assertTrue($res->json('unavailable'));
        $this->assertSame([], $res->json('rows'));
    }

    // ── §3 meetings ──────────────────────────────────────────────────────────

    public function test_a_customer_is_a_valid_meeting_subject(): void
    {
        $this->assertTrue(KickoffSubject::isValid(KickoffSubject::CUSTOMER));
        $this->assertSame(Client::class, KickoffSubject::classFor(KickoffSubject::CUSTOMER));
        $this->assertSame('Widget Ltd', KickoffSubject::nameOf($this->client));
    }

    public function test_meetings_attached_to_this_customer_are_listed(): void
    {
        $meetingId = DB::table('kickoff_meetings')->insertGetId([
            'tenant_id' => $this->tenant->id, 'title' => 'Quarterly Business Review',
            'status' => 'scheduled', 'scheduled_at' => now()->addWeek(),
            'created_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('kickoff_meeting_subjects')->insert([
            'tenant_id' => $this->tenant->id, 'kickoff_meeting_id' => $meetingId,
            'subject_type' => Client::class, 'subject_id' => $this->client->id,
            'is_primary' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $res = $this->getJson("/api/customers/{$this->client->id}/linked/meetings")->assertOk();

        $this->assertCount(1, $res->json('rows'));
        $this->assertSame('Quarterly Business Review', $res->json('rows.0.title'));
        $this->assertSame('customer', $res->json('subject_type'));
    }

    // ── §15 vault audit trail ────────────────────────────────────────────────

    public function test_revealing_a_credential_is_recorded(): void
    {
        $entry = ClientVaultEntry::create([
            'tenant_id' => $this->tenant->id, 'client_id' => $this->client->id,
            'title' => 'SFTP', 'password' => 'hunter2',
            'visibility' => ClientVaultEntry::VISIBILITY_ALL, 'created_by' => $this->user->id,
        ]);

        // The vault now asks who you are before it opens — the re-authentication
        // the legacy CRM required and the port had dropped.
        $this->postJson('/api/customers/vault/unlock', ['password' => 'x'])->assertOk();

        $this->postJson("/api/customers/{$this->client->id}/vault/{$entry->id}/reveal")->assertOk();

        $log = $this->getJson("/api/customers/{$this->client->id}/vault/{$entry->id}/access-log")
            ->assertOk()->json();

        $this->assertSame('revealed', $log[0]['action']);
        $this->assertSame($this->user->id, $log[0]['user_id']);
    }

    public function test_listing_the_vault_is_not_logged(): void
    {
        // Created through the API so the trail has a 'created' row to be the
        // ONLY row — proving the list below added nothing rather than proving
        // the log happened to be empty.
        $id = $this->postJson("/api/customers/{$this->client->id}/vault", [
            'title' => 'SFTP', 'password' => 'hunter2',
            'visibility' => ClientVaultEntry::VISIBILITY_ALL,
        ])->assertCreated()->json('id');

        $entry = ClientVaultEntry::findOrFail($id);

        $this->getJson("/api/customers/{$this->client->id}/vault")->assertOk();

        // A list never contains a password, so a page view discloses nothing.
        // Logging it would bury the reveals that matter in noise.
        $log = $this->getJson("/api/customers/{$this->client->id}/vault/{$entry->id}/access-log")->json();
        $this->assertSame(['created'], collect($log)->pluck('action')->all());
    }

    public function test_a_non_administrator_cannot_read_the_audit_trail(): void
    {
        $entry = ClientVaultEntry::create([
            'tenant_id' => $this->tenant->id, 'client_id' => $this->client->id,
            'title' => 'SFTP', 'password' => 'hunter2',
            'visibility' => ClientVaultEntry::VISIBILITY_ALL, 'created_by' => $this->user->id,
        ]);

        Sanctum::actingAs(User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Staff', 'email' => 's@x.test',
            'password' => bcrypt('x'), 'role' => 'staff', 'status' => 'active',
        ]));

        // Who looked at which credential is itself sensitive.
        $this->getJson("/api/customers/{$this->client->id}/vault/{$entry->id}/access-log")->assertStatus(403);
    }
}
