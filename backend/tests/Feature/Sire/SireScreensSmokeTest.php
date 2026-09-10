<?php

namespace Tests\Feature\Sire;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Every SIRE screen has a data source behind it, and it answers.
 *
 * This is the test that would have caught "I navigate to SIRE and nothing shows".
 * A screen whose endpoint 500s and a screen with genuinely no rows look identical
 * in the browser -- both are blank -- so the difference is asserted here instead
 * of guessed at.
 *
 * Reference data is seeded first, deliberately: with no severities the `triage`
 * transition cannot run at all, which is what made the whole module look dead.
 */
class SireScreensSmokeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create([
            'name' => 'ACME', 'slug' => 'acme', 'subdomain' => 'acme',
            'plan' => 'professional', 'status' => 'active',
        ]);

        $this->admin = User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Admin',
            'email'     => 'admin@acme.test',
            'password'  => bcrypt('password'),
            'role'      => 'admin',
        ]);

        $this->artisan('sire:seed-defaults', ['--tenant' => $tenant->id]);

        Sanctum::actingAs($this->admin);
    }

    public static function screenEndpoints(): array
    {
        return [
            'Dashboard tiles'   => ['/api/sire/dashboard'],
            'Dashboard filters' => ['/api/sire/dashboard/options'],
            'Issue register'    => ['/api/sire/dashboard/register'],
            'My Work (dev)'     => ['/api/sire/queues/development'],
            'My Work (QA)'      => ['/api/sire/queues/qa'],
            'Releases'          => ['/api/sire/releases'],
            'Release board'     => ['/api/sire/release-board'],
            'Release gates'     => ['/api/sire/release-gates'],
            'Quality'           => ['/api/sire/quality'],
            'Recurrence'        => ['/api/sire/recurrence-groups'],
            'CAPA'              => ['/api/sire/capa'],
            'AI status'         => ['/api/sire/ai/status'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('screenEndpoints')]
    public function test_every_screen_endpoint_answers(string $uri): void
    {
        $this->getJson($uri)->assertStatus(200);
    }

    /** The seeder is what makes triage possible; assert it actually produced rows. */
    public function test_reference_data_is_present_and_usable(): void
    {
        $options = $this->getJson('/api/sire/dashboard/options')->assertStatus(200)->json();

        $this->assertNotEmpty(
            data_get($options, 'data.severities', data_get($options, 'severities', [])),
            'No severities: triage requires severity_id, so every report would be stuck in `new`.',
        );
        $this->assertNotEmpty(
            // The filter payload calls them 	ypes, not categories.
            data_get($options, 'data.types', data_get($options, 'types', [])),
            'No report categories: the Report Issue category picker would be empty.',
        );
    }

    /** A report can actually be moved off `new` -- the whole point of the module. */
    public function test_a_report_can_be_triaged(): void
    {
        $created = $this->postJson('/api/sire/reports', [
            'title'       => 'Invoice page throws on save',
            'description' => 'Clicking Generate Invoice returns a 500 and the invoice is not created.',
            'origin'      => 'internal',
            'submit'      => true,
        ])->assertStatus(201)->json();

        $id = (int) data_get($created, 'data.report.id');
        $this->assertGreaterThan(0, $id);

        $severityId = (int) \Illuminate\Support\Facades\DB::table('sire_severities')
            ->where('tenant_id', $this->admin->tenant_id)->where('code', 's2')->value('id');
        $this->assertGreaterThan(0, $severityId, 'Seeder did not create severity s2.');

        $this->postJson("/api/sire/reports/{$id}/transitions", [
            'action'      => 'triage',
            'severity_id' => $severityId,
            'priority'    => 'p2',
        ])->assertStatus(200);

        $this->assertSame('triaged', \Illuminate\Support\Facades\DB::table('sire_reports')->where('id', $id)->value('status'));
    }
}