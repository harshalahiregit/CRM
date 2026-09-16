<?php

namespace Tests\Feature\Hrm;

use App\Models\Hr\HrEmployee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The attendance app signing in against the CRM.
 *
 * These assert SangoeTrack's SHAPE, not the CRM's. The app has 271 parse sites
 * and an unrecognised key renders blank rather than failing, so the contract is
 * the thing under test — `status` must be the integer 1, a refusal must still
 * be HTTP 200, and `data.workspaces` must be a non-null array because the app
 * asserts on it and crashes otherwise.
 */
class HrmAuthTest extends TestCase
{
    use RefreshDatabase;

    private ?Tenant $t = null;

    private function tenant(): Tenant
    {
        return $this->t ??= Tenant::create(['name' => 'Sangoe', 'slug' => 'hrm-t', 'status' => 'active']);
    }

    private function person(bool $appAllowed = true, string $status = 'active', string $role = 'staff'): array
    {
        $user = User::create([
            'tenant_id' => $this->tenant()->id, 'name' => 'Priya Sharma',
            'email' => 'priya@example.test', 'phone' => '9876543210',
            'password' => Hash::make('Password123!'), 'role' => $role, 'status' => $status,
        ]);

        $employee = HrEmployee::create([
            'tenant_id' => $this->tenant()->id, 'employee_code' => 'SNE-1', 'name' => 'Priya Sharma',
            'department' => 'Ops', 'designation' => 'Analyst', 'joining_date' => '2020-01-01',
            'status' => 'Active', 'user_id' => $user->id, 'app_login_enabled' => $appAllowed,
        ]);

        return [$user, $employee];
    }

    /* ── the contract ────────────────────────────────────────────────── */

    public function test_a_good_login_returns_their_exact_shape(): void
    {
        $this->person();

        $r = $this->postJson('/api/Hrm/login', ['email' => 'priya@example.test', 'password' => 'Password123!'])
            ->assertOk();

        // An INTEGER 1, not the string 'success' the rest of this API uses.
        $this->assertSame(1, $r->json('status'));
        $this->assertIsString($r->json('data.token'));

        // Every field User.fromJson reads, present even when empty — a missing
        // key renders blank in the app and looks like data loss.
        foreach (['id', 'name', 'email', 'mobile_no', 'type', 'active_workspace', 'avatar', 'lang'] as $k) {
            $this->assertArrayHasKey($k, $r->json('data.user'), "user.{$k} is missing.");
        }

        // The app does data!.workspaces!.length — a non-null assertion.
        $this->assertIsArray($r->json('data.workspaces'));
        $this->assertCount(1, $r->json('data.workspaces'));
        foreach (['id', 'name', 'slug', 'status', 'created_by', 'logo'] as $k) {
            $this->assertArrayHasKey($k, $r->json('data.workspaces.0'), "workspace.{$k} is missing.");
        }
    }

    /**
     * A refusal is HTTP 200 with status 0.
     *
     * The app reads the body, not the status line. A 422 would surface as
     * "something went wrong" instead of the actual reason.
     */
    public function test_a_wrong_password_is_200_with_status_zero(): void
    {
        $this->person();

        $r = $this->postJson('/api/Hrm/login', ['email' => 'priya@example.test', 'password' => 'wrong'])
            ->assertOk();

        $this->assertSame(0, $r->json('status'));
        $this->assertNotEmpty($r->json('message'));
    }

    /** Saying which half was wrong reveals whether an address is registered. */
    public function test_an_unknown_email_says_the_same_thing_as_a_wrong_password(): void
    {
        $this->person();

        $a = $this->postJson('/api/Hrm/login', ['email' => 'nobody@example.test', 'password' => 'Password123!']);
        $b = $this->postJson('/api/Hrm/login', ['email' => 'priya@example.test', 'password' => 'wrong']);

        $this->assertSame($a->json('message'), $b->json('message'));
    }

    /* ── app access is HR's decision ─────────────────────────────────── */

    public function test_app_access_off_is_refused_even_with_the_right_password(): void
    {
        $this->person(appAllowed: false);

        $r = $this->postJson('/api/Hrm/login', ['email' => 'priya@example.test', 'password' => 'Password123!'])
            ->assertOk();

        $this->assertSame(0, $r->json('status'));
        $this->assertNull($r->json('data.token'), 'No token may be issued when app access is off.');
    }

    public function test_a_login_with_no_employee_record_is_refused(): void
    {
        User::create([
            'tenant_id' => $this->tenant()->id, 'name' => 'Nobody', 'email' => 'no@example.test',
            'password' => Hash::make('Password123!'), 'role' => 'staff', 'status' => 'active',
        ]);

        $r = $this->postJson('/api/Hrm/login', ['email' => 'no@example.test', 'password' => 'Password123!'])->assertOk();

        $this->assertSame(0, $r->json('status'));
        $this->assertStringContainsString('employee record', (string) $r->json('message'));
    }

    public function test_an_inactive_account_is_refused(): void
    {
        $this->person(status: 'inactive');

        $r = $this->postJson('/api/Hrm/login', ['email' => 'priya@example.test', 'password' => 'Password123!'])->assertOk();

        $this->assertSame(0, $r->json('status'));
    }

    /** An admin gets the company app, not the employee one. */
    public function test_an_admin_is_typed_as_company(): void
    {
        $this->person(role: 'admin');

        $r = $this->postJson('/api/Hrm/login', ['email' => 'priya@example.test', 'password' => 'Password123!']);

        $this->assertSame('company', $r->json('data.user.type'));
    }

    /* ── the rest of the session ─────────────────────────────────────── */

    public function test_the_issued_token_works_on_a_protected_route(): void
    {
        $this->person();

        $token = $this->postJson('/api/Hrm/login', ['email' => 'priya@example.test', 'password' => 'Password123!'])
            ->json('data.token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/Hrm/refresh')
            ->assertOk()
            ->assertJsonPath('status', 1);
    }

    public function test_refresh_issues_a_new_token_and_retires_the_old_one(): void
    {
        $this->person();

        $first = $this->postJson('/api/Hrm/login', ['email' => 'priya@example.test', 'password' => 'Password123!'])
            ->json('data.token');

        $second = $this->withHeader('Authorization', "Bearer {$first}")
            ->postJson('/api/Hrm/refresh')->assertOk()->json('data.token');

        $this->assertNotSame($first, $second);

        // Asserted against the DATABASE, not a second request. Laravel's auth
        // guard caches the resolved user for the lifetime of one test's
        // application instance, so replaying the old token here would return 200
        // whether or not it had been revoked — the test would pass while proving
        // nothing, or fail while the code was right.
        //
        // The old row must be gone, or a stolen token outlives the refresh.
        $oldId = (int) explode('|', $first)[0];
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $oldId]);
        $this->assertSame(1, \DB::table('personal_access_tokens')->count());
    }

    public function test_logout_ends_the_session(): void
    {
        [$user] = $this->person();

        Sanctum::actingAs($user);
        $this->postJson('/api/Hrm/logout')->assertOk()->assertJsonPath('status', 1);
    }

    public function test_changing_a_password_requires_the_current_one(): void
    {
        [$user] = $this->person();

        Sanctum::actingAs($user);
        $this->postJson('/api/Hrm/change-password', [
            'current_password' => 'wrong', 'new_password' => 'NewPassword123!',
        ])->assertOk()->assertJsonPath('status', 0);

        $this->postJson('/api/Hrm/change-password', [
            'current_password' => 'Password123!', 'new_password' => 'NewPassword123!',
        ])->assertOk()->assertJsonPath('status', 1);

        $this->assertTrue(Hash::check('NewPassword123!', $user->fresh()->password));
    }

    public function test_a_protected_route_without_a_token_is_401(): void
    {
        // 401 is destructive in the app — it wipes local storage — so it must
        // mean exactly this and nothing else.
        $this->postJson('/api/Hrm/refresh')->assertStatus(401);
    }
}
