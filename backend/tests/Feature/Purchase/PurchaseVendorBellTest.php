<?php

namespace Tests\Feature\Purchase;

use App\Models\Purchase\PurchaseKickoffMeeting;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Purchase\PurchaseKickoffService;
use App\Support\Purchase\PurchaseKickoffStatus;
use App\Support\Shared\BusinessTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Purchase vendor's in-app bell.
 *
 * A Purchase vendor is its own Authenticatable, not a User, so its bell lives in
 * purchase_vendor_notifications rather than the shared notifications table. That
 * store had exactly TWO writers — documents and onboarding — so it sat empty and
 * the bell looked broken: everything else that happened to a vendor, meetings
 * included, reached them only by e-mail or not at all.
 */
class PurchaseVendorBellTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private PurchaseVendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();
        BusinessTime::flush();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();

        $this->vendor = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Acme',
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => 'acme-'.Str::random(4).'@t.local',
            'status' => 'Active', 'portal_status' => 'active',
        ]);
    }

    private function actor(): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'Coordinator', 'role' => 'admin',
            'email' => 'c-'.Str::random(6).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function clock(int $minutesFromNow): string
    {
        return BusinessTime::now(self::TENANT)->copy()->addMinutes($minutesFromNow)->format('Y-m-d H:i:s');
    }

    private function bellRows(): \Illuminate\Support\Collection
    {
        return DB::table('purchase_vendor_notifications')
            ->where('purchase_vendor_id', $this->vendor->id)->get();
    }

    /** A meeting is born a Draft and is invisible to the vendor; PUBLISHING tells them. */
    private function publishedMeeting(): PurchaseKickoffMeeting
    {
        $service = app(PurchaseKickoffService::class);
        $actor   = $this->actor();

        $meeting = $service->schedule([
            'purchase_vendor_id' => $this->vendor->id,
            'title'        => 'Kickoff',
            'meeting_type' => 'kickoff',
            'scheduled_at' => $this->clock(1440),
            'end_at'       => $this->clock(1500),
        ], $actor);

        $published = $service->transition($meeting->fresh(), PurchaseKickoffStatus::SCHEDULED, [], $actor);

        // Publishing now notifies AFTER the response is flushed — one SMTP
        // session per participant used to run inside the request and timed it
        // out at thirty seconds. A test calls the service directly, so nothing
        // would ever terminate the application and the notice would sit unsent
        // forever. This is the honest question: by the time the request is
        // over, was the vendor told?
        $this->app->terminate();

        return $published;
    }

    public function test_a_draft_meeting_tells_the_vendor_nothing(): void
    {
        app(PurchaseKickoffService::class)->schedule([
            'purchase_vendor_id' => $this->vendor->id,
            'title' => 'Kickoff', 'meeting_type' => 'kickoff',
            'scheduled_at' => $this->clock(1440), 'end_at' => $this->clock(1500),
        ], $this->actor());

        // A draft is deliberately invisible to the vendor — telling them about
        // one would announce a meeting that may never be booked.
        $this->assertCount(0, $this->bellRows());
    }

    public function test_publishing_a_meeting_reaches_the_vendors_bell(): void
    {
        $this->publishedMeeting();

        $rows = $this->bellRows();

        $this->assertCount(1, $rows, 'the vendor should be told a meeting was booked');
        $this->assertSame('meeting.scheduled', $rows[0]->type);
        $this->assertStringContainsString('Kickoff', $rows[0]->title);
    }

    public function test_an_expiring_meeting_reaches_the_vendors_bell(): void
    {
        PurchaseKickoffMeeting::create([
            'tenant_id' => self::TENANT, 'purchase_vendor_id' => $this->vendor->id,
            'title' => 'Missed', 'meeting_type' => 'kickoff', 'status' => PurchaseKickoffStatus::SCHEDULED,
            'scheduled_at' => $this->clock(-180), 'end_at' => $this->clock(-120), 'duration_minutes' => 60,
        ]);

        app(PurchaseKickoffService::class)->runDueExpiryNotices();

        $rows = $this->bellRows()->where('type', 'meeting.expired');
        $this->assertCount(1, $rows, 'the vendor should be told their meeting expired');
    }

    public function test_the_vendor_reads_its_own_bell_through_the_portal(): void
    {
        $this->publishedMeeting();

        Sanctum::actingAs($this->vendor);
        $body = $this->getJson('/api/portal/purchase/notifications')->assertOk()->json();

        $this->assertSame(1, $body['unread_count']);
        $this->assertCount(1, $body['items']);
    }

    public function test_another_vendors_bell_is_never_readable(): void
    {
        $rival = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Rival',
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => 'rival-'.Str::random(4).'@t.local',
            'status' => 'Active', 'portal_status' => 'active',
        ]);

        $this->publishedMeeting();

        Sanctum::actingAs($rival);
        $body = $this->getJson('/api/portal/purchase/notifications')->assertOk()->json();

        $this->assertSame(0, $body['unread_count']);
        $this->assertCount(0, $body['items']);
    }
}
