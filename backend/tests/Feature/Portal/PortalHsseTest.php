<?php

namespace Tests\Feature\Portal;

use App\Models\Tenant;
use App\Models\Tpv\HsseIncident;
use App\Models\Tpv\WorkPermit;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Compliance & HSSE — a TPV vendor requests permits and reports incidents from
 * its portal (writes), sees only its own, and a Serious/Fatal self-report still
 * trips the safety hold on site access.
 */
class PortalHsseTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill(['id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active'])->save();
    }

    private function portalVendor(string $name = 'Acme'): Vendor
    {
        $vendor = Vendor::create(['tenant_id' => self::TENANT, 'company_name' => $name, 'status' => VendorStatus::ACTIVE, 'email' => strtolower($name).'@test.local']);
        $user = User::create([
            'tenant_id' => self::TENANT, 'name' => $name.' Portal', 'role' => 'third_party_vendor',
            'email' => strtolower($name).'@test.local', 'password' => bcrypt('secret'), 'status' => 'active',
        ]);
        $vendor->update(['user_id' => $user->id]);

        return $vendor->fresh();
    }

    public function test_vendor_requests_a_permit(): void
    {
        $vendor = $this->portalVendor();

        Sanctum::actingAs($vendor->user);
        $this->postJson('/api/portal/permits', ['type' => 'Hot_Work', 'title' => 'Welding at bay 3', 'location' => 'Bay 3'])
            ->assertCreated()->assertJsonPath('status', 'Requested');

        $this->getJson('/api/portal/permits')->assertOk()->assertJsonPath('data.0.title', 'Welding at bay 3');
    }

    public function test_vendor_reports_incident_and_sees_only_its_own(): void
    {
        $mine  = $this->portalVendor('Acme');
        $other = $this->portalVendor('BetaCo');
        HsseIncident::create(['tenant_id' => self::TENANT, 'reference' => 'INC-OTHER', 'vendor_id' => $other->id, 'title' => 'Theirs', 'type' => 'Near_Miss', 'severity' => 'Minor', 'status' => 'Reported', 'occurred_at' => now()]);

        Sanctum::actingAs($mine->user);
        $this->postJson('/api/portal/incidents', ['title' => 'Slip near store', 'type' => 'Near_Miss', 'severity' => 'Minor'])
            ->assertCreated()->assertJsonPath('status', 'Reported');

        $res = $this->getJson('/api/portal/incidents')->assertOk();
        $titles = collect($res->json('data'))->pluck('title')->all();
        $this->assertSame(['Slip near store'], $titles);
    }

    public function test_serious_incident_report_triggers_safety_hold(): void
    {
        $vendor = $this->portalVendor();
        $this->assertSame(VendorStatus::ACTIVE, $vendor->status);

        Sanctum::actingAs($vendor->user);
        $this->postJson('/api/portal/incidents', ['title' => 'Fall from height', 'type' => 'LTI', 'severity' => 'Serious'])
            ->assertCreated()->assertJsonPath('triggered_suspension', true);

        // The vendor's own site access is withheld pending review.
        $this->assertNotSame(VendorStatus::ACTIVE, $vendor->fresh()->status);
    }
}
