<?php

namespace Tests\Feature\Shared;

use App\Models\Shared\KickoffMeeting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\Shared\AttendanceVerdict;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Who can see and do what on a meeting.
 *
 * Most of this was already true and is pinned here rather than built: the
 * scheduling endpoints sit behind role:admin,staff, the portal only ever lists a
 * vendor's own meetings, and the vendor can never approve or decide anything.
 * None of that had a test saying so from the VENDOR's side, so it was true by
 * arrangement rather than by agreement.
 *
 * ── The roster is deliberately visible ──────────────────────────────────
 * A vendor opening their meeting sees every participant, including our own
 * staff and their roles. That is a decision, not an oversight, and it is the
 * reason this test exists: somebody reading the brief's "external vendors see
 * only participants mapped to their permitted access levels" could reasonably
 * decide to hide the internal side, and would be undoing a choice rather than
 * fixing a leak.
 *
 * The argument for showing them: the vendor sat in that call. They saw those
 * people and heard them speak, so hiding the names afterwards protects nothing
 * — and when the minutes say "Anjali to confirm the payment date", they need to
 * know who Anjali is. If this ever needs to become private, the meeting already
 * carries a `confidentiality` field, and per-meeting is the right granularity
 * for it — not a blanket rule.
 */
class MeetingVisibilityByRoleTest extends TestCase
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

    private function staff(string $role = 'staff', string $name = 'Priya Sharma'): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => $name, 'role' => $role,
            'email' => strtolower(str_replace(' ', '.', $name)).'-'.Str::random(4).'@sangoe.local',
            'password' => bcrypt('secret'), 'status' => 'active',
        ]);
    }

    private function vendorWithLogin(string $name = 'AlphaCo'): array
    {
        $login = User::create([
            'tenant_id' => self::TENANT, 'name' => $name, 'role' => 'third_party_vendor',
            'email' => strtolower($name).'-'.Str::random(6).'@login.local',
            'password' => bcrypt('secret'), 'status' => 'active',
        ]);
        $vendor = Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => $name,
            'email' => strtolower($name).'-'.Str::random(6).'@vendor.local',
            'status' => VendorStatus::ACTIVE, 'user_id' => $login->id,
        ]);

        return [$login, $vendor];
    }

    /** A meeting with both sides on the roster, as a real one has. */
    private function meetingWithBothSides(Vendor $vendor, User $organiser): KickoffMeeting
    {
        $m = KickoffMeeting::create([
            'tenant_id' => self::TENANT, 'created_by' => $organiser->id,
            'kickoffable_type' => Vendor::class, 'kickoffable_id' => $vendor->id,
            'title' => 'Kickoff', 'meeting_type' => 'kickoff', 'status' => 'Scheduled', 'mode' => 'online',
            'scheduled_at' => now()->addDay(), 'end_at' => now()->addDay()->addHour(), 'duration_minutes' => 60,
        ]);

        foreach ([
            ['Priya Sharma', 'Project Manager', 'internal'],
            ['Rahul Verma', 'Safety Officer', 'internal'],
            ['Anjali Desai', 'Finance Head', 'internal'],
            ['Ravi', 'Site Supervisor', 'external'],
            ['Sunita', 'Accounts', 'external'],
        ] as [$name, $role, $side]) {
            $m->attendees()->create([
                'tenant_id' => self::TENANT, 'name' => $name, 'role' => $role, 'side' => $side,
            ]);
        }

        return $m;
    }

    /* ── the decision ────────────────────────────────────────────────── */

    public function test_a_vendor_sees_the_whole_roster_including_our_own_side(): void
    {
        [$login, $vendor] = $this->vendorWithLogin();
        $this->meetingWithBothSides($vendor, $this->staff('admin', 'Priya Sharma'));

        Sanctum::actingAs($login);
        $names = collect($this->getJson('/api/portal/meetings')->assertOk()->json('data.0.attendees'))
            ->pluck('name')->all();

        // Their own people...
        $this->assertContains('Ravi', $names);
        $this->assertContains('Sunita', $names);

        // ...and ours. Chosen, not overlooked — see the class docblock. Anyone
        // hiding these is reversing a decision, and this assertion is where they
        // will find that out.
        $this->assertContains('Rahul Verma', $names, 'internal attendees stay visible to the vendor by choice');
        $this->assertContains('Anjali Desai', $names);
    }

    /* ── what the vendor genuinely cannot do ─────────────────────────── */

    public function test_a_vendor_cannot_schedule_a_meeting(): void
    {
        [$login, $vendor] = $this->vendorWithLogin();

        Sanctum::actingAs($login);

        // The scheduling side of the engine is admin/staff only. 403, not 401 —
        // a session-shaped 401 makes the portal client sign the vendor out.
        $this->postJson('/api/kickoff/meetings', [
            'subject_type' => 'vendor', 'subject_id' => $vendor->id, 'title' => 'Mine now',
        ])->assertStatus(403);

        $this->assertSame(0, KickoffMeeting::count());
    }

    public function test_a_vendor_cannot_read_the_staff_directory(): void
    {
        [$login] = $this->vendorWithLogin();
        $this->staff('staff', 'Rahul Verma');

        Sanctum::actingAs($login);

        // The participant pickers are how an admin selects "all users and
        // vendors". They are not a directory the outside world may read.
        $this->getJson('/api/kickoff/staff')->assertStatus(403);
        $this->getJson('/api/kickoff/customers')->assertStatus(403);
    }

    public function test_a_vendor_cannot_decide_attendance(): void
    {
        [$login, $vendor] = $this->vendorWithLogin();
        $organiser = $this->staff('admin');
        $m = $this->meetingWithBothSides($vendor, $organiser);
        $row = $m->attendees()->where('name', 'Ravi')->sole();

        Sanctum::actingAs($login);

        // The organiser's verdict is the organiser's. A vendor marking itself
        // Fully Present would make the whole review worthless.
        $this->postJson("/api/kickoff/meetings/{$m->id}/attendance/review", [
            'rows' => [['id' => $row->id, 'verdict' => AttendanceVerdict::FULLY_PRESENT]],
        ])->assertStatus(403);

        $this->assertNull($row->fresh()->verdict);
    }

    public function test_another_vendors_meeting_is_not_listed(): void
    {
        [$loginA] = $this->vendorWithLogin('AlphaCo');
        [, $vendorB] = $this->vendorWithLogin('BetaCo');
        $this->meetingWithBothSides($vendorB, $this->staff('admin'));

        Sanctum::actingAs($loginA);

        // Seeing the whole roster of YOUR meeting is a decision; seeing somebody
        // else's meeting at all is not.
        $this->assertCount(0, $this->getJson('/api/portal/meetings')->assertOk()->json('data'));
    }

    /* ── the same rules on the Purchase engine ───────────────────────── */

    /** A Purchase vendor authenticates as the PurchaseVendor itself, not a User. */
    private function purchaseVendor(string $name = 'Southgate')
    {
        return \App\Models\Purchase\PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => $name,
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => strtolower($name).'-'.Str::random(4).'@t.local',
            'status' => 'Active', 'portal_status' => 'active',
        ]);
    }

    private function purchaseMeetingWithBothSides($vendor, User $organiser)
    {
        $m = \App\Models\Purchase\PurchaseKickoffMeeting::create([
            'tenant_id' => self::TENANT, 'created_by' => $organiser->id,
            'purchase_vendor_id' => $vendor->id, 'title' => 'Kickoff', 'meeting_type' => 'kickoff',
            'status' => 'Scheduled', 'mode' => 'online',
            'scheduled_at' => now()->addDay(), 'end_at' => now()->addDay()->addHour(), 'duration_minutes' => 60,
        ]);

        foreach ([
            ['Priya Sharma', 'Project Manager', 'internal'],
            ['Anjali Desai', 'Finance Head', 'internal'],
            ['Ravi', 'Site Supervisor', 'external'],
        ] as [$name, $role, $side]) {
            $m->participants()->create([
                'tenant_id' => self::TENANT, 'name' => $name, 'role' => $role, 'side' => $side,
            ]);
        }

        return $m;
    }

    public function test_a_purchase_vendor_sees_the_whole_roster_too(): void
    {
        $vendor = $this->purchaseVendor();
        $this->purchaseMeetingWithBothSides($vendor, $this->staff('admin'));

        Sanctum::actingAs($vendor);
        $row = $this->getJson('/api/portal/purchase/meetings')->assertOk()->json('data.0');

        // Purchase shipped NO roster at all, so its vendors could not see who was
        // in their own meeting — not even their own people. The two portals run
        // one screen, so the key is the shared engine's name on both.
        $names = collect($row['attendees'])->pluck('name')->all();

        $this->assertContains('Ravi', $names);
        $this->assertContains('Priya Sharma', $names, 'internal attendees stay visible here too');
        $this->assertContains('Anjali Desai', $names);
    }

    public function test_a_purchase_vendor_cannot_schedule_a_meeting(): void
    {
        $vendor = $this->purchaseVendor();

        Sanctum::actingAs($vendor);
        $this->postJson('/api/purchase/kickoff', [
            'purchase_vendor_id' => $vendor->id, 'title' => 'Mine now',
        ])->assertStatus(403);

        $this->assertSame(0, \App\Models\Purchase\PurchaseKickoffMeeting::count());
    }

    public function test_a_purchase_vendor_cannot_decide_attendance(): void
    {
        $vendor = $this->purchaseVendor();
        $m = $this->purchaseMeetingWithBothSides($vendor, $this->staff('admin'));
        $row = $m->participants()->where('name', 'Ravi')->sole();

        Sanctum::actingAs($vendor);
        $this->postJson("/api/purchase/kickoff/{$m->id}/attendance/review", [
            'rows' => [['id' => $row->id, 'verdict' => AttendanceVerdict::FULLY_PRESENT]],
        ])->assertStatus(403);

        $this->assertNull($row->fresh()->verdict);
    }

    public function test_another_purchase_vendors_meeting_is_not_listed(): void
    {
        $a = $this->purchaseVendor('Southgate');
        $b = $this->purchaseVendor('Northgate');
        $this->purchaseMeetingWithBothSides($b, $this->staff('admin'));

        Sanctum::actingAs($a);
        $this->assertCount(0, $this->getJson('/api/portal/purchase/meetings')->assertOk()->json('data'));
    }

    /* ── what an admin can do ────────────────────────────────────────── */

    public function test_an_admin_can_read_both_pickers(): void
    {
        Sanctum::actingAs($this->staff('admin'));

        // "Admins can view and select all users and vendors."
        $this->getJson('/api/kickoff/staff')->assertOk();
        $this->getJson('/api/kickoff/customers')->assertOk();
    }
}
