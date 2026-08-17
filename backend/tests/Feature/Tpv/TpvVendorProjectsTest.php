<?php

namespace Tests\Feature\Tpv;

use App\Models\Project\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Projects section of the TPV vendor detail page.
 *
 * A project already records its vendor link on the projects table (vendor_id +
 * link_type), so this section is the EXISTING project list read one vendor at a
 * time — GET /projects?vendor_id= — rather than a TPV-side project store. No new
 * table, no new endpoint, so what these tests pin is the filter's precision:
 * it must not leak across vendors, across link types, or across tenants.
 */
class TpvVendorProjectsTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;
    private const OTHER  = 2;

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

    /**
     * Per-tenant project creator. An instance property, never a static — a static
     * would outlive RefreshDatabase and hand the next test a deleted user id.
     *
     * @var array<int, User>
     */
    private array $creators = [];

    private function creator(int $tenantId): User
    {
        return $this->creators[$tenantId] ??= $this->user('admin', $tenantId);
    }

    private function project(string $name, ?Vendor $v, ?string $linkType, int $tenantId = self::TENANT): Project
    {
        return (new Project())->forceFill([
            'tenant_id'  => $tenantId,
            'name'       => $name,
            'status'     => 'In Progress',
            'progress'   => 40,
            'start_date' => '2026-01-01',                 // NOT NULL on projects
            'created_by' => $this->creator($tenantId)->id, // NOT NULL on projects
            'vendor_id'  => $v?->id,
            'link_type'  => $linkType,
        ]);
    }

    /** @return \Illuminate\Support\Collection<int, string> */
    private function listFor(int $vendorId, ?User $as = null)
    {
        Sanctum::actingAs($as ?? $this->user('admin'));

        $res = $this->getJson('/api/projects?vendor_id='.$vendorId)->assertOk();

        return collect($res->json('data') ?? $res->json())->pluck('name');
    }

    public function test_projects_are_scoped_to_the_requested_vendor(): void
    {
        $a = $this->vendor('AlphaCo');
        $b = $this->vendor('BetaCo');
        $this->project('Alpha Plant Shutdown', $a, 'tpv_vendor')->save();
        $this->project('Beta Warehouse Fitout', $b, 'tpv_vendor')->save();

        $names = $this->listFor($a->id);

        $this->assertContains('Alpha Plant Shutdown', $names);
        $this->assertNotContains('Beta Warehouse Fitout', $names, "Another vendor's projects must not appear.");
    }

    /**
     * Rows written before the vendor modules were split say 'tpv'; rows written
     * since say 'tpv_vendor'. Both mean the vendors table, so both must match or
     * a vendor's older projects silently vanish from its screen.
     */
    public function test_both_tpv_link_type_spellings_are_returned(): void
    {
        $v = $this->vendor('LegacyCo');
        $this->project('Legacy Spelling', $v, 'tpv')->save();
        $this->project('Current Spelling', $v, 'tpv_vendor')->save();

        $names = $this->listFor($v->id);

        $this->assertContains('Legacy Spelling', $names);
        $this->assertContains('Current Spelling', $names);
    }

    /**
     * vendor_id is only unique WITHIN a link type — the same integer is a
     * different company under 'purchase_vendor'. Without the link_type guard this
     * screen would show another module's projects.
     */
    public function test_a_purchase_vendor_project_with_the_same_id_is_excluded(): void
    {
        $v = $this->vendor('SharedIdCo');
        $this->project('TPV Work', $v, 'tpv_vendor')->save();

        // Same integer, other module.
        $p = $this->project('Purchase Work', null, 'purchase_vendor');
        $p->vendor_id = $v->id;
        $p->save();

        $names = $this->listFor($v->id);

        $this->assertContains('TPV Work', $names);
        $this->assertNotContains('Purchase Work', $names, 'link_type must partition the vendor id space.');
    }

    /** A project with no vendor link belongs to nobody's vendor screen. */
    public function test_unlinked_projects_are_excluded(): void
    {
        $v = $this->vendor('PlainCo');
        $this->project('Vendor Work', $v, 'tpv_vendor')->save();
        $this->project('Internal Work', null, null)->save();

        $names = $this->listFor($v->id);

        $this->assertContains('Vendor Work', $names);
        $this->assertNotContains('Internal Work', $names);
    }

    /** The filter rides the normal project list, so tenant scoping still applies. */
    public function test_another_tenants_project_is_never_returned(): void
    {
        $mine    = $this->vendor('MineCo');
        $theirs  = $this->vendor('TheirsCo', self::OTHER);

        $this->project('My Project', $mine, 'tpv_vendor')->save();

        // Same vendor id value, other tenant.
        $p = $this->project('Their Project', null, 'tpv_vendor', self::OTHER);
        $p->vendor_id = $mine->id;
        $p->save();

        $this->assertNotContains('Their Project', $this->listFor($mine->id));
        $this->assertNotContains('My Project', $this->listFor($theirs->id, $this->user('admin', self::OTHER)));
    }

    /** A vendor with nothing linked gets an empty list, not everyone else's. */
    public function test_a_vendor_with_no_projects_gets_an_empty_list(): void
    {
        $empty = $this->vendor('EmptyCo');
        $other = $this->vendor('BusyCo');
        $this->project('Busy Work', $other, 'tpv_vendor')->save();

        $this->assertCount(0, $this->listFor($empty->id));
    }

    /** Omitting the filter must leave the tenant-wide project screen untouched. */
    public function test_no_vendor_filter_still_returns_every_project(): void
    {
        $v = $this->vendor('WideCo');
        $this->project('Linked', $v, 'tpv_vendor')->save();
        $this->project('Unlinked', null, null)->save();

        Sanctum::actingAs($this->user('admin'));

        $names = collect($this->getJson('/api/projects')->assertOk()->json('data') ?? [])->pluck('name');

        $this->assertContains('Linked', $names);
        $this->assertContains('Unlinked', $names);
    }

    /** Staff run this screen too — they must not be locked out of it. */
    public function test_staff_can_read_the_vendor_projects(): void
    {
        $v     = $this->vendor('StaffCo');
        $staff = $this->user('staff');

        $p = $this->project('Staff Visible', $v, 'tpv_vendor');
        $p->created_by = $staff->id;   // non-admins see what they created or belong to
        $p->save();

        $this->assertContains('Staff Visible', $this->listFor($v->id, $staff));
    }

    /** The project module bars the portal roles at the door — 403, not an empty 200. */
    public function test_portal_roles_cannot_read_vendor_projects(): void
    {
        $v = $this->vendor('DenyCo');
        $this->project('Secret', $v, 'tpv_vendor')->save();

        foreach (['third_party_vendor', 'vendor', 'client'] as $role) {
            Sanctum::actingAs($this->user($role));

            $this->getJson('/api/projects?vendor_id='.$v->id)->assertForbidden();
        }
    }

    /** An unauthenticated caller gets nothing at all. */
    public function test_guests_cannot_read_vendor_projects(): void
    {
        $v = $this->vendor('GuestCo');
        $this->project('Secret', $v, 'tpv_vendor')->save();

        $this->getJson('/api/projects?vendor_id='.$v->id)->assertUnauthorized();
    }
}
