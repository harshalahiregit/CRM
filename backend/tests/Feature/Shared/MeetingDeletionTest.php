<?php

namespace Tests\Feature\Shared;

use App\Models\Purchase\PurchaseKickoffMeeting;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Shared\KickoffMeeting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\Purchase\PurchaseKickoffStatus;
use App\Support\Shared\KickoffStatus;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Deleting a meeting, in both engines.
 *
 * The endpoints existed but nothing in the product called them — the meetings
 * register had no delete control at all — so they were entirely untested. Now
 * that the register offers a row delete and a bulk delete, what they reach is
 * worth pinning: the meeting goes, it leaves every list, and it cannot be
 * reached across a tenant boundary.
 */
class MeetingDeletionTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;
    private const OTHER  = 2;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::TENANT, self::OTHER] as $id) {
            (new Tenant())->forceFill([
                'id' => $id, 'name' => 'T'.$id, 'slug' => 't'.$id,
                'subdomain' => 't'.$id, 'status' => 'active',
            ])->save();
        }
    }

    private function admin(int $tenant = self::TENANT): User
    {
        return User::create([
            'tenant_id' => $tenant, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'a-'.Str::random(6).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function sharedMeeting(int $tenant = self::TENANT): KickoffMeeting
    {
        $vendor = Vendor::create([
            'tenant_id' => $tenant, 'company_name' => 'Acme '.Str::random(4), 'status' => VendorStatus::ACTIVE,
        ]);

        return KickoffMeeting::create([
            'tenant_id' => $tenant, 'kickoffable_type' => Vendor::class, 'kickoffable_id' => $vendor->id,
            'title' => 'Kickoff', 'meeting_type' => 'kickoff', 'status' => KickoffStatus::SCHEDULED,
            'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'), 'duration_minutes' => 60,
        ]);
    }

    private function purchaseMeeting(int $tenant = self::TENANT): PurchaseKickoffMeeting
    {
        $vendor = PurchaseVendor::create([
            'tenant_id' => $tenant, 'company_name' => 'Acme '.Str::random(4),
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => 'p-'.Str::random(5).'@t.local', 'status' => 'Active', 'portal_status' => 'active',
        ]);

        return PurchaseKickoffMeeting::create([
            'tenant_id' => $tenant, 'purchase_vendor_id' => $vendor->id,
            'title' => 'Kickoff', 'meeting_type' => 'kickoff', 'status' => PurchaseKickoffStatus::SCHEDULED,
            'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'), 'duration_minutes' => 60,
        ]);
    }

    /* ── Shared engine ───────────────────────────────────────────────────── */

    public function test_a_shared_meeting_can_be_deleted(): void
    {
        Sanctum::actingAs($this->admin());
        $meeting = $this->sharedMeeting();

        $this->deleteJson("/api/kickoff/meetings/{$meeting->id}")->assertOk();

        $this->assertSoftDeleted('kickoff_meetings', ['id' => $meeting->id]);
    }

    public function test_a_deleted_shared_meeting_leaves_the_register(): void
    {
        Sanctum::actingAs($this->admin());
        $keep = $this->sharedMeeting();
        $drop = $this->sharedMeeting();

        $this->deleteJson("/api/kickoff/meetings/{$drop->id}")->assertOk();

        // The register answers with a bare array, not a {data:[...]} envelope.
        $body = $this->getJson('/api/kickoff/meetings')->assertOk()->json();
        $ids  = collect($body['data'] ?? $body)->pluck('id');
        $this->assertContains($keep->id, $ids->all());
        $this->assertNotContains($drop->id, $ids->all());
    }

    public function test_a_meeting_from_another_tenant_cannot_be_deleted(): void
    {
        Sanctum::actingAs($this->admin(self::TENANT));
        $theirs = $this->sharedMeeting(self::OTHER);

        $this->deleteJson("/api/kickoff/meetings/{$theirs->id}")->assertStatus(404);

        $this->assertDatabaseHas('kickoff_meetings', ['id' => $theirs->id, 'deleted_at' => null]);
    }

    /** The bulk delete is N calls, so deleting several must simply work N times. */
    public function test_several_meetings_can_be_deleted_in_succession(): void
    {
        Sanctum::actingAs($this->admin());
        $meetings = collect(range(1, 3))->map(fn () => $this->sharedMeeting());

        foreach ($meetings as $m) {
            $this->deleteJson("/api/kickoff/meetings/{$m->id}")->assertOk();
        }

        $this->assertSame(0, KickoffMeeting::count());
        $this->assertSame(3, KickoffMeeting::withTrashed()->count());
    }

    public function test_deleting_a_meeting_that_is_already_gone_is_not_found(): void
    {
        // The bulk delete tolerates this — two admins clearing the same row —
        // and reports it rather than abandoning the rest of the batch.
        Sanctum::actingAs($this->admin());
        $meeting = $this->sharedMeeting();

        $this->deleteJson("/api/kickoff/meetings/{$meeting->id}")->assertOk();
        $this->deleteJson("/api/kickoff/meetings/{$meeting->id}")->assertStatus(404);
    }

    /* ── Purchase engine ─────────────────────────────────────────────────── */

    public function test_a_purchase_meeting_can_be_deleted(): void
    {
        Sanctum::actingAs($this->admin());
        $meeting = $this->purchaseMeeting();

        $this->deleteJson("/api/purchase/kickoff/{$meeting->id}")->assertOk();

        $this->assertSoftDeleted('purchase_kickoff_meetings', ['id' => $meeting->id]);
    }

    public function test_a_purchase_meeting_from_another_tenant_cannot_be_deleted(): void
    {
        Sanctum::actingAs($this->admin(self::TENANT));
        $theirs = $this->purchaseMeeting(self::OTHER);

        $this->deleteJson("/api/purchase/kickoff/{$theirs->id}")->assertStatus(404);

        $this->assertDatabaseHas('purchase_kickoff_meetings', ['id' => $theirs->id, 'deleted_at' => null]);
    }
}
