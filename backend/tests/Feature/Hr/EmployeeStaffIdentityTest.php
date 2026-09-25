<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\EmployeeIdentityService;
use App\Services\Hr\EmployeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * One person, one identity — and one answer to whether they may sign in.
 *
 * A runtime audit drove the app in a browser and found the two directories
 * disagreeing about the same human at the same moment. HR said Manager /
 * Engineering; Staff Management said "N/A / —". Worse, an employee set to
 * Inactive through the HR screen kept a working login: users.status was still
 * 'active' because nothing joined the two tables, and the sign-in gate had never
 * been told employment existed. The login genuinely succeeded.
 *
 * The shape of the fix these tests hold:
 *
 *   hr_employees OWNS name, email, phone, department and designation.
 *   `users` owns the credential, the account status and the permission role.
 *   Employment status gates authentication, and the gate lives server-side.
 *
 * Each test below fails if one of those is undone — see the comments on the
 * individual cases for exactly which regression each one catches.
 */
class EmployeeStaffIdentityTest extends TestCase
{
    use RefreshDatabase;

    private ?Tenant $tenant = null;

    private function tenant(): Tenant
    {
        return $this->tenant ??= Tenant::create([
            'name' => 'Identity Co', 'slug' => 'identity-co', 'status' => 'active',
        ]);
    }

    private function otherTenant(): Tenant
    {
        return Tenant::firstOrCreate(
            ['slug' => 'other-co'],
            ['name' => 'Other Co', 'status' => 'active'],
        );
    }

    private function user(array $attributes = []): User
    {
        static $n = 0;
        $n++;

        return User::create(array_merge([
            'tenant_id' => $this->tenant()->id,
            'name'      => 'Person '.$n,
            'email'     => 'person'.$n.'@identity.test',
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
            'employee_code' => 'EMP-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT),
            'name'          => 'Employee '.$n,
            'department'    => 'Operations',
            'designation'   => 'Executive',
            'joining_date'  => '2025-01-01',
            'status'        => 'Active',
        ], $attributes));
    }

    private function login(User $user)
    {
        return $this->postJson('/api/auth/login', [
            'email'    => $user->email,
            'password' => 'Password123!',
            'role'     => $user->role,
        ]);
    }

    /* ── the access gate ──────────────────────────────────────────────── */

    public function test_an_active_employee_with_an_active_login_can_sign_in(): void
    {
        $user = $this->user();
        $this->employee(['user_id' => $user->id, 'status' => 'Active']);

        $this->login($user)->assertOk();
    }

    /**
     * THE regression. Reproduced in a browser before the fix: employee Inactive,
     * user active, login succeeded.
     *
     * If someone removes the employment gate from AuthService, this is the test
     * that goes red.
     */
    public function test_an_inactive_employee_cannot_sign_in(): void
    {
        $user = $this->user();
        $this->employee(['user_id' => $user->id, 'status' => 'Inactive']);

        $this->login($user)
            ->assertStatus(403)
            ->assertJsonFragment(['message' => 'Your employment record is not active. Contact HR.']);
    }

    /**
     * Being away is not being gone. Somebody on leave still has to read a
     * payslip and apply for more leave, so 'On Leave' must NOT lock them out —
     * a gate that blocks it would break the leave module for the only people
     * who use it.
     */
    public function test_an_employee_on_leave_can_still_sign_in(): void
    {
        $user = $this->user();
        $this->employee(['user_id' => $user->id, 'status' => 'On Leave']);

        $this->login($user)->assertOk();
    }

    /**
     * Every employment status the API accepts must have been deliberately
     * classified as allowed or blocked.
     *
     * It cannot be tested by storing an unknown value — hr_employees.status
     * carries a CHECK constraint, so the database refuses one outright. The real
     * risk is the other shape: somebody adds a fourth status to the dropdown and
     * the migration, and nobody decides whether its holder may sign in. An
     * allowlist means the new status is refused by default, which is the safe
     * direction — but silently, and a year later "why can't Priya log in" is a
     * hard afternoon.
     *
     * So this reads the vocabulary from the rule that defines it and insists the
     * two lists together account for all of it. Add a status, and this test tells
     * you to make the decision. Source-level for the same reason as
     * BannedPatternsTest beside it: the rule string is the authority.
     */
    public function test_every_employment_status_is_deliberately_classified(): void
    {
        $source = file_get_contents(base_path('app/Http/Controllers/Api/Hr/EmployeeController.php'));

        $this->assertMatchesRegularExpression(
            "/'status'\s*=>\s*'nullable\|in:([^']+)'/",
            $source,
            'The employee status vocabulary has moved — update this test to follow it.',
        );

        preg_match("/'status'\s*=>\s*'nullable\|in:([^']+)'/", $source, $m);

        $vocabulary = array_map('trim', explode(',', $m[1]));
        $maySignIn  = EmployeeIdentityService::EMPLOYMENT_STATUSES_THAT_MAY_SIGN_IN;
        $blocked    = ['Inactive'];

        $this->assertSame(
            [],
            array_values(array_diff($vocabulary, $maySignIn, $blocked)),
            'A new employment status exists that nobody has decided about. Add it to '
            .'EMPLOYMENT_STATUSES_THAT_MAY_SIGN_IN, or to the blocked list in this test.',
        );

        $this->assertNotContains('Inactive', $maySignIn, 'Inactive must never be allowed to sign in.');
    }

    /**
     * The account's own reasons keep their own wording. A suspended account must
     * not start talking about HR.
     */
    public function test_a_suspended_account_is_still_refused_in_its_own_words(): void
    {
        $user = $this->user(['status' => 'suspended']);
        $this->employee(['user_id' => $user->id, 'status' => 'Active']);

        $this->login($user)
            ->assertStatus(403)
            ->assertJsonFragment(['message' => 'Your account has been suspended. Contact support.']);
    }

    /**
     * Logins that are not employees are none of this gate's business.
     *
     * A portal account, an integration login, an admin in a workspace that does
     * not use the HR module: inventing an employment status to judge them by
     * would break sign-ins that work correctly today.
     */
    public function test_a_login_with_no_employee_record_is_unaffected(): void
    {
        $this->login($this->user())->assertOk();
    }

    public function test_reactivating_the_employee_restores_access(): void
    {
        $user = $this->user();
        $employee = $this->employee(['user_id' => $user->id, 'status' => 'Inactive']);

        $this->login($user)->assertStatus(403);

        $employee->update(['status' => 'Active']);

        $this->login($user)->assertOk();
    }

    /**
     * A workspace must never be able to lock every administrator out of itself.
     *
     * Staff Management already refuses to demote or delete the founding admin
     * for this reason; the HR Employees screen says nothing about access, so one
     * careless status change there would otherwise brick the tenant with no way
     * back in.
     */
    public function test_the_founding_admin_is_not_locked_out_by_their_employee_record(): void
    {
        $admin = $this->user(['role' => 'admin']);
        $this->employee(['user_id' => $admin->id, 'status' => 'Inactive']);

        $this->login($admin)->assertOk();
    }

    /** ...but a later admin is not exempt. The exemption is one account, not a role. */
    public function test_a_non_founding_admin_is_still_refused(): void
    {
        $founder = $this->user(['role' => 'admin']);
        $second  = $this->user(['role' => 'admin']);

        $this->employee(['user_id' => $second->id, 'status' => 'Inactive']);

        $this->assertLessThan($second->id, $founder->id, 'the founder must be the earlier admin');

        $this->login($second)->assertStatus(403);
    }

    /** The attendance app is the half that records attendance — it gets the same gate. */
    public function test_the_attendance_app_also_refuses_an_inactive_employee(): void
    {
        $user = $this->user();
        $employee = $this->employee([
            'user_id' => $user->id, 'status' => 'Active', 'app_login_enabled' => true,
        ]);

        $identity = app(EmployeeIdentityService::class);

        $this->assertTrue($identity->mayUseApp($user->fresh()));

        $employee->update(['status' => 'Inactive']);

        $this->assertFalse($identity->mayUseApp($user->fresh()));
        $this->assertSame(
            'Your employment record is not active. Contact HR.',
            $identity->appRefusalReason($user->fresh()),
        );
    }

    /* ── one identity, one owner ──────────────────────────────────────── */

    /**
     * Editing the employee must carry through to the login attached to it.
     *
     * Before the fix: phone, department and designation changed on the employee
     * and stayed NULL on the user, so Staff Management went on showing blanks
     * for somebody HR had just updated.
     */
    public function test_editing_an_employee_updates_the_linked_login(): void
    {
        $user = $this->user(['name' => 'Old Name', 'email' => 'old@identity.test']);
        $employee = $this->employee(['user_id' => $user->id]);

        app(EmployeeService::class)->update($employee, [
            'name'        => 'New Name',
            'email'       => 'new@identity.test',
            'phone'       => '9876500000',
            'department'  => 'Engineering',
            'designation' => 'Manager',
        ], $user);

        $user->refresh();

        $this->assertSame('New Name', $user->name);
        $this->assertSame('new@identity.test', $user->email, 'the login email is the credential and must follow');
        $this->assertSame('9876500000', $user->phone);
        $this->assertSame('Engineering', $user->department);
        $this->assertSame('Manager', $user->designation);
    }

    /**
     * The sync touches identity and nothing else. Password, status and the
     * permission role belong to the account.
     */
    public function test_the_sync_does_not_touch_account_fields(): void
    {
        $user = $this->user(['status' => 'active', 'role' => 'admin']);
        $password = $user->password;
        $employee = $this->employee(['user_id' => $user->id]);

        app(EmployeeService::class)->update($employee, ['name' => 'Renamed'], $user);

        $user->refresh();

        $this->assertSame($password, $user->password);
        $this->assertSame('active', $user->status);
        $this->assertSame('admin', $user->role);
    }

    /**
     * An email already spoken for is left alone rather than colliding.
     * users.email is globally unique; a clash is a decision for a human.
     */
    public function test_an_email_already_in_use_is_not_pushed_onto_the_login(): void
    {
        $taken = $this->user(['email' => 'taken@identity.test']);
        $user  = $this->user(['email' => 'mine@identity.test']);
        $employee = $this->employee(['user_id' => $user->id]);

        app(EmployeeService::class)->update($employee, ['email' => 'taken@identity.test'], $user);

        $this->assertSame('mine@identity.test', $user->fresh()->email);
        $this->assertSame('taken@identity.test', $taken->fresh()->email);
    }

    /** Never across workspaces — users.email is globally unique, so a bare lookup can reach one. */
    public function test_the_sync_never_crosses_tenants(): void
    {
        $foreign = User::create([
            'tenant_id' => $this->otherTenant()->id,
            'name'      => 'Foreign Person',
            'email'     => 'foreign@identity.test',
            'password'  => Hash::make('Password123!'),
            'role'      => 'staff',
            'status'    => 'active',
        ]);

        $employee = $this->employee(['user_id' => $foreign->id]);

        app(EmployeeService::class)->update($employee, ['name' => 'Should Not Travel'], null);

        $this->assertSame('Foreign Person', $foreign->fresh()->name);
    }

    /* ── Staff Management sees the same person ────────────────────────── */

    private function admin(): User
    {
        return $this->user(['role' => 'admin', 'email' => 'admin@identity.test']);
    }

    /**
     * A linked employee must never vanish from account management because their
     * login carries a legacy portal role.
     *
     * Vikram Rao, an active Director, was invisible on this screen because his
     * `users.role` said 'client' — so the one page that could fix his account
     * was the one page that could not see him.
     */
    public function test_a_linked_client_role_account_is_still_listed(): void
    {
        $admin = $this->admin();
        $legacy = $this->user(['role' => 'client', 'name' => 'Legacy Portal Login']);
        $this->employee(['user_id' => $legacy->id, 'name' => 'Vikram Rao', 'designation' => 'Director']);

        $rows = $this->actingAs($admin)->getJson('/api/admin/staff?per_page=50')
            ->assertOk()
            ->json('data.staff');

        $ids = array_column($rows, 'id');

        $this->assertContains($legacy->id, $ids, 'a linked employee must not disappear because of a legacy role');
    }

    /** An unlinked portal account is still none of this screen's business. */
    public function test_an_unlinked_portal_account_is_still_hidden(): void
    {
        $admin = $this->admin();
        $portal = $this->user(['role' => 'client', 'name' => 'A Customer']);

        $rows = $this->actingAs($admin)->getJson('/api/admin/staff?per_page=50')
            ->assertOk()
            ->json('data.staff');

        $this->assertNotContains($portal->id, array_column($rows, 'id'));
    }

    /**
     * The screen reads the owner, so the two screens cannot disagree even when
     * the account row's copy is stale.
     */
    public function test_the_staff_list_shows_the_employees_identity_not_a_stale_copy(): void
    {
        $admin = $this->admin();
        $user = $this->user(['name' => 'Stale Name', 'department' => null, 'designation' => null]);
        $this->employee([
            'user_id'     => $user->id,
            'name'        => 'Fresh Name',
            'phone'       => '9000012345',
            'department'  => 'Engineering',
            'designation' => 'Manager',
        ]);

        $rows = $this->actingAs($admin)->getJson('/api/admin/staff?per_page=50')->assertOk()->json('data.staff');
        $row  = collect($rows)->firstWhere('id', $user->id);

        $this->assertSame('Fresh Name', $row['name']);
        $this->assertSame('9000012345', $row['phone']);
        $this->assertSame('Engineering', $row['department']);
        $this->assertSame('Manager', $row['designation']);
    }

    /**
     * The badge must not say ACTIVE beside a login the auth gate refuses. The UI
     * needs a reason to show, not just a status to contradict.
     */
    public function test_the_staff_list_explains_why_access_is_blocked(): void
    {
        $admin = $this->admin();
        $user = $this->user();
        $this->employee(['user_id' => $user->id, 'status' => 'Inactive']);

        $rows = $this->actingAs($admin)->getJson('/api/admin/staff?per_page=50')->assertOk()->json('data.staff');
        $row  = collect($rows)->firstWhere('id', $user->id);

        $this->assertSame('Inactive', $row['employment_status']);
        $this->assertNotNull($row['access_blocked_reason']);
        $this->assertStringContainsString('Employment is Inactive', $row['access_blocked_reason']);
    }

    /**
     * An account with no employee record is a legitimate state, and its row must
     * not be decorated with an employment problem it cannot have.
     */
    public function test_an_account_without_an_employee_record_reports_no_employment_block(): void
    {
        $admin = $this->admin();

        $rows = $this->actingAs($admin)->getJson('/api/admin/staff?per_page=50')->assertOk()->json('data.staff');
        $row  = collect($rows)->firstWhere('id', $admin->id);

        $this->assertNull($row['employee_id']);
        $this->assertNull($row['access_blocked_reason']);
    }

    /**
     * Editing in Staff Management writes to the owner.
     *
     * Without this the screen would be worse than before the fix: the admin
     * saves a new phone number and the list redraws with the old one, because
     * the display reads the employee and the write went to the account.
     */
    public function test_editing_in_staff_management_writes_through_to_the_employee(): void
    {
        $admin = $this->admin();
        $user = $this->user();
        $employee = $this->employee(['user_id' => $user->id]);

        $this->actingAs($admin)->putJson('/api/admin/staff/'.$user->id, [
            'name'  => 'Edited In Staff',
            'phone' => '9111122222',
        ])->assertOk();

        $employee->refresh();

        $this->assertSame('Edited In Staff', $employee->name);
        $this->assertSame('9111122222', $employee->phone);
        $this->assertSame('Edited In Staff', $user->fresh()->name);
    }

    /**
     * Staff Management must not be forced to invent a permission role.
     *
     * 7 of 11 real accounts, Super Admin among them, have no staff_role_id. The
     * browser form marked Role required, so opening any of them produced a form
     * that could never submit — clicking Update sent no request at all.
     */
    public function test_a_staff_account_with_no_role_can_still_be_edited(): void
    {
        $admin = $this->admin();
        $user = $this->user();

        $this->assertNull($user->staff_role_id);

        // The exact shape the form now posts for a role-less account: no
        // internal_role key at all, and a null staff_role_id. Sending
        // internal_role: '' instead is a 422 — the rule is `sometimes|required`,
        // so an empty value present is worse than absent.
        $this->actingAs($admin)->putJson('/api/admin/staff/'.$user->id, [
            'name'          => 'Still Editable',
            'staff_role_id' => null,
        ])->assertOk();

        $this->assertSame('Still Editable', $user->fresh()->name);
        $this->assertNull($user->fresh()->staff_role_id, 'no role must have been invented');
    }

    /**
     * And the shape that used to be posted is still rejected, so nobody
     * "simplifies" the form back into sending an empty role.
     */
    public function test_posting_an_empty_internal_role_is_still_a_validation_error(): void
    {
        $admin = $this->admin();
        $user = $this->user();

        $this->actingAs($admin)->putJson('/api/admin/staff/'.$user->id, [
            'name'          => 'Attempted',
            'internal_role' => '',
        ])->assertStatus(422);
    }

    /* ── reconciliation actions ───────────────────────────────────────── */

    public function test_provisioning_a_login_creates_one_and_links_it(): void
    {
        $admin = $this->admin();
        $employee = $this->employee(['email' => 'needs.login@identity.test']);

        $body = $this->actingAs($admin)
            ->postJson('/api/hr/employees/'.$employee->id.'/provision-login')
            ->assertOk()
            ->json('data');

        $this->assertTrue($body['created']);
        $this->assertNotEmpty($body['temporary_password']);
        $this->assertSame($body['user_id'], $employee->fresh()->user_id);
        $this->assertSame('needs.login@identity.test', User::find($body['user_id'])->email);
    }

    /** Idempotent — pressing it twice must not make a second account. */
    public function test_provisioning_twice_does_not_create_a_duplicate(): void
    {
        $admin = $this->admin();
        $employee = $this->employee(['email' => 'once@identity.test']);

        $first = $this->actingAs($admin)->postJson('/api/hr/employees/'.$employee->id.'/provision-login')->json('data');
        $again = $this->actingAs($admin)->postJson('/api/hr/employees/'.$employee->id.'/provision-login')->json('data');

        $this->assertSame($first['user_id'], $again['user_id']);
        $this->assertFalse($again['created']);
        $this->assertSame(1, User::where('email', 'once@identity.test')->count());
    }

    /** A portal address must never be quietly repurposed as a staff login. */
    public function test_provisioning_refuses_a_portal_account_address(): void
    {
        $admin = $this->admin();
        $this->user(['role' => 'client', 'email' => 'customer@identity.test']);
        $employee = $this->employee(['email' => 'customer@identity.test']);

        $this->actingAs($admin)
            ->postJson('/api/hr/employees/'.$employee->id.'/provision-login')
            ->assertStatus(422);

        $this->assertNull($employee->fresh()->user_id);
    }

    /**
     * The panel may only SUGGEST a link on an exact, unique email match. A wrong
     * link hands one person's payslips to another.
     */
    public function test_the_reconciliation_report_suggests_only_exact_unique_matches(): void
    {
        $admin = $this->admin();

        $match = $this->user(['email' => 'exact@identity.test', 'name' => 'Exact Match']);
        $this->employee(['email' => 'exact@identity.test', 'name' => 'Should Match']);
        $this->employee(['email' => 'nobody@identity.test', 'name' => 'No Match']);

        $report = $this->actingAs($admin)->getJson('/api/hr/directory/reconciliation')->assertOk()->json('data');

        $rows = collect($report['without_login'])->keyBy('name');

        $this->assertSame($match->id, $rows['Should Match']['suggested_user']['user_id']);
        $this->assertNull($rows['No Match']['suggested_user']);
    }

    public function test_linking_refuses_a_login_another_employee_already_holds(): void
    {
        $admin = $this->admin();
        $user = $this->user();
        $this->employee(['user_id' => $user->id, 'name' => 'First Claimant']);
        $second = $this->employee(['name' => 'Second Claimant']);

        $this->actingAs($admin)
            ->postJson('/api/hr/employees/'.$second->id.'/link-login', ['user_id' => $user->id])
            ->assertStatus(422);

        $this->assertNull($second->fresh()->user_id);
    }
}
