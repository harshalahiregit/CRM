<?php

namespace Tests\Feature\Shared;

use App\Models\Purchase\PurchaseKickoffMeeting;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Shared\KickoffMeeting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\Purchase\PurchaseKickoffStatus;
use App\Support\Shared\BusinessTime;
use App\Support\Shared\KickoffStatus;
use App\Support\Shared\MomApprovalStatus;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Who can do what to a kickoff meeting — audited by asking, as each actor.
 *
 * The meeting surface is 147 routes: 134 behind role:admin,staff and 13 read-only
 * portal endpoints. That says who may KNOCK, not what they get, so this exercises
 * the actual capabilities as six identities:
 *
 *   admin · staff · TPV vendor · Purchase vendor · doctor · nobody
 *
 * Both engines, because they share no code: a rule proven on one says nothing
 * about the other.
 */
class KickoffMeetingAccessAuditTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private Vendor $vendor;
    private PurchaseVendor $pVendor;
    private KickoffMeeting $meeting;
    private PurchaseKickoffMeeting $pMeeting;

    protected function setUp(): void
    {
        parent::setUp();
        BusinessTime::flush();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();

        $this->vendor = Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Acme', 'status' => VendorStatus::ACTIVE,
        ]);
        $this->vendor->forceFill(['user_id' => $this->user('third_party_vendor')->id])->save();

        $this->pVendor = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Southgate',
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => 'sg-'.Str::random(4).'@t.local',
            'status' => 'Active', 'portal_status' => 'active',
        ]);

        $this->meeting = KickoffMeeting::create([
            'tenant_id' => self::TENANT, 'kickoffable_type' => Vendor::class,
            'kickoffable_id' => $this->vendor->id, 'title' => 'Shared kickoff',
            'meeting_type' => 'kickoff', 'status' => KickoffStatus::SCHEDULED,
            'scheduled_at' => $this->clock(120), 'end_at' => $this->clock(180),
            'duration_minutes' => 60, 'mom_status' => MomApprovalStatus::DRAFT,
        ]);

        $this->pMeeting = PurchaseKickoffMeeting::create([
            'tenant_id' => self::TENANT, 'purchase_vendor_id' => $this->pVendor->id,
            'title' => 'Purchase kickoff', 'meeting_type' => 'kickoff',
            'status' => PurchaseKickoffStatus::SCHEDULED,
            'scheduled_at' => $this->clock(120), 'end_at' => $this->clock(180),
            'duration_minutes' => 60,
        ]);
    }

    private function clock(int $mins): string
    {
        return BusinessTime::now(self::TENANT)->copy()->addMinutes($mins)->format('Y-m-d H:i:s');
    }

    private function user(string $role): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => ucfirst($role), 'role' => $role,
            'email' => $role.'-'.Str::random(6).'@t.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    /* ── 1. The admin surface is closed to everyone but admin and staff ───── */

    public function test_no_portal_identity_can_reach_the_admin_meeting_surface(): void
    {
        $endpoints = [
            ['get',  '/api/kickoff/meetings'],
            ['get',  "/api/kickoff/meetings/{$this->meeting->id}"],
            ['post', '/api/kickoff/meetings'],
            ['put',  "/api/kickoff/meetings/{$this->meeting->id}"],
            ['delete', "/api/kickoff/meetings/{$this->meeting->id}"],
            ['get',  '/api/purchase/kickoff'],
            ['delete', "/api/purchase/kickoff/{$this->pMeeting->id}"],
        ];

        // A doctor and a TPV vendor are both Users, so only the role gate stands
        // between them and 134 admin routes. That gate is the thing under test.
        foreach (['third_party_vendor', 'doctor', 'client', 'company'] as $role) {
            $actor = $this->user($role);
            Sanctum::actingAs($actor);

            foreach ($endpoints as [$verb, $uri]) {
                $status = $this->{$verb.'Json'}($uri)->getStatusCode();
                $this->assertSame(403, $status,
                    "{$role} reached {$verb} {$uri} with {$status} — the admin surface must refuse them");
            }
        }
    }

    public function test_a_purchase_vendor_cannot_reach_the_admin_meeting_surface(): void
    {
        // Not a User at all — it must be refused as the wrong identity, never
        // let through and never signed out (403, not 401).
        Sanctum::actingAs($this->pVendor);

        foreach (['/api/kickoff/meetings', '/api/purchase/kickoff'] as $uri) {
            $this->assertSame(403, $this->getJson($uri)->getStatusCode(),
                "a Purchase vendor reached {$uri}");
        }
    }

    public function test_an_anonymous_caller_gets_nothing(): void
    {
        foreach ([
            '/api/kickoff/meetings', '/api/purchase/kickoff',
            '/api/portal/meetings', '/api/portal/purchase/meetings',
        ] as $uri) {
            $this->assertSame(401, $this->getJson($uri)->getStatusCode(),
                "{$uri} answered an anonymous caller");
        }
    }

    /* ── 2. Staff hold the same power as admins ──────────────────────────── */

    /**
     * Documented, not asserted as correct: every meeting route is
     * role:admin,staff, so a staff member can delete a meeting — its minutes,
     * actions and issue register with it — exactly as an admin can. The UI hides
     * the control from them; the API does not.
     *
     * Flagged rather than changed: the route group is shared with the TPV
     * module, so tightening it is a decision for the module's owner.
     */
    public function test_staff_can_delete_a_meeting_just_like_an_admin(): void
    {
        Sanctum::actingAs($this->user('staff'));

        $this->deleteJson("/api/kickoff/meetings/{$this->meeting->id}")->assertOk();
        $this->assertSoftDeleted('kickoff_meetings', ['id' => $this->meeting->id]);
    }

    /* ── 3. What a vendor sees of its OWN meetings ───────────────────────── */

    public function test_a_tpv_vendor_sees_its_own_meeting_and_not_another_vendors(): void
    {
        $rival = Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Rival', 'status' => VendorStatus::ACTIVE,
        ]);
        KickoffMeeting::create([
            'tenant_id' => self::TENANT, 'kickoffable_type' => Vendor::class,
            'kickoffable_id' => $rival->id, 'title' => 'Not yours',
            'meeting_type' => 'kickoff', 'status' => KickoffStatus::SCHEDULED,
            'scheduled_at' => $this->clock(60), 'end_at' => $this->clock(120), 'duration_minutes' => 60,
        ]);

        Sanctum::actingAs(User::find($this->vendor->user_id));
        $titles = collect($this->getJson('/api/portal/meetings')->assertOk()->json('data'))->pluck('title');

        $this->assertContains('Shared kickoff', $titles->all());
        $this->assertNotContains('Not yours', $titles->all());
    }

    public function test_a_purchase_vendor_sees_its_own_meeting_and_not_another_vendors(): void
    {
        $rival = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Rival',
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => 'rv-'.Str::random(4).'@t.local',
            'status' => 'Active', 'portal_status' => 'active',
        ]);
        PurchaseKickoffMeeting::create([
            'tenant_id' => self::TENANT, 'purchase_vendor_id' => $rival->id,
            'title' => 'Not yours', 'meeting_type' => 'kickoff',
            'status' => PurchaseKickoffStatus::SCHEDULED,
            'scheduled_at' => $this->clock(60), 'end_at' => $this->clock(120), 'duration_minutes' => 60,
        ]);

        Sanctum::actingAs($this->pVendor);
        $titles = collect($this->getJson('/api/portal/purchase/meetings')->assertOk()->json('data'))->pluck('title');

        $this->assertContains('Purchase kickoff', $titles->all());
        $this->assertNotContains('Not yours', $titles->all());
    }

    /* ── 4. The minutes are gated on approval, in both engines ───────────── */

    public function test_minutes_are_withheld_until_distributed_in_both_engines(): void
    {
        Sanctum::actingAs(User::find($this->vendor->user_id));
        $this->assertSame(403, $this->getJson("/api/portal/meetings/{$this->meeting->id}/mom")->getStatusCode(),
            'draft minutes must not be readable by the vendor');

        Sanctum::actingAs($this->pVendor);
        $this->assertSame(403, $this->getJson("/api/portal/purchase/meetings/{$this->pMeeting->id}/mom")->getStatusCode(),
            'draft minutes must not be readable by the Purchase vendor');
    }

    public function test_a_vendor_cannot_read_another_vendors_minutes_even_when_distributed(): void
    {
        $rival = Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Rival', 'status' => VendorStatus::ACTIVE,
        ]);
        $theirs = KickoffMeeting::create([
            'tenant_id' => self::TENANT, 'kickoffable_type' => Vendor::class,
            'kickoffable_id' => $rival->id, 'title' => 'Theirs',
            'meeting_type' => 'kickoff', 'status' => KickoffStatus::COMPLETED,
            'scheduled_at' => $this->clock(-300), 'end_at' => $this->clock(-240),
            'duration_minutes' => 60, 'mom_status' => MomApprovalStatus::DISTRIBUTED,
        ]);

        Sanctum::actingAs(User::find($this->vendor->user_id));

        $this->assertSame(404, $this->getJson("/api/portal/meetings/{$theirs->id}/mom")->getStatusCode(),
            'distributed minutes are still only the OWNING vendor\'s to read');
    }

    /* ── 5. Drafts are invisible to every vendor ─────────────────────────── */

    public function test_a_draft_meeting_is_invisible_to_both_vendors(): void
    {
        $this->meeting->update(['status' => KickoffStatus::DRAFT]);
        $this->pMeeting->update(['status' => PurchaseKickoffStatus::DRAFT]);

        Sanctum::actingAs(User::find($this->vendor->user_id));
        $this->assertCount(0, $this->getJson('/api/portal/meetings')->assertOk()->json('data'));

        Sanctum::actingAs($this->pVendor);
        $this->assertCount(0, $this->getJson('/api/portal/purchase/meetings')->assertOk()->json('data'));
    }

    /* ── 6. Tenant isolation, which no role check covers ─────────────────── */

    public function test_an_admin_cannot_touch_another_tenants_meeting(): void
    {
        (new Tenant())->forceFill([
            'id' => 2, 'name' => 'T2', 'slug' => 't2', 'subdomain' => 't2', 'status' => 'active',
        ])->save();

        $otherVendor = Vendor::create([
            'tenant_id' => 2, 'company_name' => 'Other', 'status' => VendorStatus::ACTIVE,
        ]);
        $theirs = KickoffMeeting::create([
            'tenant_id' => 2, 'kickoffable_type' => Vendor::class, 'kickoffable_id' => $otherVendor->id,
            'title' => 'Other tenant', 'meeting_type' => 'kickoff', 'status' => KickoffStatus::SCHEDULED,
            'scheduled_at' => $this->clock(60), 'end_at' => $this->clock(120), 'duration_minutes' => 60,
        ]);

        Sanctum::actingAs($this->user('admin'));

        foreach ([
            ['get',    "/api/kickoff/meetings/{$theirs->id}"],
            ['put',    "/api/kickoff/meetings/{$theirs->id}"],
            ['delete', "/api/kickoff/meetings/{$theirs->id}"],
        ] as [$verb, $uri]) {
            $this->assertSame(404, $this->{$verb.'Json'}($uri)->getStatusCode(),
                "a tenant-1 admin reached {$verb} {$uri} in tenant 2");
        }

        $this->assertDatabaseHas('kickoff_meetings', ['id' => $theirs->id, 'deleted_at' => null]);
    }
}
