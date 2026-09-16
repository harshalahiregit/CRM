<?php

namespace Tests\Feature\Shared;

use App\Models\Shared\KickoffAttendee;
use App\Models\Shared\KickoffMeeting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Shared\MeetingJoinRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * A join records WHERE it came from, in columns, on both engines.
 *
 * All of this used to be one sentence written into `remark`: "Opened the
 * meeting 14 Mar 2026, 10:04 from Mobile on Chrome (10.2.4.9)". Three
 * consequences, and each one is a test below:
 *
 *  - `remark` is the field a person types "joined late, apologised" into, so
 *    the evidence lived where anybody could overwrite it, and nothing could
 *    read it back — not the minutes, not the register, not a query;
 *  - the recorder checked `Schema::hasColumn(..., 'remark')` before writing,
 *    and Purchase's roster has no such column, so every Purchase meeting
 *    recorded nothing at all;
 *  - there was no location, and adding one by asking a geo-IP service would
 *    put a third party's guess into an attendance record. The browser offers
 *    coordinates or it does not, and "not shared" is the honest answer.
 */
class JoinEvidenceIsRecordedTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private User $user;

    private KickoffMeeting $meeting;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'status' => 'active',
        ])->save();

        $this->user = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Ravi Menon', 'email' => 'ravi@t1.test',
            'password' => bcrypt('secret-secret'), 'role' => 'admin', 'status' => 'active',
        ]);

        $this->meeting = KickoffMeeting::create([
            'tenant_id' => self::TENANT, 'title' => 'Site kickoff',
            'created_by' => $this->user->id,
            'scheduled_at' => now()->addHour(), 'end_at' => now()->addHours(2),
            'status' => 'Scheduled', 'mode' => 'online',
            'meeting_link' => 'https://meet.example.test/abc',
        ]);

        KickoffAttendee::create([
            'tenant_id' => self::TENANT, 'kickoff_meeting_id' => $this->meeting->id,
            'user_id' => $this->user->id, 'name' => 'Ravi Menon', 'email' => 'ravi@t1.test',
        ]);
    }

    private function join(array $body = [], array $server = []): KickoffAttendee
    {
        $request = Request::create('/join', 'POST', $body, [], [], $server);

        app(MeetingJoinRecorder::class)->record($this->meeting, $this->user, $request);

        return KickoffAttendee::where('kickoff_meeting_id', $this->meeting->id)->firstOrFail();
    }

    public function test_the_address_and_device_land_in_their_own_columns(): void
    {
        $row = $this->join([], [
            'REMOTE_ADDR' => '203.0.113.42',
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) AppleWebKit/605.1 '
                .'(KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
        ]);

        $this->assertSame('203.0.113.42', $row->join_ip);
        $this->assertSame('Mobile · Safari', $row->join_device,
            'the coarse device/browser classification is stored, so the register need not '
            .'re-parse a 200-character user-agent on every row it draws');
        $this->assertStringContainsString('iPhone', $row->join_user_agent);
    }

    /** Coordinates arrive from the browser, and only when it offered them. */
    public function test_a_shared_location_is_kept_and_a_withheld_one_is_null(): void
    {
        $row = $this->join(['latitude' => 12.9716, 'longitude' => 77.5946, 'location_label' => 'Bengaluru']);

        $this->assertEquals(12.9716, (float) $row->join_latitude);
        $this->assertEquals(77.5946, (float) $row->join_longitude);
        $this->assertSame('Bengaluru', $row->join_location_label);

        // A second meeting, joined without sharing anything.
        $other = KickoffMeeting::create([
            'tenant_id' => self::TENANT, 'title' => 'Second', 'created_by' => $this->user->id,
            'scheduled_at' => now()->addHour(), 'end_at' => now()->addHours(2),
            'status' => 'Scheduled', 'mode' => 'online', 'meeting_link' => 'https://meet.example.test/def',
        ]);
        KickoffAttendee::create([
            'tenant_id' => self::TENANT, 'kickoff_meeting_id' => $other->id,
            'user_id' => $this->user->id, 'name' => 'Ravi Menon', 'email' => 'ravi@t1.test',
        ]);

        app(MeetingJoinRecorder::class)->record($other, $this->user, Request::create('/join', 'POST'));

        $quiet = KickoffAttendee::where('kickoff_meeting_id', $other->id)->firstOrFail();

        $this->assertNull($quiet->join_latitude,
            'declining to share a location must leave it null — null prints as "not shared", '
            .'and a zero would print as the Gulf of Guinea');
        $this->assertNull($quiet->join_longitude);
    }

    /**
     * Nothing junk is accepted as a coordinate.
     *
     * The value comes from a request body, so it can be anything at all.
     */
    public function test_a_non_numeric_coordinate_is_ignored(): void
    {
        $row = $this->join(['latitude' => 'somewhere', 'longitude' => '']);

        $this->assertNull($row->join_latitude);
        $this->assertNull($row->join_longitude);
    }

    /**
     * And the evidence does not depend on a column only one engine has.
     *
     * The old code wrote its sentence into `remark`, guarded by a hasColumn
     * check — which Purchase fails. Whatever these columns are, both rosters
     * must carry them, or half the meetings in the system keep no record.
     */
    public function test_both_engines_carry_the_evidence_columns(): void
    {
        foreach (['kickoff_attendees', 'purchase_kickoff_participants'] as $table) {
            foreach ([
                'join_ip', 'join_user_agent', 'join_device',
                'join_latitude', 'join_longitude', 'join_location_label',
            ] as $column) {
                $this->assertTrue(
                    \Illuminate\Support\Facades\Schema::hasColumn($table, $column),
                    "{$table} is missing {$column} — join evidence would be silently dropped for "
                    .'every meeting on that engine, which is exactly the bug these columns replaced',
                );
            }
        }
    }

    /** The minutes print it, on both engines. */
    public function test_the_minutes_template_prints_the_evidence(): void
    {
        foreach (['kickoff_mom', 'purchase_kickoff_mom'] as $view) {
            $src = (string) file_get_contents(resource_path("views/pdf/{$view}.blade.php"));

            $this->assertStringContainsString('pdf.partials.join_evidence', $src,
                "{$view} no longer includes the join-evidence section; the minutes would assert "
                .'who attended without showing how that was established');
        }

        $partial = (string) file_get_contents(resource_path('views/pdf/partials/join_evidence.blade.php'));

        foreach (['join_ip', 'join_device', 'join_latitude', 'Not shared'] as $needle) {
            $this->assertStringContainsString($needle, $partial);
        }
    }
}
