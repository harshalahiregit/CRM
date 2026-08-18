<?php

namespace Tests\Feature\Tpv;

use App\Models\Helpdesk\Ticket;
use App\Models\Project\Project;
use App\Models\Project\ProjectExpense;
use App\Models\Tenant;
use App\Models\Tpv\TpvGateScan;
use App\Models\Tpv\TpvSafetyStrike;
use App\Models\Tpv\TpvWorker;
use App\Models\User;
use App\Models\Vendor\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * One boundary net across EVERY active section of the TPV vendor detail sidebar.
 *
 * The per-section suites prove each filter returns the right rows. This proves
 * the properties that must hold for ALL of them at once, so a future section —
 * or a future change to an existing one — cannot quietly drop a guard that its
 * neighbours still keep:
 *
 *   · a portal role never reaches any of them
 *   · an unauthenticated caller never reaches any of them
 *   · another tenant's vendor id yields nothing, never that vendor's data
 *
 * The endpoint list below IS the sidebar's read surface. When a section is
 * activated, it belongs here — that is the point of a table-driven test.
 */
class TpvSidebarBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;
    private const OTHER  = 2;

    /**
     * Every vendor-scoped LIST endpoint behind an active section, as
     * section => URL template with {vendor} standing in for the vendor id.
     *
     * @return array<string, string>
     */
    private function vendorScopedEndpoints(): array
    {
        return [
            'Workforce / Medical / Training' => '/api/tpv/workers?vendor_id={vendor}',
            'Gate Log'                       => '/api/tpv/gate-log?vendor_id={vendor}',
            'Strikes'                        => '/api/tpv/strikes?vendor_id={vendor}',
            'Projects / Customer'            => '/api/projects?vendor_id={vendor}',
            'Ticket'                         => '/api/helpdesk/tickets?vendor_id={vendor}',
            'Expenses'                       => '/api/projects/expenses?vendor_id={vendor}',
        ];
    }

    /**
     * Endpoints that take the vendor through ROUTE-MODEL BINDING rather than a
     * filter. These must reject a foreign vendor outright, not return an empty
     * list — the binding is the guard.
     *
     * @return array<string, string>
     */
    private function boundEndpoints(): array
    {
        return [
            'Overview / Profile' => '/api/vendors/{vendor}',
            'Contact'            => '/api/tpv/vendors/{vendor}/contacts',
            'Documents'          => '/api/tpv/vendors/{vendor}/documents',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::TENANT => 't1', self::OTHER => 't2'] as $id => $slug) {
            (new Tenant())->forceFill([
                'id' => $id, 'name' => strtoupper($slug), 'slug' => $slug,
                'subdomain' => $slug, 'status' => 'active',
            ])->save();
        }
    }

    private function user(string $role, int $tenantId = self::TENANT): User
    {
        return User::create([
            'tenant_id' => $tenantId, 'name' => ucfirst($role), 'role' => $role,
            'email' => $role.'-'.Str::random(6).'@test.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function vendor(string $name, int $tenantId = self::TENANT): Vendor
    {
        return Vendor::create([
            'tenant_id' => $tenantId, 'company_name' => $name,
            'email' => strtolower($name).'@test.local', 'status' => 'Active',
        ]);
    }

    private function url(string $template, int $vendorId): string
    {
        return str_replace('{vendor}', (string) $vendorId, $template);
    }

    /**
     * One real row behind EVERY vendor-scoped section, so a cross-tenant read has
     * something it could wrongly return.
     *
     * Without this the isolation test is a false green: an empty tenant answers
     * "0 rows" whether the guard works or not. Seeding first means removing a
     * tenant scope anywhere makes the test fail, which is the only version of it
     * worth having.
     */
    private function seedEverySection(Vendor $vendor, string $marker): void
    {
        $tenantId = (int) $vendor->tenant_id;
        $admin    = $this->user('admin', $tenantId);

        $worker = TpvWorker::create([
            'tenant_id' => $tenantId, 'vendor_id' => $vendor->id,
            'name' => $marker.' Worker', 'worker_code' => 'W-'.Str::random(5), 'status' => 'Active',
        ]);

        TpvGateScan::create([
            'tenant_id' => $tenantId, 'tpv_worker_id' => $worker->id,
            'decision' => 'admit', 'scanned_at' => now(),
        ]);

        TpvSafetyStrike::create([
            'tenant_id' => $tenantId, 'tpv_worker_id' => $worker->id,
            'severity' => 'minor', 'reason' => $marker.' Strike', 'occurred_at' => now(),
        ]);

        $project = (new Project())->forceFill([
            'tenant_id'  => $tenantId, 'name' => $marker.' Project',
            'status'     => 'in_progress', 'progress' => 0, 'start_date' => '2026-01-01',
            'created_by' => $admin->id, 'vendor_id' => $vendor->id, 'link_type' => 'tpv_vendor',
        ]);
        $project->save();

        (new Ticket())->forceFill([
            'tenant_id' => $tenantId, 'subject' => $marker.' Ticket', 'status' => 'open',
            'priority'  => 'medium', 'project_id' => $project->id, 'created_by' => $admin->id,
        ])->save();

        ProjectExpense::create([
            'tenant_id' => $tenantId, 'project_id' => $project->id, 'title' => $marker.' Expense',
            'category'  => 'Travel', 'amount' => 100, 'expense_date' => '2026-02-01',
            'billable'  => true, 'created_by' => $admin->id,
        ]);
    }

    /** No portal role may read any section of an admin vendor screen. */
    public function test_no_active_section_is_readable_by_a_portal_role(): void
    {
        $v = $this->vendor('DenyCo');
        $all = $this->vendorScopedEndpoints() + $this->boundEndpoints();

        foreach (['third_party_vendor', 'vendor', 'client'] as $role) {
            Sanctum::actingAs($this->user($role));

            foreach ($all as $section => $template) {
                $status = $this->getJson($this->url($template, $v->id))->getStatusCode();

                $this->assertContains($status, [403, 404], "{$section} leaked to role {$role} (HTTP {$status}).");
            }
        }
    }

    public function test_no_active_section_is_readable_without_authentication(): void
    {
        $v = $this->vendor('GuestCo');

        foreach ($this->vendorScopedEndpoints() + $this->boundEndpoints() as $section => $template) {
            $this->getJson($this->url($template, $v->id))
                ->assertUnauthorized("{$section} answered an unauthenticated caller.");
        }
    }

    /**
     * The cross-tenant property. An admin of tenant 2 asking for tenant 1's
     * vendor must get nothing — not tenant 1's rows, and not an error that
     * confirms the vendor exists.
     */
    public function test_a_foreign_tenants_vendor_id_yields_no_rows(): void
    {
        $mine = $this->vendor('MineCo');                     // tenant 1
        $this->seedEverySection($mine, 'Mine');              // …with real rows to leak
        $them = $this->user('admin', self::OTHER);           // admin of tenant 2

        foreach ($this->vendorScopedEndpoints() as $section => $template) {
            Sanctum::actingAs($them);

            $body = $this->getJson($this->url($template, $mine->id))->assertOk()->json();
            $rows = $body['data'] ?? $body;
            // Expenses answers { rows, total }; the list endpoints answer a list.
            $rows = $rows['rows'] ?? $rows;

            $this->assertCount(0, $rows, "{$section} returned another tenant's data.");
        }
    }

    /**
     * The same seeded data, read by its OWN tenant, must come back — otherwise the
     * isolation test above could pass simply because the endpoints are broken.
     */
    public function test_the_seeded_sections_are_visible_to_their_own_tenant(): void
    {
        $mine = $this->vendor('OwnCo');
        $this->seedEverySection($mine, 'Own');

        foreach ($this->vendorScopedEndpoints() as $section => $template) {
            Sanctum::actingAs($this->user('admin'));

            $body = $this->getJson($this->url($template, $mine->id))->assertOk()->json();
            $rows = $body['data'] ?? $body;
            $rows = $rows['rows'] ?? $rows;

            $this->assertNotEmpty($rows, "{$section} returned nothing for its own tenant — the isolation test above would be vacuous.");
        }
    }

    /** Route-model-bound sections must refuse a foreign vendor, not scope it away. */
    public function test_route_bound_sections_reject_a_foreign_tenants_vendor(): void
    {
        $mine = $this->vendor('BoundCo');                    // tenant 1
        $them = $this->user('admin', self::OTHER);

        foreach ($this->boundEndpoints() as $section => $template) {
            Sanctum::actingAs($them);

            $status = $this->getJson($this->url($template, $mine->id))->getStatusCode();

            $this->assertContains($status, [403, 404], "{$section} exposed another tenant's vendor (HTTP {$status}).");
        }
    }

    /** A vendor id that does not exist must behave like one with no rows. */
    public function test_an_unknown_vendor_id_is_handled_cleanly(): void
    {
        Sanctum::actingAs($this->user('admin'));

        foreach ($this->vendorScopedEndpoints() as $section => $template) {
            $body = $this->getJson($this->url($template, 999999))->assertOk()->json();
            $rows = $body['data'] ?? $body;
            $rows = $rows['rows'] ?? $rows;

            $this->assertCount(0, $rows, "{$section} invented rows for an unknown vendor.");
        }
    }
}
