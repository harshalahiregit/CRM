<?php

namespace Tests\Feature\Purchase;

use App\Models\Purchase\PurchaseKickoffMeeting;
use App\Models\Purchase\PurchaseKickoffParticipant;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Purchase\PurchaseKickoffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Reminders in BOTH directions.
 *
 * runDueReminders only ever looked forward (`scheduled_at > now`), so the moment
 * a meeting started nothing further was sent — minutes could sit unwritten with
 * no nudge to anyone. And rescheduling left reminders_sent intact, so a meeting
 * whose 24h reminder had already gone out could be pushed a week later and never
 * remind anyone again.
 */
class PurchaseMeetingFollowUpTest extends TestCase
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

    private function admin(): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'a-'.Str::random(6).'@test.local',
            'password' => bcrypt('secret'), 'status' => 'active',
        ]);
    }

    private function meeting(string $status, $scheduledAt): PurchaseKickoffMeeting
    {
        $v = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'FollowCo',
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => Str::random(5).'@test.local', 'status' => 'Draft', 'portal_status' => 'active',
        ]);
        $m = PurchaseKickoffMeeting::create([
            'tenant_id' => self::TENANT, 'purchase_vendor_id' => $v->id,
            'title' => 'Weekly', 'meeting_type' => 'kickoff',
            'status' => $status, 'scheduled_at' => $scheduledAt,
        ]);
        PurchaseKickoffParticipant::create([
            'tenant_id' => self::TENANT, 'purchase_kickoff_meeting_id' => $m->id,
            'name' => 'Attendee', 'email' => 'attendee@test.local',
        ]);

        return $m;
    }

    /** A meeting held 3 hours ago gets its follow-up; the 120-minute window is due. */
    public function test_follow_up_fires_after_the_meeting(): void
    {
        $m = $this->meeting('Completed', now()->subHours(3));

        $this->assertSame(1, app(PurchaseKickoffService::class)->runDueFollowUps());

        $keys = $m->fresh()->reminders_sent ?? [];
        $this->assertContains('after:120', $keys, 'the 2-hour follow-up window was not recorded');
    }

    /** Idempotent — a second run must not send the same window twice. */
    public function test_follow_up_is_not_repeated(): void
    {
        $this->meeting('Completed', now()->subHours(3));
        $svc = app(PurchaseKickoffService::class);

        $this->assertSame(1, $svc->runDueFollowUps());
        $this->assertSame(0, $svc->runDueFollowUps(), 'the follow-up fired twice');
    }

    /** A meeting still ahead is not followed up — that is what reminders are for. */
    public function test_future_meeting_gets_no_follow_up(): void
    {
        $this->meeting('Scheduled', now()->addHours(3));

        $this->assertSame(0, app(PurchaseKickoffService::class)->runDueFollowUps());
    }

    /** Once the minutes are out there is nothing left to chase. */
    public function test_distributed_minutes_stop_the_follow_up(): void
    {
        $m = $this->meeting('Completed', now()->subHours(3));
        $m->forceFill(['mom_status' => \App\Support\Purchase\PurchaseMomApprovalStatus::DISTRIBUTED])->save();

        $this->assertSame(0, app(PurchaseKickoffService::class)->runDueFollowUps());
    }

    /**
     * Moving a meeting re-arms its reminders. Without the reset, a window
     * already listed in reminders_sent is skipped forever.
     */
    public function test_rescheduling_clears_the_fired_reminder_windows(): void
    {
        $m = $this->meeting('Scheduled', now()->addHours(2));
        $m->forceFill(['reminders_sent' => ['1440', '60']])->save();

        app(PurchaseKickoffService::class)->update(
            $m,
            ['scheduled_at' => now()->addDays(7)->toDateTimeString(), 'end_at' => now()->addDays(7)->addHour()->toDateTimeString()],
            $this->admin()
        );

        $this->assertSame([], $m->fresh()->reminders_sent ?? [], 'reminders were not re-armed after the move');
    }

    /** An edit that does NOT move the meeting leaves the ledger alone. */
    public function test_non_schedule_edit_keeps_the_reminder_ledger(): void
    {
        $m = $this->meeting('Scheduled', now()->addHours(2));
        $m->forceFill(['reminders_sent' => ['1440']])->save();

        app(PurchaseKickoffService::class)->update($m, ['title' => 'Renamed'], $this->admin());

        $this->assertSame(['1440'], $m->fresh()->reminders_sent ?? [], 'a rename wiped the reminder ledger');
    }
}
