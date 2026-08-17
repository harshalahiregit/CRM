<?php

namespace Tests\Feature\Tpv;

use App\Models\Customer\Client;
use App\Models\Project\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Customers section of the TPV vendor detail page.
 *
 * No customer is linked to a vendor anywhere in the schema. The two parties meet
 * on a PROJECT — projects.vendor_id (the vendor) and projects.customer_id (the
 * client) — so "who does this vendor work for" is answered by the vendor's own
 * projects, and the section adds no endpoint of its own.
 *
 * That makes the contract these tests defend a narrow one: GET /projects?vendor_id=
 * must keep returning each row decorated with its RESOLVED customer. If that
 * decoration ever disappears the section silently empties, which no existing test
 * would have caught — the Projects tab never reads it.
 *
 * (clients.vendor_id exists but is a reserved placeholder: no FK, never written,
 * never read. It is deliberately NOT used here.)
 */
class TpvVendorCustomersTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;
    private const OTHER  = 2;

    /** @var array<int, User> */
    private array $creators = [];

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

    private function creator(int $tenantId): User
    {
        return $this->creators[$tenantId] ??= $this->user('admin', $tenantId);
    }

    private function vendor(string $name, int $tenantId = self::TENANT): Vendor
    {
        return Vendor::create([
            'tenant_id' => $tenantId, 'company_name' => $name,
            'email' => strtolower($name).'@test.local', 'status' => 'Active',
        ]);
    }

    private function client(string $company, int $tenantId = self::TENANT): Client
    {
        return Client::create(['tenant_id' => $tenantId, 'company' => $company, 'active' => 1]);
    }

    private function project(string $name, ?int $vendorId, ?int $customerId, int $tenantId = self::TENANT): Project
    {
        $p = (new Project())->forceFill([
            'tenant_id'   => $tenantId,
            'name'        => $name,
            'status'      => 'in_progress',
            'progress'    => 0,
            'start_date'  => '2026-01-01',
            'created_by'  => $this->creator($tenantId)->id,
            'vendor_id'   => $vendorId,
            'link_type'   => $vendorId ? 'tpv_vendor' : null,
            'customer_id' => $customerId,
        ]);
        $p->save();

        return $p;
    }

    /** @return array<int, array> the project rows the Customers panel reads */
    private function projectRows(int $vendorId, ?User $as = null): array
    {
        Sanctum::actingAs($as ?? $this->user('admin'));

        return $this->getJson('/api/projects?vendor_id='.$vendorId)->assertOk()->json('data');
    }

    /** The contract the section is built on: rows carry a resolved customer. */
    public function test_vendor_projects_carry_their_resolved_customer(): void
    {
        $v = $this->vendor('AlphaCo');
        $c = $this->client('Acme Industries');
        $this->project('Plant Shutdown', $v->id, $c->id);

        $rows = $this->projectRows($v->id);

        $this->assertCount(1, $rows);
        $this->assertSame($c->id, $rows[0]['customer']['id'] ?? null);
        $this->assertSame('Acme Industries', $rows[0]['customer']['name'] ?? null);
    }

    /** Several projects for one client collapse to a single customer. */
    public function test_one_customer_is_reached_through_several_projects(): void
    {
        $v = $this->vendor('MultiCo');
        $c = $this->client('Acme Industries');
        $this->project('Phase 1', $v->id, $c->id);
        $this->project('Phase 2', $v->id, $c->id);

        $ids = collect($this->projectRows($v->id))->pluck('customer.id')->unique()->values();

        $this->assertSame([$c->id], $ids->all());
    }

    public function test_another_vendors_customer_is_never_returned(): void
    {
        $a  = $this->vendor('VendorA');
        $b  = $this->vendor('VendorB');
        $ca = $this->client('Customer A');
        $cb = $this->client('Customer B');

        $this->project('Project A', $a->id, $ca->id);
        $this->project('Project B', $b->id, $cb->id);

        $this->assertSame([$ca->id], collect($this->projectRows($a->id))->pluck('customer.id')->all());
        $this->assertSame([$cb->id], collect($this->projectRows($b->id))->pluck('customer.id')->all());
    }

    /** An internal project has no client — it must not invent one. */
    public function test_a_project_without_a_customer_resolves_to_null(): void
    {
        $v = $this->vendor('PlainCo');
        $this->project('Internal Work', $v->id, null);

        $rows = $this->projectRows($v->id);

        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['customer']);
    }

    public function test_a_vendor_with_no_projects_has_no_customers(): void
    {
        $busy  = $this->vendor('BusyCo');
        $empty = $this->vendor('EmptyCo');
        $this->project('Busy Work', $busy->id, $this->client('Someone')->id);

        $this->assertCount(0, $this->projectRows($empty->id));
    }

    /**
     * A client id is only meaningful inside its tenant. Resolution must stay
     * tenant-scoped, so a foreign client never leaks through a project row.
     */
    public function test_customer_resolution_is_tenant_scoped(): void
    {
        $v      = $this->vendor('MineCo');
        $mine   = $this->client('My Client');
        $theirs = $this->client('Their Client', self::OTHER);

        $this->project('Mine', $v->id, $mine->id);
        // A project in THIS tenant pointing at the other tenant's client id.
        $this->project('Crossed', $v->id, $theirs->id);

        $names = collect($this->projectRows($v->id))->pluck('customer.name')->filter()->values();

        $this->assertContains('My Client', $names);
        $this->assertNotContains('Their Client', $names, "Another tenant's client must never resolve.");
    }

    /** The section rides the project list, so its gate is the project module's. */
    public function test_portal_roles_cannot_read_vendor_customers(): void
    {
        $v = $this->vendor('DenyCo');
        $this->project('Secret', $v->id, $this->client('Secret Client')->id);

        foreach (['third_party_vendor', 'vendor', 'client'] as $role) {
            Sanctum::actingAs($this->user($role));

            $this->getJson('/api/projects?vendor_id='.$v->id)->assertForbidden();
        }
    }
}
