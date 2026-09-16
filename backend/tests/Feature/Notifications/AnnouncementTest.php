<?php

namespace Tests\Feature\Notifications;

use App\Models\Hr\HrEmployee;
use App\Models\Notification;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Announcements composed by hand in the Notification Center.
 *
 * The behaviour worth protecting is that pressing Send actually sends. The push
 * items used to be created Pending and left for a scheduled sweep, which on a
 * machine with no scheduler running meant they were never delivered at all —
 * and that looked exactly like "push does not work" while push was working
 * perfectly.
 */
class AnnouncementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private array $staff = [];

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'S', 'slug' => 'ann-t', 'status' => 'active']);

        $this->admin = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Admin', 'email' => 'a@example.test',
            'password' => Hash::make('x'), 'role' => 'admin', 'status' => 'active',
        ]);

        foreach ([['Ops', 'Ravi'], ['Ops', 'Meera'], ['Sales', 'Arjun']] as $i => [$dept, $name]) {
            $u = User::create([
                'tenant_id' => $tenant->id, 'name' => $name, 'email' => "s{$i}@example.test",
                'password' => Hash::make('x'), 'role' => 'staff', 'status' => 'active',
            ]);
            HrEmployee::create([
                'tenant_id' => $tenant->id, 'employee_code' => "E{$i}", 'name' => $name,
                'department' => $dept, 'designation' => 'Analyst', 'joining_date' => '2020-01-01',
                'status' => 'Active', 'user_id' => $u->id,
            ]);
            $this->staff[] = $u;
        }

        Sanctum::actingAs($this->admin);
    }

    private function send(array $over = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/hr/notifications/announcements', array_merge([
            'title'    => 'Diwali holiday',
            'body'     => 'Office closed on the 20th.',
            'audience' => 'all',
        ], $over));
    }

    public function test_everyone_gets_it_in_the_app_and_in_the_crm(): void
    {
        $this->send()->assertOk()->assertJsonPath('recipients', 3);

        // Both in-app stores: hr_notifications is the CRM bell, notifications is
        // what the phone reads. One only would reach the office but not the site.
        $this->assertSame(3, DB::table('hr_notifications')->where('title', 'Diwali holiday')->count());
        $this->assertSame(3, Notification::where('title', 'Diwali holiday')->count());
    }

    /** Pressing Send sends. Nothing may be left waiting on a scheduled sweep. */
    public function test_nothing_is_left_pending_for_a_cron(): void
    {
        $this->send()->assertOk();

        $this->assertSame(
            0,
            DB::table('hr_notification_queue')->where('status', 'Pending')->count(),
            'A queue item left Pending is an announcement that silently never arrives.',
        );
    }

    public function test_a_department_gets_it_and_the_others_do_not(): void
    {
        $this->send(['audience' => 'department', 'department' => 'Ops'])
            ->assertOk()->assertJsonPath('recipients', 2);

        $titles = Notification::where('title', 'Diwali holiday')->pluck('user_id');
        $this->assertContains($this->staff[0]->id, $titles);
        $this->assertNotContains($this->staff[2]->id, $titles, 'Sales must not receive an Ops announcement.');
    }

    public function test_specific_people_get_it(): void
    {
        $this->send(['audience' => 'employees', 'user_ids' => [$this->staff[1]->id]])
            ->assertOk()->assertJsonPath('recipients', 1);
    }

    /**
     * An empty audience is refused rather than reported as a send.
     *
     * "Sent to 0 people" is the kind of success message somebody reads as done
     * and walks away from.
     */
    public function test_an_audience_that_matches_nobody_is_refused(): void
    {
        $this->send(['audience' => 'department', 'department' => 'Nonexistent'])
            ->assertStatus(422);

        $this->assertSame(0, Notification::where('title', 'Diwali holiday')->count());
    }

    /** The reach reported is people, not devices — one person may carry two. */
    public function test_reach_is_reported_even_when_no_phone_is_registered(): void
    {
        $this->send()->assertOk()->assertJsonPath('pushed', 0)
            ->assertJsonFragment(['recipients' => 3]);
    }
}
