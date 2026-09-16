<?php

namespace Tests\Feature\Purchase;

use App\Models\Purchase\PurchaseKickoffMeeting;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Shared\OnlineMeetingService;
use App\Support\Purchase\PurchaseVendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The kickoff meeting's join link.
 *
 * The reported fault: scheduling a kickoff for a new Purchase vendor produced
 * no link, and asking for one answered "No query results for model
 * [App\Models\Shared\KickoffMeeting] 25" — a Purchase meeting id being looked up
 * in the SHARED engine's table.
 *
 * Two rules this suite holds:
 *  - each engine mints its link on its own route;
 *  - the link that comes back actually opens a meeting. An unconfigured
 *    provider yields that platform's real instant-start URL, never the stub's
 *    https://meet.example.com/… placeholder.
 */
class PurchaseKickoffLinkTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active',
        ])->save();
    }

    private function admin(): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'admin-'.Str::random(6).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function vendor(): PurchaseVendor
    {
        return PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Bolt Supplies',
            'purchase_vendor_code' => 'PV-'.uniqid(), 'status' => PurchaseVendorStatus::ACTIVE,
            'portal_status' => 'active', 'email' => Str::random(6).'@t.local',
        ]);
    }

    private function meeting(array $overrides = []): PurchaseKickoffMeeting
    {
        return PurchaseKickoffMeeting::create(array_merge([
            'tenant_id' => self::TENANT,
            'purchase_vendor_id' => $this->vendor()->id,
            'title' => 'Kickoff — Bolt Supplies',
            'status' => 'Scheduled',
            'mode' => 'online',
            'scheduled_at' => now()->addDays(3),
        ], $overrides));
    }

    /* ── The route that was missing ─────────────────────────────────────── */

    public function test_a_purchase_meeting_mints_its_link_on_its_own_route(): void
    {
        $meeting = $this->meeting();
        Sanctum::actingAs($this->admin());

        $res = $this->postJson("/api/purchase/kickoff/{$meeting->id}/generate-link", ['platform' => 'google_meet'])
            ->assertOk();

        $link = $res->json('link.link');
        $this->assertNotEmpty($link);
        $this->assertStringStartsWith('https://meet.google.com/', $link);

        // And it is stored on the meeting, not just returned.
        $this->assertSame($link, $meeting->fresh()->meeting_link);
        $this->assertSame('google_meet', $meeting->fresh()->meeting_platform);
    }

    public function test_the_stored_link_reads_back(): void
    {
        $meeting = $this->meeting();
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/purchase/kickoff/{$meeting->id}/generate-link", ['platform' => 'google_meet'])->assertOk();

        $this->getJson("/api/purchase/kickoff/{$meeting->id}/link")->assertOk()
            ->assertJsonPath('platform', 'google_meet');
    }

    public function test_another_tenants_meeting_is_not_reachable(): void
    {
        $meeting = $this->meeting();
        $meeting->forceFill(['tenant_id' => 999])->save();

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/purchase/kickoff/{$meeting->id}/generate-link")->assertStatus(404);
    }

    /* ── The link has to be usable ──────────────────────────────────────── */

    public function test_an_unconfigured_platform_yields_a_real_instant_start_link(): void
    {
        // No Zoom credentials in the test environment — the old behaviour was a
        // 422 ("Zoom credentials not configured") and no link at all.
        config(['meeting.zoom.account_id' => null]);

        $meeting = $this->meeting();
        Sanctum::actingAs($this->admin());

        $res = $this->postJson("/api/purchase/kickoff/{$meeting->id}/generate-link", ['platform' => 'zoom'])
            ->assertOk();

        $this->assertSame('https://zoom.us/start/videomeeting', $res->json('link.link'));
        $this->assertTrue($res->json('link.instant'), 'the caller is told this is a start-now link, not a booked room');
    }

    public function test_no_link_is_ever_the_stub_placeholder(): void
    {
        config(['meeting.provider' => 'stub']);

        $meeting = $this->meeting();
        Sanctum::actingAs($this->admin());

        $res = $this->postJson("/api/purchase/kickoff/{$meeting->id}/generate-link")->assertOk();

        // meet.example.com is not a meeting. Anything that resolves to the stub
        // falls through to the default platform's own start-now URL, which is.
        $this->assertStringNotContainsString('example.com', $res->json('link.link'));
        $this->assertStringStartsWith('https://meet.google.com/', $res->json('link.link'));
    }

    public function test_the_legacy_stub_platform_is_accepted_not_rejected(): void
    {
        // Meetings created before this stored meeting_platform = "stub", and
        // the detail page sends the STORED platform back when Generate is
        // pressed. Rejecting it 422d as "Validation failed" on exactly those
        // meetings.
        $meeting = $this->meeting(['meeting_platform' => 'stub']);
        Sanctum::actingAs($this->admin());

        $res = $this->postJson("/api/purchase/kickoff/{$meeting->id}/generate-link", ['platform' => 'stub'])
            ->assertOk();

        // Accepted, and quietly upgraded to a platform that actually opens.
        $this->assertStringStartsWith('https://meet.google.com/', $res->json('link.link'));
        $this->assertSame('google_meet', $meeting->fresh()->meeting_platform);
    }

    public function test_the_retired_jitsi_platform_is_accepted_not_rejected(): void
    {
        // The same trap one retirement later. Every meeting scheduled while the
        // call ran inside the CRM stored meeting_platform = "jitsi", and the
        // detail page still posts it straight back. It must not 422 them, and
        // it must not hand back a meet.jit.si room either — that is the thing
        // being removed.
        $meeting = $this->meeting(['meeting_platform' => 'jitsi']);
        Sanctum::actingAs($this->admin());

        $res = $this->postJson("/api/purchase/kickoff/{$meeting->id}/generate-link", ['platform' => 'jitsi'])
            ->assertOk();

        $this->assertStringNotContainsString('jit.si', $res->json('link.link'));
        $this->assertStringStartsWith('https://meet.google.com/', $res->json('link.link'));
        $this->assertSame('google_meet', $meeting->fresh()->meeting_platform);
    }

    public function test_a_meeting_with_a_stored_stub_platform_regenerates_without_a_platform_argument(): void
    {
        // The other half of the same trap: no platform in the body at all, and
        // the stored 'stub' is what resolvePlatform() falls back to.
        $meeting = $this->meeting(['meeting_platform' => 'stub']);
        Sanctum::actingAs($this->admin());

        $res = $this->postJson("/api/purchase/kickoff/{$meeting->id}/generate-link")->assertOk();

        $this->assertStringStartsWith('https://meet.google.com/', $res->json('link.link'));
    }

    public function test_a_platform_nobody_offers_is_still_refused(): void
    {
        $meeting = $this->meeting();
        Sanctum::actingAs($this->admin());

        // Widening the rule must not turn it off.
        $this->postJson("/api/purchase/kickoff/{$meeting->id}/generate-link", ['platform' => 'skype'])
            ->assertStatus(422);
    }

    /* ── Scheduling one produces a link without being asked ─────────────── */

    public function test_scheduling_an_online_meeting_generates_the_link_immediately(): void
    {
        $vendor = $this->vendor();
        Sanctum::actingAs($this->admin());

        $res = $this->postJson('/api/purchase/kickoff', [
            'purchase_vendor_id' => $vendor->id,
            'title'              => 'Kickoff — Bolt Supplies',
            'scheduled_at'       => now()->addDays(3)->toDateTimeString(),
            'end_at'             => now()->addDays(3)->addHour()->toDateTimeString(),
            'mode'               => 'online',
            'meeting_platform'   => 'google_meet',
        ])->assertSuccessful();

        $id = $res->json('id') ?? $res->json('data.id') ?? $res->json('meeting.id');
        $this->assertNotNull($id, 'the meeting was created');

        $meeting = PurchaseKickoffMeeting::find($id);
        $this->assertNotEmpty($meeting->meeting_link, 'an online meeting is scheduled WITH its join link');
        $this->assertStringStartsWith('https://meet.google.com/', $meeting->meeting_link);
    }

    public function test_an_in_person_meeting_gets_no_join_link(): void
    {
        $vendor = $this->vendor();
        Sanctum::actingAs($this->admin());

        $res = $this->postJson('/api/purchase/kickoff', [
            'purchase_vendor_id' => $vendor->id,
            'title'              => 'Kickoff at site',
            'scheduled_at'       => now()->addDays(3)->toDateTimeString(),
            'end_at'             => now()->addDays(3)->addHour()->toDateTimeString(),
            'mode'               => 'onsite',
            'location'           => 'Site office, Gate 2',
        ])->assertSuccessful();

        $id = $res->json('id') ?? $res->json('data.id') ?? $res->json('meeting.id');
        $meeting = PurchaseKickoffMeeting::find($id);

        // A "Join online" button on an invitation to a site office is noise.
        $this->assertNull($meeting->meeting_link);
    }

    /* ── The mode string must not decide whether the link is sent ───────── */

    public function test_the_service_wants_a_link_for_hybrid_and_capitalised_modes(): void
    {
        $service = app(OnlineMeetingService::class);

        $this->assertTrue($service->wantsLink($this->meeting(['mode' => 'online'])));
        $this->assertTrue($service->wantsLink($this->meeting(['mode' => 'hybrid'])));
        // The old invitation code compared `mode === 'online'` exactly, so these
        // two silently lost their join link.
        $this->assertTrue($service->wantsLink($this->meeting(['mode' => 'Online'])));
        $this->assertTrue($service->wantsLink($this->meeting(['mode' => null])));

        $this->assertFalse($service->wantsLink($this->meeting(['mode' => 'onsite'])));
    }
}
