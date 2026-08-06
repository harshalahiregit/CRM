<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Review comment #45 — "STAFF MANAGEMENT: Auto log out after clicking".
 *
 * The contract these pin down:
 *
 *   401 = you are not (or no longer) authenticated  → the client ends the session
 *   403 = you are authenticated but not allowed     → the client shows an error
 *
 * The original defect was a 401 on a PERMISSION problem: `routes/admin.php`
 * chained a second `->middleware()`, which replaces rather than appends, so
 * `auth:sanctum` was dropped and `role:admin` saw a null user. Every client
 * treats 401 as an expired token, so opening Staff Management logged the user
 * straight out — admins included.
 */
class PermissionFailureStatusTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active',
        ])->save();
    }

    private function user(string $role, array $extra = []): User
    {
        return User::create(array_merge([
            'tenant_id' => self::TENANT, 'name' => ucfirst($role), 'email' => $role.uniqid().'@test.com',
            'password' => bcrypt('secret'), 'role' => $role, 'status' => 'active',
        ], $extra));
    }

    /* ── The reported bug ─────────────────────────────────────────────── */

    public function test_staff_management_is_reachable_by_an_admin(): void
    {
        // The regression that started it all: auth:sanctum being dropped made
        // this 401 for EVERYONE, including the admin who is allowed in.
        Sanctum::actingAs($this->user('admin'));

        $this->getJson('/api/admin/staff/stats')->assertOk();
        $this->getJson('/api/admin/staff')->assertOk();
        $this->getJson('/api/admin/staff/designations')->assertOk();
        $this->getJson('/api/admin/staff/departments')->assertOk();
    }

    public function test_a_non_admin_is_forbidden_not_logged_out(): void
    {
        Sanctum::actingAs($this->user('staff'));

        // 403 — the frontend shows an error. A 401 here would clear their token
        // and bounce them to the login screen mid-task.
        foreach (['/api/admin/staff/stats', '/api/admin/staff',
                  '/api/admin/staff/designations', '/api/admin/staff/departments'] as $url) {
            $this->getJson($url)->assertStatus(403);
        }
    }

    public function test_an_unauthenticated_caller_still_gets_401(): void
    {
        // The genuine case must keep working, or expired sessions never end.
        $this->getJson('/api/admin/staff/stats')->assertStatus(401);
    }

    /* ── Every portal the comment's blast radius touches ───────────────── */

    public function test_each_portal_answers_a_wrong_role_with_403_not_401(): void
    {
        // A logged-in user of the wrong kind is a PERMISSION problem in every
        // portal. Returning 401 to any of them would sign them out of the app
        // they are legitimately signed in to.
        $cases = [
            // [portal endpoint, a role that is authenticated but not allowed]
            ['/api/vendor-portal/profile', 'client'],
            ['/api/company-portal/profile', 'vendor'],
            ['/api/purchase-portal/profile', 'admin'],
        ];

        foreach ($cases as [$url, $role]) {
            Sanctum::actingAs($this->user($role));
            $status = $this->getJson($url)->getStatusCode();

            $this->assertNotSame(401, $status,
                "{$url} answered a wrong-role caller with 401 — that logs them out");
            $this->assertContains($status, [403, 404],
                "{$url} should refuse with 403 (or 404 where existence is hidden), got {$status}");
        }
    }

    public function test_hr_permission_failures_are_403(): void
    {
        // The HR module gates on canManageHrQueue() in many places; none of them
        // may cost the user their session.
        Sanctum::actingAs($this->user('employee'));

        foreach (['/api/hr/variable-earnings', '/api/hr/interview-questions'] as $url) {
            $status = $this->getJson($url)->getStatusCode();
            $this->assertNotSame(401, $status, "{$url} must not answer a permission failure with 401");
        }

        $this->getJson('/api/hr/variable-earnings')->assertStatus(403);
    }

    public function test_an_inactive_or_missing_token_is_the_only_401_path(): void
    {
        // Sweep the protected surface: unauthenticated → 401 everywhere.
        foreach (['/api/hr/candidates', '/api/hr/offers', '/api/admin/staff'] as $url) {
            $this->getJson($url)->assertStatus(401);
        }
    }
}
