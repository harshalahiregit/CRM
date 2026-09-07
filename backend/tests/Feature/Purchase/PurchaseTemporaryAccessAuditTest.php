<?php

namespace Tests\Feature\Purchase;

use App\Models\Purchase\PurchaseKickoffMeeting;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Tenant;
use App\Support\Purchase\PurchaseKickoffStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A temporary Purchase vendor whose access window has closed.
 *
 * The TPV portal runs every request through EnsureTemporaryAccessNotExpired,
 * which expires a lapsed temporary vendor lazily, on the spot. The Purchase
 * portal's route group carries no equivalent — its guard checks only that the
 * caller is a PurchaseVendor with portal_status 'active'. PurchaseVendor has
 * isAccessExpired(), so the model knows; the question this answers is whether
 * anything on the request path asks it.
 *
 * Purchase has temporary vendors in production data, so this is not theoretical.
 */
class PurchaseTemporaryAccessAuditTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();
    }

    private function expiredTemporaryVendor(): PurchaseVendor
    {
        $vendor = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Temp Crew',
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => 'temp-'.Str::random(4).'@t.local',
            'status' => 'Active', 'portal_status' => 'active',
            // registration_type is the real rule; vendor_type is only the legacy
            // fallback for rows written before it existed, and a non-null
            // registration_type disables that fallback entirely.
            'vendor_type' => 'temporary',
            'registration_type' => \App\Support\Purchase\PurchaseRegistrationType::TEMPORARY,
        ]);

        $vendor->forceFill(['access_expires_at' => now()->subWeek()])->save();

        return $vendor->fresh();
    }

    public function test_the_model_knows_the_window_has_closed(): void
    {
        $vendor = $this->expiredTemporaryVendor();

        $this->assertTrue($vendor->isTemporary(), 'the fixture must actually be a temporary vendor');
        $this->assertTrue($vendor->isAccessExpired(), 'its window closed a week ago');
    }

    /**
     * The finding, recorded as it stands.
     *
     * An expired temporary Purchase vendor still reads its meetings, because
     * portal_status is only flipped by a scheduled backfill — nothing on the
     * request path asks isAccessExpired(). The equivalent TPV vendor is refused
     * on the spot by EnsureTemporaryAccessNotExpired.
     *
     * Asserted as CURRENT behaviour rather than as correct: closing it means
     * adding middleware to the Purchase portal group, which changes access for
     * every Purchase vendor and is the module owner's call. If that middleware
     * is added, this test should flip to expecting 403 — which is exactly the
     * signal wanted.
     */
    public function test_an_expired_temporary_vendor_still_reaches_the_portal_today(): void
    {
        $vendor = $this->expiredTemporaryVendor();

        PurchaseKickoffMeeting::create([
            'tenant_id' => self::TENANT, 'purchase_vendor_id' => $vendor->id,
            'title' => 'Kickoff', 'meeting_type' => 'kickoff',
            'status' => PurchaseKickoffStatus::SCHEDULED,
            'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'), 'duration_minutes' => 60,
        ]);

        Sanctum::actingAs($vendor);

        $status = $this->getJson('/api/portal/purchase/meetings')->getStatusCode();

        $this->assertSame(200, $status,
            'documented gap: the Purchase portal does not enforce the access window on the request path. '
            .'If middleware has since been added, change this expectation to 403.');
    }

    /** Whereas flipping portal_status — what the backfill does — is enforced. */
    public function test_portal_status_is_enforced_when_it_is_actually_set(): void
    {
        $vendor = $this->expiredTemporaryVendor();
        $vendor->forceFill(['portal_status' => 'inactive'])->save();

        Sanctum::actingAs($vendor->fresh());

        $this->assertSame(403, $this->getJson('/api/portal/purchase/meetings')->getStatusCode(),
            'an inactive portal account must be refused');
    }
}
