<?php

namespace Tests\Feature\Shared;

use App\Models\Purchase\PurchaseKickoffMeeting;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Shared\KickoffMeeting;
use App\Models\Tenant;
use App\Models\Vendor\Vendor;
use App\Support\Purchase\PurchaseKickoffStatus;
use App\Support\Shared\KickoffStatus;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The meeting number must be unique per tenant, in BOTH engines.
 *
 * MTG-YYYY-NNNN is what the minutes print and what a vendor quotes in an e-mail,
 * so two meetings sharing one is a real problem — and neither table has a unique
 * index to catch it, so a duplicate is written silently rather than refused.
 *
 * Both engines generated it as count()+1, which only equals the highest number
 * issued while every row ever created is still present. Deleting one makes the
 * next meeting reuse a number. That became reachable the moment the register
 * grew a delete button.
 */
class MeetingNumberUniquenessTest extends TestCase
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

    private function sharedMeeting(string $title): KickoffMeeting
    {
        $vendor = Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'V'.Str::random(4),
            'status' => VendorStatus::ACTIVE,
        ]);

        return KickoffMeeting::create([
            'tenant_id' => self::TENANT, 'kickoffable_type' => Vendor::class,
            'kickoffable_id' => $vendor->id, 'title' => $title,
            'meeting_type' => 'kickoff', 'status' => KickoffStatus::SCHEDULED,
            'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'), 'duration_minutes' => 60,
        ]);
    }

    private function purchaseMeeting(string $title): PurchaseKickoffMeeting
    {
        $vendor = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'V'.Str::random(4),
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => Str::random(6).'@t.local', 'status' => 'Active', 'portal_status' => 'active',
        ]);

        return PurchaseKickoffMeeting::create([
            'tenant_id' => self::TENANT, 'purchase_vendor_id' => $vendor->id,
            'title' => $title, 'meeting_type' => 'kickoff',
            'status' => PurchaseKickoffStatus::SCHEDULED,
            'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'), 'duration_minutes' => 60,
        ]);
    }

    public function test_shared_meeting_numbers_survive_a_deletion(): void
    {
        $first  = $this->sharedMeeting('One');
        $second = $this->sharedMeeting('Two');

        // Hard-delete: this is what makes a count fall behind the highest issued.
        KickoffMeeting::withTrashed()->whereKey($first->id)->forceDelete();

        $third = $this->sharedMeeting('Three');

        $this->assertNotSame($second->meeting_no, $third->meeting_no,
            'a deleted meeting must not hand its number to the next one');
    }

    public function test_purchase_meeting_numbers_survive_a_deletion(): void
    {
        $first  = $this->purchaseMeeting('One');
        $second = $this->purchaseMeeting('Two');

        PurchaseKickoffMeeting::withTrashed()->whereKey($first->id)->forceDelete();

        $third = $this->purchaseMeeting('Three');

        $this->assertNotSame($second->meeting_no, $third->meeting_no,
            'a deleted meeting must not hand its number to the next one');
    }

    /**
     * A SOFT-deleted meeting still holds its number — it may be printed on
     * minutes already distributed — so the next meeting must step past it.
     */
    public function test_a_soft_deleted_meetings_number_is_never_reissued(): void
    {
        $held = $this->purchaseMeeting('Held');
        $held->delete();

        $next = $this->purchaseMeeting('Next');

        $this->assertNotSame($held->meeting_no, $next->meeting_no);
    }

    /**
     * No two meetings that EXIST at the same time may share a number.
     *
     * Deliberately not "no number is ever reused": a hard delete erases the row,
     * and with it the only record that the number was taken — nothing can read
     * it back. That is an acceptable limit, because a hard delete is a
     * deliberate erasure, and the number can only collide with a record that no
     * longer exists. A SOFT delete is different and is covered above: the row is
     * still there, may still be referenced, and keeps its number.
     */
    public function test_no_two_live_meetings_ever_share_a_number(): void
    {
        foreach (range(1, 5) as $i) {
            $m = $this->purchaseMeeting("M{$i}");

            if ($i % 2 === 0) {
                PurchaseKickoffMeeting::withTrashed()->whereKey($m->id)->forceDelete();
            }

            $live = PurchaseKickoffMeeting::pluck('meeting_no');
            $this->assertSame($live->count(), $live->unique()->count(),
                'two live meetings share a number after M'.$i.': '.$live->implode(', '));
        }
    }
}
