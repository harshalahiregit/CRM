<?php

namespace Tests\Feature\Notifications;

use App\Models\Notifications\HrNotification;
use App\Models\Tenant;
use App\Models\User;
use App\Repositories\Notifications\NotificationRepository;
use App\Services\Notifications\NotificationRoleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A notification addressed to a role now reaches that role, and only it.
 *
 * The engine has always addressed notifications either to a user id or to a
 * role string — 'hr', 'hr_manager', 'department_head', 'admin', the four the
 * escalation ladder produces. The role was stored and then never read:
 * visibleTo() showed EVERY role-targeted row to anyone who could manage the HR
 * queue. So a reminder escalated to a department head landed in the inbox of
 * every HR executive and nowhere near a department head, and escalating to
 * 'admin' distinguished nobody from anybody.
 *
 * The fix maps each ladder role onto a predicate the User model already
 * answers, so there is one role vocabulary rather than a second one invented
 * here. 'hr' deliberately resolves to exactly canManageHrQueue() — the
 * population that could see those rows before still can, which is the half of
 * this that must NOT move.
 */
class RoleTargetedVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Ops', 'slug' => 'notif-roles', 'status' => 'active']);
    }

    /* ── fixture ──────────────────────────────────────────────────────── */

    private function user(string $role = 'staff', ?string $internal = null, ?Tenant $tenant = null): User
    {
        return User::create([
            'tenant_id' => ($tenant ?: $this->tenant)->id, 'name' => 'U',
            'email' => uniqid().'@roles.test', 'password' => Hash::make('Password123!'),
            'role' => $role, 'status' => 'active', 'internal_role' => $internal,
        ]);
    }

    private function toRole(string $role, ?Tenant $tenant = null): HrNotification
    {
        return HrNotification::create([
            'tenant_id' => ($tenant ?: $this->tenant)->id, 'module' => 'Leave',
            'event' => 'Pending Approval', 'priority' => 'Warning',
            'notification_type' => 'reminder', 'title' => "For {$role}",
            'message' => 'Something is waiting.', 'recipient_role' => $role,
        ]);
    }

    private function toUser(User $user): HrNotification
    {
        return HrNotification::create([
            'tenant_id' => $user->tenant_id, 'module' => 'Leave', 'event' => 'Approved',
            'priority' => 'Success', 'notification_type' => 'event',
            'title' => 'Yours', 'message' => 'Your leave was approved.',
            'recipient_user_id' => $user->id,
        ]);
    }

    /** What this user can actually see. */
    private function visible(User $user): array
    {
        return app(NotificationRepository::class)->visibleTo($user)->pluck('title')->all();
    }

    /* ── 1. the matching role receives it ─────────────────────────────── */

    public function test_a_department_head_receives_a_department_head_notification(): void
    {
        $head = $this->user('staff', 'department_head');
        $this->toRole('department_head');

        $this->assertSame(['For department_head'], $this->visible($head));
    }

    public function test_an_hr_manager_receives_an_hr_manager_notification(): void
    {
        $manager = $this->user('staff', 'hr_manager');
        $this->toRole('hr_manager');

        $this->assertContains('For hr_manager', $this->visible($manager));
    }

    public function test_an_admin_receives_an_admin_notification(): void
    {
        $admin = $this->user('admin');
        $this->toRole('admin');

        $this->assertContains('For admin', $this->visible($admin));
    }

    /* ── 2. the non-matching HR user does not ─────────────────────────── */

    public function test_an_hr_executive_does_not_receive_a_department_head_notification(): void
    {
        $hr = $this->user('staff', 'hr_executive');
        $this->assertTrue($hr->canManageHrQueue(), 'fixture check: this user IS on the HR queue');

        $this->toRole('department_head');

        // THE defect. Before this, holding the HR queue meant seeing every
        // role-targeted row in the workspace, whoever it was addressed to.
        $this->assertSame([], $this->visible($hr));
    }

    public function test_an_hr_executive_does_not_receive_an_hr_manager_notification(): void
    {
        $hr = $this->user('staff', 'hr_executive');
        $this->toRole('hr_manager');

        $this->assertSame([], $this->visible($hr));
    }

    public function test_a_department_head_does_not_receive_an_hr_notification(): void
    {
        $head = $this->user('staff', 'department_head');
        $this->assertFalse($head->canManageHrQueue(), 'fixture check: not on the HR queue');

        $this->toRole('hr');

        // Narrowing has to cut both ways, or it is just a different leak.
        $this->assertSame([], $this->visible($head));
    }

    /* ── 3. existing hr behaviour is unchanged ────────────────────────── */

    public function test_an_hr_executive_still_receives_hr_notifications(): void
    {
        $hr = $this->user('staff', 'hr_executive');
        $this->toRole('hr');

        // The half that must not move: 'hr' resolves to exactly the population
        // that could see these rows before.
        $this->assertSame(['For hr'], $this->visible($hr));
    }

    public function test_an_hr_recruiter_still_receives_hr_notifications(): void
    {
        $recruiter = $this->user('staff', 'hr_recruiter');
        $this->assertTrue($recruiter->canManageHrQueue());

        $this->toRole('hr');

        $this->assertSame(['For hr'], $this->visible($recruiter));
    }

    public function test_an_admin_still_receives_hr_notifications(): void
    {
        $admin = $this->user('admin');
        $this->toRole('hr');

        // An admin passes canManageHrQueue(), so nothing they saw is lost.
        $this->assertContains('For hr', $this->visible($admin));
    }

    /* ── 4. user-addressed notifications are untouched ────────────────── */

    public function test_a_user_still_receives_their_own_notification(): void
    {
        $person = $this->user('staff', null);
        $this->toUser($person);

        $this->assertSame(['Yours'], $this->visible($person));
    }

    public function test_one_users_notification_does_not_reach_another(): void
    {
        $mine = $this->user('staff', null);
        $theirs = $this->user('staff', null);
        $this->toUser($theirs);

        $this->assertSame([], $this->visible($mine));
    }

    public function test_an_admin_does_not_see_another_users_personal_notification(): void
    {
        $person = $this->user('staff', null);
        $this->toUser($person);

        // Role resolution must not have quietly widened user-addressed rows —
        // an admin's own inbox is not everybody's inbox.
        $this->assertSame([], $this->visible($this->user('admin')));
    }

    public function test_a_users_own_row_shows_beside_their_role_rows(): void
    {
        $hr = $this->user('staff', 'hr_executive');
        $this->toUser($hr);
        $this->toRole('hr');

        $titles = $this->visible($hr);
        $this->assertContains('Yours', $titles);
        $this->assertContains('For hr', $titles);
    }

    /* ── 5. a non-staff account holds no role ─────────────────────────── */

    public function test_a_portal_account_receives_no_role_notifications(): void
    {
        // internal_role is a free string every account carries — a client
        // whose internal_role read 'hr_manager' must not thereby read HR's
        // reminders. The same guard canManageHrQueue() opens with.
        $client = $this->user('client', 'hr_manager');
        $this->toRole('hr_manager');
        $this->toRole('hr');

        $this->assertSame([], $this->visible($client));
    }

    /* ── 6. an unmapped role is narrow, not silent and not broad ──────── */

    public function test_an_unrecognised_role_reaches_administrators_only(): void
    {
        $this->toRole('finance');

        $admin = $this->user('admin');
        $hr    = $this->user('staff', 'hr_executive');

        // A workspace that edits its escalation ladder must not create a
        // notification nothing can display — nor one everybody reads.
        $this->assertContains('For finance', $this->visible($admin));
        $this->assertSame([], $this->visible($hr));
    }

    public function test_the_known_roles_are_the_ladder_roles(): void
    {
        $ladder = array_column(config('hr_notifications.escalation_ladder'), 'role');

        // If somebody adds a rung, this says so rather than letting it fall
        // silently into the administrators-only catch-all.
        foreach ($ladder as $role) {
            $this->assertContains($role, NotificationRoleResolver::KNOWN,
                "Escalation ladder role '{$role}' has no mapping in the resolver.");
        }
    }

    /* ── 7. tenant isolation ──────────────────────────────────────────── */

    public function test_a_role_notification_does_not_cross_tenants(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'notif-roles-2', 'status' => 'active']);

        $mine   = $this->user('staff', 'hr_executive');
        $theirs = $this->user('staff', 'hr_executive', $other);

        $this->toRole('hr', $this->tenant);

        $this->assertSame(['For hr'], $this->visible($mine));
        $this->assertSame([], $this->visible($theirs));
    }

    public function test_the_unread_count_respects_the_same_boundary(): void
    {
        $hr = $this->user('staff', 'hr_executive');
        $this->toRole('department_head');
        $this->toRole('hr');

        // The badge is built from visibleTo(), so a count that disagreed with
        // the list would be its own disclosure.
        $this->assertSame(1, app(NotificationRepository::class)->unreadCount($hr));
    }
}
