<?php

namespace Tests\Feature\Shared;

use App\Models\Shared\KickoffMeeting;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Shared\MeetingVisibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Meetings is a module for the whole company, not for TPV.
 *
 * Two rules, decided with the user:
 *   1. Every INTERNAL role can open Meetings and schedule one.
 *   2. You see the meetings you organise or were invited to. An admin sees the
 *      whole tenant plus the reports. Nothing else differs — same screens.
 *
 * Rule 2 is the one that needs pinning. Before it, the engine was tenant-wide
 * with no per-user filter, which was safe only because ten admin/staff logins
 * shared a single subject: vendor governance. Opening the module to every
 * internal role without scoping would have put an HR one-to-one and a doctor's
 * medical review in a list everybody reads — and the `confidentiality` field
 * that looks like it prevents exactly that was enforced nowhere.
 */
class MeetingsAreCompanyWideTest extends TestCase
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
            'tenant_id' => self::TENANT, 'name' => ucfirst($role).' '.Str::random(4), 'role' => $role,
            'email' => $role.'-'.Str::random(6).'@sangoe.local',
            'password' => bcrypt('secret'), 'status' => 'active',
        ]);
    }

    private function meeting(?User $organiser, string $title = 'A meeting'): KickoffMeeting
    {
        return KickoffMeeting::create([
            'tenant_id' => self::TENANT, 'created_by' => $organiser?->id,
            'title' => $title, 'meeting_type' => 'weekly_coordination', 'status' => 'Scheduled',
            'mode' => 'online', 'scheduled_at' => now()->addDay(), 'end_at' => now()->addDay()->addHour(),
        ]);
    }

    private function invite(KickoffMeeting $m, ?User $u = null, ?string $email = null): void
    {
        $m->attendees()->create([
            'tenant_id' => self::TENANT,
            'user_id' => $u?->id,
            'name' => $u?->name ?? 'Guest',
            'email' => $email ?? $u?->email,
            'side' => 'internal',
        ]);
    }

    private function titles(): array
    {
        return collect($this->getJson('/api/kickoff/meetings')->assertOk()->json())
            ->pluck('title')->all();
    }

    /* ── 1. who is allowed in ────────────────────────────────────────── */

    public function test_every_internal_role_can_open_meetings_and_schedule_one(): void
    {
        foreach (MeetingVisibility::INTERNAL_ROLES as $role) {
            Sanctum::actingAs($this->user($role));

            $this->getJson('/api/kickoff/meetings')->assertOk();

            // "All have access — they can start a meeting."
            $this->postJson('/api/kickoff/meetings', [
                'title' => "Scheduled by a {$role}",
                'scheduled_at' => now()->addDay()->toDateTimeString(),
                'end_at' => now()->addDay()->addHour()->toDateTimeString(),
                'mode' => 'online', 'meeting_type' => 'weekly_coordination',
            ])->assertSuccessful();
        }

        $this->assertSame(count(MeetingVisibility::INTERNAL_ROLES), KickoffMeeting::count());
    }

    public function test_a_meeting_needs_no_vendor_behind_it(): void
    {
        Sanctum::actingAs($this->user('hr'));

        // The whole point of a company-wide module: an internal meeting belongs
        // to nobody but the people in it.
        $this->postJson('/api/kickoff/meetings', [
            'title' => 'Team catch-up',
            'scheduled_at' => now()->addDay()->toDateTimeString(),
            'end_at' => now()->addDay()->addHour()->toDateTimeString(),
            'mode' => 'online', 'meeting_type' => 'weekly_coordination',
        ])->assertSuccessful();

        $m = KickoffMeeting::sole();
        $this->assertNull($m->kickoffable_type);
        $this->assertNull($m->kickoffable_id);
    }

    public function test_external_roles_are_still_refused_outright(): void
    {
        // They keep the read-only governance view in their own portal, and stay
        // selectable AS participants — see the picker test below.
        foreach (['third_party_vendor', 'client', 'vendor'] as $role) {
            Sanctum::actingAs($this->user($role));
            $this->getJson('/api/kickoff/meetings')->assertStatus(403);
            $this->getJson('/api/kickoff/participants')->assertStatus(403);
        }
    }

    /* ── 2. what each person sees ────────────────────────────────────── */

    public function test_a_user_sees_what_they_organise_and_what_they_are_invited_to(): void
    {
        $me = $this->user('staff');
        $other = $this->user('staff');

        $this->meeting($me, 'Mine, I called it');
        $this->invite($this->meeting($other, 'Mine, I was invited'), $me);
        $this->meeting($other, 'Not mine at all');

        Sanctum::actingAs($me);
        $titles = $this->titles();

        $this->assertContains('Mine, I called it', $titles);
        $this->assertContains('Mine, I was invited', $titles);
        $this->assertNotContains('Not mine at all', $titles);
    }

    public function test_an_invitation_by_email_alone_still_shows_the_meeting(): void
    {
        $me = $this->user('staff');
        $organiser = $this->user('staff');

        // Typed into the form by address rather than picked from the directory,
        // so the row carries no user_id. This person is invited, e-mailed and
        // belled — and would open Meetings to find their own meeting missing.
        $m = $this->meeting($organiser, 'Invited by address');
        $this->invite($m, null, strtoupper($me->email));   // and case must not matter

        Sanctum::actingAs($me);
        $this->assertContains('Invited by address', $this->titles());
    }

    public function test_an_admin_sees_the_whole_tenant(): void
    {
        $someone = $this->user('staff');
        $this->meeting($someone, 'Not the admins meeting');
        $this->meeting($this->user('hr'), 'Nor this one');

        Sanctum::actingAs($this->user('admin'));
        $titles = $this->titles();

        // "All data is on the admin side — that is the only difference."
        $this->assertContains('Not the admins meeting', $titles);
        $this->assertContains('Nor this one', $titles);
    }

    public function test_a_stranger_gets_404_on_the_detail_route_not_403(): void
    {
        $m = $this->meeting($this->user('hr'), 'One to one');

        Sanctum::actingAs($this->user('staff'));

        // 404, because 403 confirms the meeting exists — and on an HR one-to-one
        // the existence is most of the secret. Hiding it from the list while the
        // detail route hands it over would make the scoping decorative.
        $this->getJson("/api/kickoff/meetings/{$m->id}")->assertStatus(404);
        $this->putJson("/api/kickoff/meetings/{$m->id}", ['title' => 'Mine now'])->assertStatus(404);
        $this->deleteJson("/api/kickoff/meetings/{$m->id}")->assertStatus(404);
    }

    public function test_the_counts_narrow_with_the_list(): void
    {
        $me = $this->user('staff');
        $this->meeting($me, 'Mine');
        $this->meeting($this->user('staff'), 'Theirs');
        $this->meeting($this->user('staff'), 'Also theirs');

        Sanctum::actingAs($me);

        // A header reading "3 meetings" over a table showing one looks like a
        // bug, and is really a leak: it discloses how much is being withheld.
        $this->getJson('/api/kickoff/meetings/stats')->assertOk()->assertJsonPath('total', 1);
        $this->assertCount(1, $this->titles());

        // The dashboard is the same aggregate on a bigger canvas, and narrows
        // with it -- otherwise "reports on the admin side" leaks through a chart.
        $this->getJson('/api/kickoff/meetings/dashboard')->assertOk()
            ->assertJsonPath('upcoming', 1);
    }

    public function test_the_registers_do_not_leak_another_meetings_contents(): void
    {
        $me = $this->user('staff');
        $mine = $this->meeting($me, 'Mine');
        $theirs = $this->meeting($this->user('hr'), 'Theirs');

        foreach ([[$mine, 'My action'], [$theirs, 'Their action']] as [$m, $desc]) {
            $m->momItems()->create([
                'tenant_id' => self::TENANT, 'description' => $desc, 'status' => 'Open',
            ]);
        }

        Sanctum::actingAs($me);
        $body = $this->getJson('/api/kickoff/registers/actions')->assertOk()->getContent();

        // The registers start from the action table, not from meetings, so they
        // needed scoping of their own — otherwise every action item in the
        // company is readable through a screen that never names the meeting.
        $this->assertStringContainsString('My action', $body);
        $this->assertStringNotContainsString('Their action', $body);
    }

    /* ── 3. the category-wise picker ─────────────────────────────────── */

    public function test_the_picker_offers_every_category_including_the_roles_it_used_to_hide(): void
    {
        // The old picker was hard-coded to ['admin','staff'], so these three
        // could not be invited to a meeting at all.
        $manager = $this->user('manager');
        $hr = $this->user('hr');
        $doctor = $this->user('doctor');

        Sanctum::actingAs($this->user('admin'));
        $cats = collect($this->getJson('/api/kickoff/participants')->assertOk()->json('categories'))
            ->keyBy('key');

        foreach (['admin', 'staff', 'manager', 'hr', 'doctor', 'customer', 'vendor'] as $key) {
            $this->assertTrue($cats->has($key), "the picker offers a {$key} category");
        }

        $names = fn (string $k) => collect($cats[$k]['people'])->pluck('name')->all();
        $this->assertContains($manager->name, $names('manager'));
        $this->assertContains($hr->name, $names('hr'));
        $this->assertContains($doctor->name, $names('doctor'));
    }

    public function test_the_picker_leaves_out_deactivated_logins(): void
    {
        $gone = $this->user('staff');
        $gone->forceFill(['status' => 'inactive'])->save();

        Sanctum::actingAs($this->user('admin'));
        $people = collect($this->getJson('/api/kickoff/participants')->assertOk()->json('categories'))
            ->flatMap(fn ($c) => $c['people'])->pluck('name')->all();

        // A deactivated account on a participant list is somebody who will never
        // get the invitation, while the send reports success.
        $this->assertNotContains($gone->name, $people);
    }
}
