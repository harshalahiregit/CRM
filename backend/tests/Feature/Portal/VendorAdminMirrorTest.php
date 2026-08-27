<?php

namespace Tests\Feature\Portal;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Models\Vendor\VendorAward;
use App\Models\Vendor\VendorReferral;
use App\Models\Vendor\VendorShipment;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Admin-side mirror of the vendor portal's Award / Referral / Shipments — the
 * endpoints the Vendor-section panels call. Admin works referrals, removes an
 * award, and reads the vendor's shipments.
 */
class VendorAdminMirrorTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;
    private User $admin;
    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill(['id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active'])->save();
        $this->vendor = Vendor::create(['tenant_id' => self::TENANT, 'company_name' => 'Acme', 'status' => VendorStatus::ACTIVE, 'email' => 'acme@test.local']);
        $this->admin = User::create(['tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin', 'email' => 'admin@test.local', 'password' => bcrypt('x'), 'status' => 'active']);
    }

    public function test_admin_advances_a_referral_status(): void
    {
        $ref = VendorReferral::create(['tenant_id' => self::TENANT, 'referred_by_vendor_id' => $this->vendor->id, 'company_name' => 'Lead Co', 'status' => 'New']);

        Sanctum::actingAs($this->admin);
        $this->getJson("/api/tpv/vendors/{$this->vendor->id}/referrals")->assertOk()->assertJsonPath('data.0.company_name', 'Lead Co');
        $this->patchJson("/api/tpv/vendors/{$this->vendor->id}/referrals/{$ref->id}/status", ['status' => 'Contacted'])
            ->assertOk()->assertJsonPath('status', 'Contacted');
    }

    public function test_admin_deletes_an_award(): void
    {
        $award = VendorAward::create(['tenant_id' => self::TENANT, 'vendor_id' => $this->vendor->id, 'title' => 'Star', 'awarded_on' => '2026-01-01']);

        Sanctum::actingAs($this->admin);
        $this->deleteJson("/api/tpv/vendors/{$this->vendor->id}/awards/{$award->id}")->assertOk()->assertJsonPath('deleted', true);
        $this->assertDatabaseMissing('vendor_awards', ['id' => $award->id]);
    }

    public function test_admin_reads_vendor_shipments_with_packages(): void
    {
        $ship = VendorShipment::create(['tenant_id' => self::TENANT, 'vendor_id' => $this->vendor->id, 'status' => 'Dispatched']);
        $ship->packages()->create(['tenant_id' => self::TENANT, 'description' => 'Crate', 'qty' => 3]);

        Sanctum::actingAs($this->admin);
        $this->getJson("/api/tpv/vendors/{$this->vendor->id}/shipments")
            ->assertOk()
            ->assertJsonPath('data.0.reference', $ship->reference)
            ->assertJsonPath('data.0.packages.0.description', 'Crate');
    }
}
