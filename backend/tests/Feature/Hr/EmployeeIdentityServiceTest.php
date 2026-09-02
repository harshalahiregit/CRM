<?php

namespace Tests\Feature\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrEmployee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\EmployeeIdentityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Provisioning the link between a login and an employee.
 *
 * The refusals matter more than the happy path here. `users.email` is globally
 * unique, so a lookup by email alone returns exactly one account which may belong
 * to a different tenant — linking to it would bind one workspace's employee to
 * another workspace's login. With one tenant that is invisible; with two it is a
 * breach that reads as a bug.
 */
class EmployeeIdentityServiceTest extends TestCase
{
    use RefreshDatabase;

    private EmployeeIdentityService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(EmployeeIdentityService::class);
    }

    private function tenant(string $slug): Tenant
    {
        return Tenant::create(['name' => 'T ' . $slug, 'slug' => $slug, 'status' => 'active']);
    }

    private function employee(int $tenantId, string $code, ?string $email = null): HrEmployee
    {
        return HrEmployee::create([
            'tenant_id' => $tenantId, 'employee_code' => $code,
            'name' => 'Person ' . $code, 'email' => $email,
            'department' => 'Ops', 'designation' => 'Analyst',
            'joining_date' => now()->toDateString(), 'status' => 'Active',
        ]);
    }

    private function user(int $tenantId, string $email, string $role = 'staff'): User
    {
        return User::create([
            'tenant_id' => $tenantId, 'name' => 'U', 'email' => $email,
            'password' => Hash::make('Password123!'), 'role' => $role, 'status' => 'active',
        ]);
    }

    public function test_it_creates_a_login_and_links_it(): void
    {
        $t = $this->tenant('alpha');
        $e = $this->employee($t->id, 'SNE-1', 'priya@example.test');

        $r = $this->svc->provision($e);

        $this->assertTrue($r['created']);
        $this->assertNotNull($r['temporary_password']);
        $this->assertSame('priya@example.test', $r['user']->email);
        $this->assertSame($t->id, $r['user']->tenant_id);
        $this->assertSame('staff', $r['user']->role);
        $this->assertSame($r['user']->id, $e->fresh()->user_id);
    }

    public function test_it_is_idempotent(): void
    {
        $t = $this->tenant('alpha');
        $e = $this->employee($t->id, 'SNE-1', 'priya@example.test');

        $first  = $this->svc->provision($e);
        $second = $this->svc->provision($e->fresh());

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertNull($second['temporary_password']);
        $this->assertSame($first['user']->id, $second['user']->id);
        $this->assertSame(1, User::where('email', 'priya@example.test')->count());
    }

    public function test_it_reuses_an_existing_staff_login_in_the_same_tenant(): void
    {
        $t = $this->tenant('alpha');
        $u = $this->user($t->id, 'priya@example.test');
        $e = $this->employee($t->id, 'SNE-1', 'priya@example.test');

        $r = $this->svc->provision($e);

        $this->assertFalse($r['created']);
        $this->assertSame($u->id, $r['user']->id);
        $this->assertSame($u->id, $e->fresh()->user_id);
    }

    /** The one that matters: never bind across workspaces. */
    public function test_it_refuses_to_link_a_login_from_another_tenant(): void
    {
        $a = $this->tenant('alpha');
        $b = $this->tenant('bravo');

        $this->user($b->id, 'shared@example.test');
        $e = $this->employee($a->id, 'SNE-1', 'shared@example.test');

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('already belongs to an account in another workspace');

        try {
            $this->svc->provision($e);
        } finally {
            $this->assertNull($e->fresh()->user_id, 'A refused link must leave the employee unlinked.');
        }
    }

    public function test_it_refuses_to_hijack_a_portal_account(): void
    {
        $t = $this->tenant('alpha');
        $this->user($t->id, 'client@example.test', 'client');
        $e = $this->employee($t->id, 'SNE-1', 'client@example.test');

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('portal account');

        $this->svc->provision($e);
    }

    public function test_it_refuses_when_the_login_is_already_another_employee(): void
    {
        $t = $this->tenant('alpha');
        $u = $this->user($t->id, 'shared@example.test');

        $first = $this->employee($t->id, 'SNE-1', 'shared@example.test');
        $this->svc->provision($first);

        $second = $this->employee($t->id, 'SNE-2', 'shared@example.test');

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('That login already belongs to');

        $this->svc->provision($second);
    }

    public function test_it_requires_an_email(): void
    {
        $t = $this->tenant('alpha');
        $e = $this->employee($t->id, 'SNE-1', null);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('needs an email address');

        $this->svc->provision($e);
    }

    /** official_email wins — that is the work address the login should use. */
    public function test_official_email_is_preferred(): void
    {
        $t = $this->tenant('alpha');
        $e = $this->employee($t->id, 'SNE-1', 'personal@example.test');
        $e->update(['official_email' => 'work@example.test']);

        $r = $this->svc->provision($e->fresh());

        $this->assertSame('work@example.test', $r['user']->email);
    }

    public function test_employee_for_resolves_within_the_tenant_only(): void
    {
        $a = $this->tenant('alpha');
        $b = $this->tenant('bravo');

        $u = $this->user($a->id, 'priya@example.test');
        $e = $this->employee($a->id, 'SNE-1', 'priya@example.test');
        $this->svc->provision($e);

        $this->assertSame($e->id, $this->svc->employeeFor($u->fresh())->id);

        // The same user id in another tenant resolves to nobody.
        $other = $this->user($b->id, 'someone@example.test');
        $this->assertNull($this->svc->employeeFor($other));
    }
}
