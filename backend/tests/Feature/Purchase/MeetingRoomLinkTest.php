<?php

namespace Tests\Feature\Purchase;

use App\Models\Purchase\PurchaseKickoffMeeting;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Purchase\PurchaseVendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * "The Google Meet link is /new — how do the others know it's the right room?"
 *
 * They didn't. meet.google.com/new opens a NEW, EMPTY meeting for every person
 * who clicks it, so handing it to attendees put each of them in a room of their
 * own. The rules this suite holds:
 *
 *  - an instant-start link is held by the host only; everyone else is told the
 *    room is not shared yet, never given /new;
 *  - the host pastes the real room link, and from then on EVERYONE invited
 *    gets that one room — it is also e-mailed to them, so withholding it in
 *    the CRM would only make this the slow way to a link already in the inbox;
 *  - /new itself cannot be pasted as "the room", and only the host may paste.
 */
class MeetingRoomLinkTest extends TestCase
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

    private function user(string $role): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => ucfirst($role), 'role' => $role,
            'email' => $role.'-'.Str::random(6).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function meeting(User $organiser, array $overrides = []): PurchaseKickoffMeeting
    {
        $vendor = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Bolt Supplies',
            'purchase_vendor_code' => 'PV-'.uniqid(), 'status' => PurchaseVendorStatus::ACTIVE,
            'portal_status' => 'active', 'email' => Str::random(6).'@t.local',
        ]);

        return PurchaseKickoffMeeting::create(array_merge([
            'tenant_id' => self::TENANT,
            'purchase_vendor_id' => $vendor->id,
            'title' => 'Kickoff — Bolt Supplies',
            'status' => 'Scheduled',
            'mode' => 'online',
            'scheduled_at' => now()->addDays(3),
            'meeting_platform' => 'google_meet',
            'meeting_link' => 'https://meet.google.com/new',
            'created_by' => $organiser->id,
        ], $overrides));
    }

    public function test_the_host_holds_the_instant_link_and_is_told_to_share_the_real_room(): void
    {
        $admin = $this->user('admin');
        $meeting = $this->meeting($admin);
        Sanctum::actingAs($admin);

        $this->getJson("/api/purchase/kickoff/{$meeting->id}/link")->assertOk()
            ->assertJsonPath('link', 'https://meet.google.com/new')
            ->assertJsonPath('instant', true)
            ->assertJsonPath('link_is_instant', true)
            ->assertJsonPath('can_set_link', true);
    }

    public function test_an_attendee_never_receives_the_instant_link(): void
    {
        $meeting = $this->meeting($this->user('admin'));
        Sanctum::actingAs($this->user('staff'));

        $res = $this->getJson("/api/purchase/kickoff/{$meeting->id}/link")->assertOk();

        $this->assertNull($res->json('link'));
        $this->assertNull($res->json('meeting_link'));
        $this->assertTrue($res->json('link_pending'));
        $this->assertFalse($res->json('can_set_link'));
    }

    public function test_the_host_pastes_the_real_room_and_it_replaces_the_instant_link(): void
    {
        $admin = $this->user('admin');
        $meeting = $this->meeting($admin);
        Sanctum::actingAs($admin);

        $this->putJson("/api/purchase/kickoff/{$meeting->id}/link", ['link' => 'https://meet.google.com/abc-defg-hij'])
            ->assertOk()
            ->assertJsonPath('link.link', 'https://meet.google.com/abc-defg-hij')
            ->assertJsonPath('link.instant', false);

        $this->assertSame('https://meet.google.com/abc-defg-hij', $meeting->fresh()->meeting_link);
    }

    public function test_once_the_room_is_shared_every_attendee_gets_it(): void
    {
        $meeting = $this->meeting($this->user('admin'), ['meeting_link' => 'https://meet.google.com/abc-defg-hij']);
        Sanctum::actingAs($this->user('staff'));

        /*
         * This used to assert link => null: the room was withheld until the
         * person marked attendance. That toll is gone, and deliberately.
         * MeetingLinkAnnouncer e-mails this exact URL, with a calendar
         * attachment, to everyone invited the moment the host pastes it — so
         * withholding it here protected nothing and only made the CRM the
         * slowest route to a link already sitting in their inbox. See
         * MeetingAttendanceGate.
         *
         * The instant-link rule above is untouched, and it is the one that was
         * ever load-bearing.
         */
        $this->getJson("/api/purchase/kickoff/{$meeting->id}/link")->assertOk()
            ->assertJsonPath('link_pending', false)
            ->assertJsonPath('has_meeting_link', true)
            ->assertJsonPath('link', 'https://meet.google.com/abc-defg-hij')
            ->assertJsonPath('attendance_marked', false);
    }

    public function test_the_platform_is_read_from_the_pasted_link(): void
    {
        $admin = $this->user('admin');
        $meeting = $this->meeting($admin);
        Sanctum::actingAs($admin);

        $this->putJson("/api/purchase/kickoff/{$meeting->id}/link", ['link' => 'https://us02web.zoom.us/j/81234567890?pwd=abc'])
            ->assertOk()
            ->assertJsonPath('link.platform', 'zoom');
    }

    public function test_an_instant_start_link_cannot_be_pasted_as_the_room(): void
    {
        $admin = $this->user('admin');
        $meeting = $this->meeting($admin, ['meeting_link' => null]);
        Sanctum::actingAs($admin);

        $this->putJson("/api/purchase/kickoff/{$meeting->id}/link", ['link' => 'https://meet.google.com/new/'])
            ->assertStatus(422)->assertJsonValidationErrors('link');
        $this->putJson("/api/purchase/kickoff/{$meeting->id}/link", ['link' => 'https://example.com/room'])
            ->assertStatus(422)->assertJsonValidationErrors('link');
        $this->putJson("/api/purchase/kickoff/{$meeting->id}/link", ['link' => 'meet.google.com/abc-defg-hij'])
            ->assertStatus(422)->assertJsonValidationErrors('link');

        $this->assertNull($meeting->fresh()->meeting_link);
    }

    public function test_only_the_host_may_set_the_room(): void
    {
        $meeting = $this->meeting($this->user('admin'));
        Sanctum::actingAs($this->user('staff'));

        $this->putJson("/api/purchase/kickoff/{$meeting->id}/link", ['link' => 'https://meet.google.com/abc-defg-hij'])
            ->assertStatus(403);

        $this->assertSame('https://meet.google.com/new', $meeting->fresh()->meeting_link);
    }

    public function test_another_tenants_meeting_cannot_be_given_a_room(): void
    {
        $admin = $this->user('admin');
        $meeting = $this->meeting($admin);
        $meeting->forceFill(['tenant_id' => 999])->save();
        Sanctum::actingAs($admin);

        $this->putJson("/api/purchase/kickoff/{$meeting->id}/link", ['link' => 'https://meet.google.com/abc-defg-hij'])
            ->assertStatus(404);
    }
}
