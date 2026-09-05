<?php

namespace Tests\Feature\Purchase;

use App\Models\Purchase\PurchaseKickoffMeeting;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Tenant;
use App\Support\Purchase\PurchaseKickoffStatus;
use App\Support\Shared\BusinessTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The single-meeting summary the Purchase dashboard reads for its join popup.
 *
 * This payload is hand-built rather than serialised from the model, so the
 * model's appended timing does NOT ride along — it has to be named explicitly.
 * It was not, so the dashboard read is_expired as undefined, `!undefined` is
 * true, and the popup offered "Join the meeting" for one that had ended two
 * days earlier.
 *
 * The same method also chose the meeting with latest() — by CREATION date, and
 * without excluding drafts, which are invisible to a vendor everywhere else.
 */
class PurchasePortalKickoffSummaryTest extends TestCase
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

    private function clock(int $minutesFromNow): string
    {
        return BusinessTime::now(self::TENANT)->copy()->addMinutes($minutesFromNow)->format('Y-m-d H:i:s');
    }

    private function meeting(string $title, int $startsIn, string $status = PurchaseKickoffStatus::SCHEDULED): PurchaseKickoffMeeting
    {
        return PurchaseKickoffMeeting::create([
            'tenant_id' => self::TENANT, 'purchase_vendor_id' => $this->vendor->id,
            'title' => $title, 'meeting_type' => 'kickoff', 'status' => $status,
            'mode' => 'online', 'meeting_link' => 'https://meet.example.test/room',
            'scheduled_at' => $this->clock($startsIn), 'end_at' => $this->clock($startsIn + 60),
            'duration_minutes' => 60,
        ]);
    }

    private function summary(): array
    {
        Sanctum::actingAs($this->vendor);

        return $this->getJson('/api/portal/purchase/kickoff')->assertOk()->json('meeting') ?? [];
    }

    public function test_the_summary_carries_the_timing_the_dashboard_needs(): void
    {
        $this->meeting('Kickoff', 1440);

        $m = $this->summary();

        // Every one of these is read by the join popup. A missing field was the
        // whole bug: absent is not the same as false.
        foreach (['ends_at', 'timing_state', 'timing_label', 'is_expired', 'is_live'] as $key) {
            $this->assertArrayHasKey($key, $m, "the dashboard needs {$key}");
        }
        $this->assertFalse($m['is_expired']);
        $this->assertSame('upcoming', $m['timing_state']);
    }

    public function test_an_expired_meeting_says_so_and_withholds_its_link(): void
    {
        $this->meeting('Ended already', -300);

        $m = $this->summary();

        $this->assertTrue($m['is_expired'], 'the popup keys off this');
        $this->assertSame('expired', $m['timing_state']);
        $this->assertNull($m['meeting_link']);
    }

    public function test_a_meeting_in_progress_keeps_its_link(): void
    {
        $this->meeting('Running now', -10);

        $m = $this->summary();

        $this->assertTrue($m['is_live']);
        $this->assertNotNull($m['meeting_link']);
    }

    /** A draft is invisible to the vendor everywhere else; it must be here too. */
    public function test_a_draft_is_never_offered_to_the_vendor(): void
    {
        $this->meeting('Not published', 1440, PurchaseKickoffStatus::DRAFT);

        $this->assertSame([], $this->summary());
    }

    /**
     * The meeting that needs the vendor wins — not whichever row was written
     * last. Created in reverse order on purpose.
     */
    public function test_the_next_due_meeting_wins_over_an_older_expired_one(): void
    {
        $this->meeting('Upcoming', 120);      // created first, happens later
        $this->meeting('Long over', -3000);   // created last, already gone

        $this->assertSame('Upcoming', $this->summary()['title']);
    }

    public function test_a_meeting_in_progress_wins_over_one_merely_upcoming(): void
    {
        $this->meeting('Later today', 300);
        $this->meeting('Happening now', -10);

        $this->assertSame('Happening now', $this->summary()['title']);
    }

    /**
     * A CANCELLED meeting is not "expired" — it never ran at all — so a test
     * written as `!is_expired` let it keep a working join link. The rule has to
     * be positive: a link is offered only while the meeting is going to happen.
     */
    public function test_a_cancelled_meeting_hands_out_no_join_link(): void
    {
        $this->meeting('Called off', 240, PurchaseKickoffStatus::CANCELLED);

        $m = $this->summary();

        $this->assertFalse($m['is_expired'], 'cancelled is not expired — that is the trap');
        $this->assertNull($m['meeting_link'], 'a cancelled meeting must not be joinable');
    }

    /** With nothing ahead, the most recent past meeting still has something to show. */
    public function test_a_past_meeting_is_shown_when_nothing_is_due(): void
    {
        $this->meeting('Ancient', -9000);
        $this->meeting('Most recent', -300);

        $m = $this->summary();
        $this->assertSame('Most recent', $m['title']);
        $this->assertTrue($m['is_expired']);
    }
}
