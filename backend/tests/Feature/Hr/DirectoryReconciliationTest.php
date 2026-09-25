<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\DirectoryReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The directory panel finds the problems that are not absences.
 *
 * It used to detect exactly two things, and both were "one side is missing":
 * an employee with no login, a login with no employee. Every fault where the
 * link EXISTS and is wrong was invisible — which is the harder class to spot by
 * eye and the one that actually hurt. A deactivated employee with a live login
 * did not appear here at all, because nothing was missing.
 *
 * Six more detections, each tested below against a record built to trip exactly
 * it. Severity is about consequence: `blocking` means somebody can reach the
 * system who should not, or the record is structurally broken.
 *
 * Nothing in this service repairs anything. Every issue names an action an admin
 * may choose, because each one is a disagreement and only a person can say which
 * side is true.
 */
class DirectoryReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private ?Tenant $tenant = null;

    private function tenant(): Tenant
    {
        return $this->tenant ??= Tenant::create([
            'name' => 'Recon Co', 'slug' => 'recon-co', 'status' => 'active',
        ]);
    }

    private function user(array $attributes = []): User
    {
        static $n = 0;
        $n++;

        return User::create(array_merge([
            'tenant_id' => $this->tenant()->id,
            'name'      => 'Account '.$n,
            'email'     => 'account'.$n.'@recon.test',
            'password'  => Hash::make('Password123!'),
            'role'      => 'staff',
            'status'    => 'active',
        ], $attributes));
    }

    private function employee(array $attributes = []): HrEmployee
    {
        static $n = 0;
        $n++;

        return HrEmployee::create(array_merge([
            'tenant_id'     => $this->tenant()->id,
            'employee_code' => 'REC-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT),
            'name'          => 'Employee '.$n,
            'department'    => 'Operations',
            'designation'   => 'Executive',
            'joining_date'  => '2025-01-01',
            'status'        => 'Active',
        ], $attributes));
    }

    private function report(): array
    {
        return app(DirectoryReconciliationService::class)->report($this->tenant()->id);
    }

    private function issues(string $type): array
    {
        return array_values(array_filter($this->report()['issues'], fn ($i) => $i['type'] === $type));
    }

    /* ── the six new detections ───────────────────────────────────────── */

    /** THE one this whole rebuild exists for: the link is fine, the access is not. */
    public function test_an_inactive_employee_with_a_live_account_is_reported_as_blocking(): void
    {
        $user = $this->user(['status' => 'active']);
        $this->employee(['user_id' => $user->id, 'status' => 'Inactive']);

        $found = $this->issues('access_mismatch');

        $this->assertCount(1, $found);
        $this->assertSame('blocking', $found[0]['severity']);
        $this->assertSame('deactivate_account', $found[0]['action']);
    }

    /** On leave is not gone — the gate lets them in, so this is not a mismatch. */
    public function test_an_employee_on_leave_is_not_reported(): void
    {
        $user = $this->user();
        $this->employee(['user_id' => $user->id, 'status' => 'On Leave']);

        $this->assertSame([], $this->issues('access_mismatch'));
    }

    /**
     * Vikram Rao's case. An active Director whose login is typed `client`, who
     * used to vanish from Staff Management because of it and was reported by
     * nothing, because his link was intact.
     */
    public function test_an_employee_on_a_portal_role_account_is_reported(): void
    {
        $user = $this->user(['role' => 'client']);
        $this->employee(['user_id' => $user->id, 'designation' => 'Director']);

        $found = $this->issues('permission_mismatch');

        $this->assertCount(1, $found);
        $this->assertStringContainsString('client', $found[0]['reason']);
    }

    public function test_the_two_sides_disagreeing_about_a_fact_is_reported_field_by_field(): void
    {
        $user = $this->user(['name' => 'Priya S', 'phone' => '111', 'department' => 'Sales']);
        $this->employee(['user_id' => $user->id, 'name' => 'Priya Sharma', 'phone' => '999', 'department' => 'Engineering']);

        $found = $this->issues('identity_mismatch');

        $this->assertCount(1, $found);
        $this->assertSame('sync_identity', $found[0]['action']);
        $this->assertSame(
            ['name', 'phone', 'department'],
            array_keys($found[0]['differences']),
            'the panel must show which fields disagree, not just that something does',
        );
        $this->assertSame('Engineering', $found[0]['differences']['department']['employee']);
        $this->assertSame('Sales', $found[0]['differences']['department']['account']);
    }

    /**
     * Blank on the account side is not a disagreement.
     *
     * Most of these columns were never populated — the account predates the HR
     * module, or was made by a path that never set a department. Reporting every
     * one as a conflict would bury the few that are real.
     */
    public function test_an_empty_account_field_is_not_a_mismatch(): void
    {
        // Names match deliberately: this test is about the BLANK columns, and a
        // differing name would trip the same detection for another reason.
        $user = $this->user(['name' => 'Same Person', 'department' => null, 'phone' => '']);
        $this->employee([
            'user_id' => $user->id, 'name' => 'Same Person', 'email' => $user->email,
            'department' => 'Engineering', 'phone' => '999',
        ]);

        $this->assertSame([], $this->issues('identity_mismatch'));
    }

    public function test_a_link_pointing_at_a_missing_account_is_blocking(): void
    {
        $this->employee(['user_id' => 999999]);

        $found = $this->issues('broken_link');

        $this->assertCount(1, $found);
        $this->assertSame('blocking', $found[0]['severity']);
        $this->assertSame('clear_link', $found[0]['action']);
    }

    /** A tenant boundary sitting in the data, not an inconvenience. */
    public function test_a_link_into_another_workspace_is_blocking(): void
    {
        $other = Tenant::create(['name' => 'Other Co', 'slug' => 'other-recon', 'status' => 'active']);

        $foreign = User::create([
            'tenant_id' => $other->id, 'name' => 'Foreign', 'email' => 'foreign@recon.test',
            'password' => Hash::make('Password123!'), 'role' => 'staff', 'status' => 'active',
        ]);

        $this->employee(['user_id' => $foreign->id]);

        $found = $this->issues('cross_tenant');

        $this->assertCount(1, $found);
        $this->assertSame('blocking', $found[0]['severity']);
    }

    public function test_two_employees_claiming_one_address_are_reported(): void
    {
        $this->employee(['email' => 'shared@recon.test', 'name' => 'First Claim']);
        $this->employee(['email' => 'shared@recon.test', 'name' => 'Second Claim']);

        $found = $this->issues('duplicate_email');

        $this->assertCount(1, $found);
        $this->assertStringContainsString('First Claim', $found[0]['reason']);
        $this->assertStringContainsString('Second Claim', $found[0]['reason']);
    }

    /** A healthy workspace must be silent, or the panel stops being read. */
    public function test_a_consistent_pair_produces_no_issues(): void
    {
        $user = $this->user([
            'name' => 'Agreed Person', 'email' => 'agreed@recon.test',
            'phone' => '555', 'department' => 'Operations', 'designation' => 'Executive',
        ]);

        $this->employee([
            'user_id' => $user->id, 'name' => 'Agreed Person', 'email' => 'agreed@recon.test',
            'phone' => '555', 'department' => 'Operations', 'designation' => 'Executive',
        ]);

        $this->assertSame([], $this->report()['issues']);
    }

    /* ── dismissal ────────────────────────────────────────────────────── */

    public function test_a_dismissed_issue_stops_being_reported_and_can_be_restored(): void
    {
        $user = $this->user(['role' => 'client']);
        $this->employee(['user_id' => $user->id]);

        $key = $this->issues('permission_mismatch')[0]['key'];
        $service = app(DirectoryReconciliationService::class);

        $service->dismiss($this->tenant()->id, $key, 1);
        $this->assertSame([], $this->issues('permission_mismatch'));

        $service->restore($this->tenant()->id, $key);
        $this->assertCount(1, $this->issues('permission_mismatch'));
    }

    /**
     * A dismissal hides one issue, not the record.
     *
     * The key is derived from the problem, so a second, different fault on the
     * same person still surfaces — dismissing "their role is odd" must not also
     * silence "they are inactive and can still sign in".
     */
    public function test_dismissing_one_issue_does_not_hide_another_on_the_same_person(): void
    {
        $user = $this->user(['role' => 'client', 'status' => 'active']);
        $this->employee(['user_id' => $user->id, 'status' => 'Inactive']);

        $service = app(DirectoryReconciliationService::class);
        $service->dismiss($this->tenant()->id, $this->issues('permission_mismatch')[0]['key'], 1);

        $this->assertSame([], $this->issues('permission_mismatch'));
        $this->assertCount(1, $this->issues('access_mismatch'), 'the blocking one must survive');
    }

    /* ── the remediation endpoints ────────────────────────────────────── */

    private function admin(): User
    {
        return $this->user(['role' => 'admin', 'email' => 'admin@recon.test']);
    }

    public function test_resync_makes_the_account_agree_with_the_employee(): void
    {
        $admin = $this->admin();
        $user = $this->user(['name' => 'Stale', 'phone' => '111', 'department' => 'Sales']);
        $employee = $this->employee(['user_id' => $user->id, 'name' => 'Fresh', 'phone' => '999', 'department' => 'Engineering']);

        $this->actingAs($admin)
            ->postJson('/api/hr/employees/'.$employee->id.'/resync-login')
            ->assertOk();

        $user->refresh();

        $this->assertSame('Fresh', $user->name);
        $this->assertSame('999', $user->phone);
        $this->assertSame('Engineering', $user->department);
        $this->assertSame([], $this->issues('identity_mismatch'));
    }

    /**
     * Unlink clears the column and nothing else. On the cross-workspace path the
     * account belongs to somebody else entirely and must not be touched.
     */
    public function test_unlink_clears_the_link_without_deleting_the_account(): void
    {
        $admin = $this->admin();
        $user = $this->user();
        $employee = $this->employee(['user_id' => $user->id]);

        $this->actingAs($admin)
            ->postJson('/api/hr/employees/'.$employee->id.'/unlink-login')
            ->assertOk();

        $this->assertNull($employee->fresh()->user_id);
        $this->assertNotNull(User::find($user->id), 'the account must survive');
    }

    public function test_the_remediation_endpoints_refuse_another_workspaces_employee(): void
    {
        $admin = $this->admin();

        $other = Tenant::create(['name' => 'Elsewhere', 'slug' => 'elsewhere-recon', 'status' => 'active']);
        $foreignEmployee = HrEmployee::create([
            'tenant_id' => $other->id, 'employee_code' => 'X-001', 'name' => 'Not Ours',
            'department' => 'Ops', 'designation' => 'Exec', 'joining_date' => '2025-01-01', 'status' => 'Active',
        ]);

        foreach (['unlink-login', 'resync-login', 'provision-login'] as $action) {
            $this->actingAs($admin)
                ->postJson('/api/hr/employees/'.$foreignEmployee->id.'/'.$action)
                ->assertStatus(404);
        }
    }

    public function test_dismissal_is_tenant_scoped(): void
    {
        $admin = $this->admin();
        $user = $this->user(['role' => 'client']);
        $this->employee(['user_id' => $user->id]);

        $key = $this->issues('permission_mismatch')[0]['key'];

        $this->actingAs($admin)->postJson('/api/hr/directory/dismiss', ['key' => $key])->assertOk();

        $this->assertDatabaseHas('hr_directory_dismissals', [
            'tenant_id' => $this->tenant()->id,
            'issue_key' => $key,
        ]);

        // Another workspace's panel is unaffected by this one's decision.
        $other = Tenant::create(['name' => 'Third Co', 'slug' => 'third-recon', 'status' => 'active']);
        $this->assertSame([], app(DirectoryReconciliationService::class)->dismissed($other->id));
    }
}
