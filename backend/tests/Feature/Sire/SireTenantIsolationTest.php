<?php

namespace Tests\Feature\Sire;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The two SIRE checks the doctor cannot make, and the install guide refuses to
 * let you skip.
 *
 * 1. TENANT ISOLATION. Tenancy is the only integration point whose failure is
 *    silent: get the strategy wrong and one customer reads another's defect
 *    backlog on a page that looks completely normal. Nothing in the UI would
 *    tell you. So it is asserted here, on every run, rather than checked once
 *    by hand and assumed forever.
 *
 * 2. LOGIN TYPES. SIRE is the INTERNAL engineering track. Customers and vendors
 *    are mapped so they can be denied -- being in a category is what makes the
 *    denial deliberate rather than accidental.
 *
 * These lock in config/sire-host.php. If someone later edits the tenant strategy
 * or the role rosters, this test is what tells them.
 */
class SireTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $slug): Tenant
    {
        return Tenant::create([
            'name'      => strtoupper($slug),
            'slug'      => $slug,
            'subdomain' => $slug,
            'plan'      => 'professional',
            'status'    => 'active',
        ]);
    }

    private function user(Tenant $tenant, string $role): User
    {
        return User::create([
            'tenant_id' => $tenant->id,
            'name'      => ucfirst($role).' of '.$tenant->slug,
            'email'     => $role.'@'.$tenant->slug.'.test',
            'password'  => bcrypt('password'),
            'role'      => $role,
        ]);
    }

    /** The report id tenant A just created. */
    private function createReportAs(User $user): int
    {
        Sanctum::actingAs($user);

        // submit:true is what the Report Issue button actually sends. Without it
        // here, the test exercised a payload no user ever produces -- and missed
        // that store() called a SireWorkflowService::submit() that does not
        // exist, so every real report 500'd.
        $response = $this->postJson('/api/sire/reports', [
            'title'       => 'Invoice page throws on save',
            'description' => 'Clicking Generate Invoice returns a 500 and the invoice is not created.',
            'origin'      => 'internal',
            'submit'      => true,
        ]);

        $response->assertStatus(201);

        // The payload is {"data":{"report":{...}}}. Asserted rather than
        // defaulted: an id of 0 would make the cross-tenant test below request a
        // malformed URL, get its 404 from the router, and pass while proving
        // nothing at all.
        $id = (int) data_get($response->json(), 'data.report.id');
        $this->assertGreaterThan(0, $id, 'Create did not return a report id.');

        return $id;
    }

    public function test_a_tenant_cannot_read_another_tenants_issue(): void
    {
        $acme  = $this->tenant('acme');
        $globex = $this->tenant('globex');

        $reportId = $this->createReportAs($this->user($acme, 'admin'));

        // Tenant B, same role, same endpoint, an id that really exists.
        Sanctum::actingAs($this->user($globex, 'admin'));

        // 404 and not 403: a 403 confirms the id is real, which is itself a
        // small leak of another tenant's data.
        $this->getJson("/api/sire/reports/{$reportId}")->assertStatus(404);
    }

    public function test_a_tenants_own_issue_is_readable(): void
    {
        $acme  = $this->tenant('acme');
        $admin = $this->user($acme, 'admin');

        $reportId = $this->createReportAs($admin);

        Sanctum::actingAs($admin);
        $this->getJson("/api/sire/reports/{$reportId}")->assertStatus(200);
    }

    public function test_internal_staff_may_use_sire(): void
    {
        $acme = $this->tenant('acme');

        Sanctum::actingAs($this->user($acme, 'staff'));
        $this->getJson('/api/sire/dashboard/register')->assertStatus(200);
    }

    #[DataProvider('blockedRoles')]
    public function test_customers_and_vendors_are_blocked(string $role): void
    {
        $acme = $this->tenant('acme');

        Sanctum::actingAs($this->user($acme, $role));
        $this->getJson('/api/sire/dashboard/register')->assertStatus(403);
    }

    public static function blockedRoles(): array
    {
        return [
            'customer'           => ['client'],
            'vendor'             => ['vendor'],
            'third party vendor' => ['third_party_vendor'],
        ];
    }

    /**
     * `doctor` is in no login-type list. SIRE denies a role it was not told
     * about, which is the safe way to be wrong -- and matches this app, whose
     * /app shell already blocks doctors.
     */
    public function test_an_unmapped_role_gets_nothing(): void
    {
        $acme = $this->tenant('acme');

        Sanctum::actingAs($this->user($acme, 'doctor'));
        $this->getJson('/api/sire/dashboard/register')->assertStatus(403);
    }
}
