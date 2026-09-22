<?php

namespace Tests\Feature\Sire;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * SIRE — the register names who filed the issue (SIR-000026).
 *
 * The list showed an Assignee column and no Reporter one, so the single person
 * who could answer a question about an issue -- the one who actually hit it --
 * was the one name the register left out. You had to open the issue to find out
 * who to go and ask.
 *
 * The query was already eager-loading `reporter:id,name`; only the column was
 * missing. This holds down the half that a client-side change cannot: that the
 * name really arrives in the row.
 */
class SireRegisterShowsReporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_register_row_names_the_person_who_filed_the_issue(): void
    {
        $tenant = Tenant::create([
            'name' => 'ACME', 'slug' => 'acme', 'subdomain' => 'acme',
            'plan' => 'professional', 'status' => 'active',
        ]);

        $admin = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Priya Sharma',
            'email' => 'priya@acme.test', 'password' => bcrypt('password'), 'role' => 'admin',
        ]);

        $this->artisan('sire:seed-defaults', ['--tenant' => $tenant->id]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/sire/reports', [
            'title'       => 'Invoice footer has a typo',
            'description' => 'The footer says Invocie instead of Invoice on every PDF.',
            'origin'      => 'internal',
            'submit'      => true,
        ])->assertStatus(201);

        $row = $this->getJson('/api/sire/dashboard/register?scope=open')
            ->assertOk()
            ->json('data.0');

        $this->assertSame('Priya Sharma', data_get($row, 'reporter.name'));

        // A NAME, not just an id. The column has to render something a person
        // recognises without a second lookup, which is the whole point of the
        // issue: "go and ask Priya" is actionable, "reporter_id 4" is not.
        // (The row does carry reporter_id as an ordinary model attribute; the
        // redaction boundary that strips ids applies to the AI payload, not to
        // the register the user is already authorised to read.)
        $this->assertNotEmpty(data_get($row, 'reporter.name'));
    }
}
