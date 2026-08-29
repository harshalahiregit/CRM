<?php

namespace Tests\Feature\Tpv;

use App\Models\Tenant;
use App\Models\Tpv\TpvVendorProject;
use App\Models\User;
use App\Models\Vendor\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * TPV vendor project engagement (§35) with the shed requirement — create, edit,
 * tenant-scope and role-gate. The shed spec lives on the TPV-local engagement,
 * not the shared Project module.
 */
class TpvVendorShedProjectTest extends TestCase
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
            'email' => $role.'-'.Str::random(6).'@test.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function vendor(int $tenantId = self::TENANT): Vendor
    {
        return Vendor::create([
            'tenant_id' => $tenantId, 'company_name' => 'Shed Co '.Str::random(4),
            'email' => 'shed-'.Str::random(5).'@test.local', 'status' => 'Active',
        ]);
    }

    private function shedPayload(array $over = []): array
    {
        return array_merge([
            'project'                => 'Plant A Shed',
            'site'                   => 'Zone 3',
            'role'                   => 'Main contractor',
            'status'                 => 'Active',
            'shed_site_location'     => 'Plot 12, MIDC',
            'shed_length'            => 40.5,
            'shed_width'             => 20,
            'shed_height'            => '19 Meter',
            'shed_purpose'           => 'Industrial Plant',
            'shed_side_wall'         => true,
            'shed_flooring'          => true,
            'shed_gate_shutter_size' => '12 x 14 ft',
            'shed_footing_done'      => false,
            'shed_office_toilet'     => true,
        ], $over);
    }

    public function test_create_stores_the_engagement_with_its_shed_requirement(): void
    {
        $v = $this->vendor();
        Sanctum::actingAs($this->user('admin'));

        $res = $this->postJson("/api/tpv/vendors/{$v->id}/projects", $this->shedPayload())->assertCreated();
        $id = $res->json('id');

        $this->assertDatabaseHas('tpv_vendor_projects', [
            'id' => $id, 'vendor_id' => $v->id, 'tenant_id' => self::TENANT,
            'project' => 'Plant A Shed', 'shed_height' => '19 Meter', 'shed_purpose' => 'Industrial Plant',
        ]);

        $row = TpvVendorProject::find($id);
        $this->assertTrue((bool) $row->shed_side_wall);
        $this->assertFalse((bool) $row->shed_footing_done);
        $this->assertSame('40.50', (string) $row->shed_length);
    }

    public function test_update_edits_the_shed_requirement(): void
    {
        $v = $this->vendor();
        Sanctum::actingAs($this->user('admin'));

        $id = $this->postJson("/api/tpv/vendors/{$v->id}/projects", $this->shedPayload())->json('id');

        $this->putJson("/api/tpv/vendors/{$v->id}/projects/{$id}", [
            'shed_height' => '21 Meter', 'shed_footing_done' => true, 'status' => 'Completed',
        ])->assertOk();

        $row = TpvVendorProject::find($id);
        $this->assertSame('21 Meter', $row->shed_height);
        $this->assertTrue((bool) $row->shed_footing_done);
        $this->assertSame('Completed', $row->status);
        // Untouched fields survive the partial update.
        $this->assertSame('Industrial Plant', $row->shed_purpose);
    }

    public function test_a_vendor_from_another_tenant_is_unreachable(): void
    {
        $foreign = $this->vendor(self::OTHER);
        Sanctum::actingAs($this->user('admin', self::TENANT));

        $this->postJson("/api/tpv/vendors/{$foreign->id}/projects", $this->shedPayload())->assertNotFound();
    }

    public function test_update_cannot_cross_vendors(): void
    {
        $a = $this->vendor();
        $b = $this->vendor();
        Sanctum::actingAs($this->user('admin'));

        $id = $this->postJson("/api/tpv/vendors/{$a->id}/projects", $this->shedPayload())->json('id');

        // The engagement belongs to A, so editing it via B's URL 404s.
        $this->putJson("/api/tpv/vendors/{$b->id}/projects/{$id}", ['shed_height' => 'x'])->assertNotFound();
    }

    public function test_portal_roles_cannot_manage_shed_projects(): void
    {
        $v = $this->vendor();
        foreach (['third_party_vendor', 'vendor', 'client'] as $role) {
            Sanctum::actingAs($this->user($role));
            $this->getJson("/api/tpv/vendors/{$v->id}/projects")->assertForbidden();
            $this->postJson("/api/tpv/vendors/{$v->id}/projects", $this->shedPayload())->assertForbidden();
        }
    }
}
