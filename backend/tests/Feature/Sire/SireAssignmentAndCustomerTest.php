<?php

namespace Tests\Feature\Sire;

use App\Models\Customer\Client;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Sire\Contracts\SireSettingsProvider;
use Sire\Models\Report;
use Tests\TestCase;

/**
 * Two things the issue page could not do.
 *
 * ASSIGNMENT. The picker was fed from `sire.roles.leads` / `.developers` / `.qa`
 * and nothing else. Those are settings keys with no screen behind them, so a
 * workspace that never ran the seeder saw an empty dropdown, and one that had
 * could not add a new joiner without somebody editing a settings row by hand.
 * The directory is the source now; the rosters group it.
 *
 * CUSTOMERS. "Which customers are hitting this" had no answer at all. A P3
 * nobody mentioned and a P3 three customers raised are not the same defect.
 */
class SireAssignmentAndCustomerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->admin = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'role' => 'admin', 'name' => 'Asha Lead',
        ]);
        $this->staff = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'role' => 'staff', 'name' => 'Dev Kumar',
        ]);
    }

    private function assignees(): array
    {
        Sanctum::actingAs($this->admin);

        return $this->getJson('/api/sire/dashboard/options')->assertOk()->json('data.assignees');
    }

    // ------------------------------------------------------------- assignment

    public function test_the_picker_works_with_no_rosters_configured_at_all(): void
    {
        // The state a fresh workspace is in, and the one that made the dropdown
        // look broken. Nothing is seeded here on purpose.
        $names = collect($this->assignees())->pluck('display_name');

        $this->assertTrue($names->contains('Asha Lead'));
        $this->assertTrue($names->contains('Dev Kumar'));
    }

    public function test_a_new_joiner_is_assignable_without_touching_settings(): void
    {
        $joiner = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'role' => 'staff', 'name' => 'Nina Newstart',
        ]);

        $this->assertTrue(
            collect($this->assignees())->pluck('id')->contains($joiner->id),
            'somebody who joined today must be assignable today',
        );
    }

    public function test_the_rosters_still_say_who_is_engineering(): void
    {
        app(SireSettingsProvider::class)
            ->set($this->tenant->id, 'sire.roles.developers', [$this->staff->id]);

        $rows = collect($this->assignees())->keyBy('id');

        $this->assertTrue($rows[$this->staff->id]['is_engineer']);
        $this->assertSame(['developers'], $rows[$this->staff->id]['rosters']);

        // Not on a roster, still assignable -- the roster describes, it no
        // longer gates.
        $this->assertFalse($rows[$this->admin->id]['is_engineer']);

        // And engineering leads the list, so a large workspace still reads well.
        $this->assertSame($this->staff->id, collect($this->assignees())->first()['id']);
    }

    public function test_customers_are_offered_separately_and_vendors_not_at_all(): void
    {
        User::factory()->create([
            'tenant_id' => $this->tenant->id, 'role' => 'client', 'name' => 'A Customer',
        ]);
        User::factory()->create([
            'tenant_id' => $this->tenant->id, 'role' => 'vendor', 'name' => 'A Vendor',
        ]);

        $rows = collect($this->assignees());

        // A customer contact can own the conversation about an issue they
        // raised, so they are offered -- under their own heading, because a flat
        // dropdown of both is how a production defect gets assigned to a client
        // by mistake.
        $customer = $rows->firstWhere('display_name', 'A Customer');
        $this->assertNotNull($customer);
        $this->assertSame('customer', $customer['kind']);

        // Vendors are not part of this conversation at all.
        $this->assertNull($rows->firstWhere('display_name', 'A Vendor'));
    }

    public function test_the_directory_never_crosses_a_tenant(): void
    {
        $other = Tenant::factory()->create();
        User::factory()->create([
            'tenant_id' => $other->id, 'role' => 'staff', 'name' => 'Someone Elses Dev',
        ]);

        $this->assertFalse(
            collect($this->assignees())->pluck('display_name')->contains('Someone Elses Dev'),
        );
    }

    public function test_assignment_still_works_end_to_end(): void
    {
        Sanctum::actingAs($this->admin);

        $id = $this->postJson('/api/sire/reports', [
            'title'       => 'Saving a lead returns a 500',
            'description' => 'The spinner runs and then nothing happens at all.',
        ])->assertCreated()->json('data.report.id');

        $severity = \Sire\Models\ReportSeverity::factory()->create([
            'tenant_id' => $this->tenant->id, 'code' => 's2', 'name' => 'High', 'level' => 3,
        ]);

        $this->postJson("/api/sire/reports/{$id}/transitions", [
            'action' => 'triage', 'severity_id' => $severity->id, 'priority' => 'p2',
        ])->assertOk();

        $this->postJson("/api/sire/reports/{$id}/transitions", [
            'action' => 'assign', 'assignee_id' => $this->staff->id,
        ])->assertOk();

        $this->assertSame($this->staff->id, (int) Report::findOrFail($id)->assignee_id);
    }

    // -------------------------------------------------------------- customers

    private function client(string $company): Client
    {
        return Client::create(['tenant_id' => $this->tenant->id, 'company' => $company]);
    }

    public function test_the_directory_is_searchable_from_sire(): void
    {
        $this->client('Acme Industries');
        $this->client('Zenith Logistics');

        Sanctum::actingAs($this->admin);
        $data = $this->getJson('/api/sire/customers?q=acme')->assertOk()->json('data');

        $this->assertTrue($data['available']);
        $this->assertSame(['Acme Industries'], collect($data['customers'])->pluck('name')->all());

        // A deep link back to the record the name came from.
        $this->assertStringStartsWith('/app/customers/', $data['customers'][0]['url']);
    }

    public function test_an_issue_can_name_the_customer_it_affects(): void
    {
        $client = $this->client('Acme Industries');

        Sanctum::actingAs($this->admin);
        $id = $this->postJson('/api/sire/reports', [
            'title'       => 'Invoice total ignores tax on credit notes',
            'description' => 'Acme spotted it on their November statement run.',
        ])->assertCreated()->json('data.report.id');

        $this->putJson("/api/sire/reports/{$id}/customer", ['customer_id' => $client->id])
            ->assertOk()
            ->assertJsonPath('data.customer.name', 'Acme Industries');

        // And it comes back on the issue, resolved through the provider.
        $this->assertSame(
            'Acme Industries',
            $this->getJson("/api/sire/reports/{$id}")->assertOk()->json('data.customer.name'),
        );
    }

    public function test_the_customer_can_be_cleared(): void
    {
        $client = $this->client('Acme Industries');

        Sanctum::actingAs($this->admin);
        $id = $this->postJson('/api/sire/reports', [
            'title'       => 'Invoice total ignores tax on credit notes',
            'description' => 'Acme spotted it on their November statement run.',
        ])->assertCreated()->json('data.report.id');

        $this->putJson("/api/sire/reports/{$id}/customer", ['customer_id' => $client->id])->assertOk();
        $this->putJson("/api/sire/reports/{$id}/customer", ['customer_id' => null])->assertOk();

        $this->assertNull(Report::findOrFail($id)->customer_id);
    }

    public function test_another_tenants_customer_cannot_be_attached(): void
    {
        $theirs = Client::create([
            'tenant_id' => Tenant::factory()->create()->id, 'company' => 'Not Yours Ltd',
        ]);

        Sanctum::actingAs($this->admin);
        $id = $this->postJson('/api/sire/reports', [
            'title'       => 'Invoice total ignores tax on credit notes',
            'description' => 'The spinner runs and then nothing happens at all.',
        ])->assertCreated()->json('data.report.id');

        // 422, and nothing stored: a guessed id must not confirm that the
        // customer exists, nor attach one.
        $this->putJson("/api/sire/reports/{$id}/customer", ['customer_id' => $theirs->id])
            ->assertStatus(422);

        $this->assertNull(Report::findOrFail($id)->customer_id);
    }

    public function test_setting_a_customer_needs_the_triage_capability(): void
    {
        $client = $this->client('Acme Industries');

        Sanctum::actingAs($this->admin);
        $id = $this->postJson('/api/sire/reports', [
            'title'       => 'Invoice total ignores tax on credit notes',
            'description' => 'The spinner runs and then nothing happens at all.',
        ])->assertCreated()->json('data.report.id');

        // Whose problem this is changes how it is prioritised, so it belongs to
        // the people who own the queue.
        Sanctum::actingAs($this->staff);
        $this->putJson("/api/sire/reports/{$id}/customer", ['customer_id' => $client->id])
            ->assertForbidden();
    }

    public function test_a_change_of_customer_is_audited(): void
    {
        $client = $this->client('Acme Industries');

        Sanctum::actingAs($this->admin);
        $id = $this->postJson('/api/sire/reports', [
            'title'       => 'Invoice total ignores tax on credit notes',
            'description' => 'The spinner runs and then nothing happens at all.',
        ])->assertCreated()->json('data.report.id');

        $this->putJson("/api/sire/reports/{$id}/customer", ['customer_id' => $client->id])->assertOk();

        $this->assertDatabaseHas('sire_audit_events', [
            'subject_id' => $id,
            'action'     => 'Affected customer set',
        ]);
    }
}
