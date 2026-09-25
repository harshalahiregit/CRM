<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrDepartment;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeGoal;
use App\Models\Hr\HrGoal;
use App\Models\Hr\HrKpi;
use App\Models\Hr\HrPerformanceReview;
use App\Models\StaffRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Performance, held to the same standard as every other operational module.
 *
 * A previous audit of mine reported that Performance enforced no data scope. It
 * was wrong, and the way it was wrong is worth recording: the audit grepped for
 * `ScopeResolver` and found nothing, because PerformanceRepository reaches the
 * resolver through the shared ScopesEmployeeData trait like the other
 * operational repositories do. Reads were scoped all along, writes were gated by
 * canManageHrQueue(), and the actor was threaded from controller to repository.
 *
 * What was genuinely missing was any test saying so. Performance was the only
 * substantial HR domain with no coverage at all, which meant the enforcement
 * could be removed by accident and nothing anywhere would notice.
 *
 * So this file asserts the behaviour rather than adding to it: a department-
 * scoped reviewer sees their own department and no further, an ordinary
 * employee cannot write, tenants cannot see each other, and the review
 * lifecycle refuses the transitions it is supposed to refuse.
 */
class PerformanceScopeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $a;
    private Tenant $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = Tenant::create(['name' => 'Alpha', 'slug' => 'perf-a', 'status' => 'active']);
        $this->b = Tenant::create(['name' => 'Beta', 'slug' => 'perf-b', 'status' => 'active']);
    }

    /* ── fixture ──────────────────────────────────────────────────────── */

    private function department(string $name, ?Tenant $t = null): HrDepartment
    {
        return HrDepartment::create([
            'tenant_id' => ($t ?: $this->a)->id, 'name' => $name, 'is_active' => true,
        ]);
    }

    /** An HR-queue manager: unscoped unless a staff role narrows them. */
    private function hrUser(?Tenant $t = null): User
    {
        return User::create([
            'tenant_id' => ($t ?: $this->a)->id, 'name' => 'HR',
            'email' => uniqid().'@perf.test', 'password' => Hash::make('Password123!'),
            'role' => 'admin', 'status' => 'active',
        ]);
    }

    /** A staff account with no HR-queue authority at all. */
    private function plainUser(?Tenant $t = null): User
    {
        return User::create([
            'tenant_id' => ($t ?: $this->a)->id, 'name' => 'Staff',
            'email' => uniqid().'@perf.test', 'password' => Hash::make('Password123!'),
            'role' => 'staff', 'status' => 'active',
        ]);
    }

    private function employee(?Tenant $t = null, ?HrDepartment $dept = null, ?User $user = null): HrEmployee
    {
        return HrEmployee::create([
            'tenant_id' => ($t ?: $this->a)->id, 'name' => 'E'.substr(uniqid(), -5),
            'employee_code' => 'E'.substr(uniqid(), -6),
            'department' => $dept?->name ?? 'Ops', 'department_id' => $dept?->id,
            'designation' => 'Analyst', 'joining_date' => '2024-01-01', 'status' => 'Active',
            'user_id' => $user?->id,
        ]);
    }

    private function review(HrEmployee $e, string $status = 'Draft'): HrPerformanceReview
    {
        return HrPerformanceReview::create([
            'tenant_id' => $e->tenant_id, 'employee_id' => $e->id,
            'review_type' => 'Annual', 'period_year' => 2026,
            'overall_rating' => 4, 'status' => $status,
        ]);
    }

    /* ═══════════════ 1. TENANT ISOLATION ════════════════════════════ */

    /** @test */
    public function reviews_never_cross_a_tenant_boundary(): void
    {
        $mine   = $this->review($this->employee($this->a));
        $theirs = $this->review($this->employee($this->b));

        Sanctum::actingAs($this->hrUser($this->a));
        $ids = collect($this->getJson('/api/hr/performance/reviews')->assertOk()->json())->pluck('id');

        $this->assertContains($mine->id, $ids->all());
        $this->assertNotContains($theirs->id, $ids->all());
    }

    /** @test */
    public function another_tenants_review_cannot_be_read_by_id(): void
    {
        $theirs = $this->review($this->employee($this->b));

        Sanctum::actingAs($this->hrUser($this->a));
        $this->getJson('/api/hr/performance/reviews/'.$theirs->id)->assertStatus(404);
    }

    /** @test */
    public function another_tenants_review_cannot_be_written(): void
    {
        $theirs = $this->review($this->employee($this->b));

        Sanctum::actingAs($this->hrUser($this->a));
        $this->patchJson('/api/hr/performance/reviews/'.$theirs->id.'/status', ['status' => 'Submitted'])
            ->assertStatus(404);

        $this->assertSame('Draft', $theirs->fresh()->status);
    }

    /** @test */
    public function employee_goals_never_cross_a_tenant_boundary(): void
    {
        foreach ([$this->a, $this->b] as $t) {
            $goal = HrGoal::create(['tenant_id' => $t->id, 'title' => 'G'.substr(uniqid(), -4), 'status' => 'Active']);
            HrEmployeeGoal::create([
                'tenant_id' => $t->id, 'goal_id' => $goal->id,
                'employee_id' => $this->employee($t)->id, 'status' => 'Active',
            ]);
        }

        Sanctum::actingAs($this->hrUser($this->a));
        $rows = $this->getJson('/api/hr/performance/assignments')->assertOk()->json();

        $this->assertCount(1, $rows, 'Only this tenant\'s assignment is visible.');
    }

    /** @test */
    public function kpis_never_cross_a_tenant_boundary(): void
    {
        HrKpi::create(['tenant_id' => $this->a->id, 'name' => 'Mine', 'is_active' => true]);
        HrKpi::create(['tenant_id' => $this->b->id, 'name' => 'Theirs', 'is_active' => true]);

        Sanctum::actingAs($this->hrUser($this->a));
        $names = collect($this->getJson('/api/hr/performance/kpis')->assertOk()->json())->pluck('name');

        $this->assertContains('Mine', $names->all());
        $this->assertNotContains('Theirs', $names->all());
    }

    /* ═══════════════ 2. DATA SCOPE ══════════════════════════════════ */

    /**
     * A department-scoped reviewer sees their department and no further.
     *
     * This is the assertion my audit wrongly said did not exist. Reads run
     * through PerformanceRepository's ScopesEmployeeData, with the actor
     * threaded from the controller.
     */
    public function test_a_department_scoped_reviewer_sees_only_their_department(): void
    {
        $ops   = $this->department('Ops');
        $sales = $this->department('Sales');

        $mine   = $this->review($this->employee($this->a, $ops));
        $theirs = $this->review($this->employee($this->a, $sales));

        $actorEmployee = $this->employee($this->a, $ops);
        $user = $this->scopedUser($actorEmployee, 'department');

        Sanctum::actingAs($user);
        $ids = collect($this->getJson('/api/hr/performance/reviews')->assertOk()->json())->pluck('id')->all();

        $this->assertContains($mine->id, $ids, 'Own department is visible.');
        $this->assertNotContains($theirs->id, $ids, 'Another department is not.');
    }

    /** And the same boundary holds on a direct read by id. */
    public function test_a_department_scoped_reviewer_cannot_open_another_departments_review(): void
    {
        $ops   = $this->department('Ops');
        $sales = $this->department('Sales');
        $theirs = $this->review($this->employee($this->a, $sales));

        Sanctum::actingAs($this->scopedUser($this->employee($this->a, $ops), 'department'));

        $this->getJson('/api/hr/performance/reviews/'.$theirs->id)->assertStatus(404);
    }

    /** An 'own'-scoped person sees their own review and nobody else's. */
    public function test_an_own_scoped_user_sees_only_their_own_review(): void
    {
        $ops  = $this->department('Ops');
        $user = $this->plainUser();
        $me   = $this->employee($this->a, $ops, $user);

        $mine      = $this->review($me);
        $colleague = $this->review($this->employee($this->a, $ops));

        Sanctum::actingAs($this->scopedUser($me, 'own', $user));
        $ids = collect($this->getJson('/api/hr/performance/reviews')->assertOk()->json())->pluck('id')->all();

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($colleague->id, $ids);
    }

    /**
     * Give a user an HR-queue staff role narrowed to one scope.
     *
     * The permission grid is what makes them an HR-queue manager; the scope on
     * the role is what narrows which employees they may see.
     */
    private function scopedUser(HrEmployee $employee, string $scope, ?User $user = null): User
    {
        $role = StaffRole::create([
            'tenant_id' => $this->a->id, 'name' => 'Reviewer '.substr(uniqid(), -5),
            'slug' => 'reviewer-'.substr(uniqid(), -5),
            'permissions' => ['hr_employees' => ['view_global'], 'hr_recruitment' => ['view_global']],
            'is_system' => false, 'scope' => $scope,
        ]);

        $user = $user ?: $this->plainUser();
        $user->update(['staff_role_id' => $role->id]);
        $employee->update(['user_id' => $user->id]);

        return $user->fresh();
    }

    /* ═══════════════ 3. PERMISSIONS ═════════════════════════════════ */

    /** @test */
    public function an_ordinary_employee_cannot_create_a_kpi(): void
    {
        Sanctum::actingAs($this->plainUser());

        $this->postJson('/api/hr/performance/kpis', ['name' => 'Sneaky'])->assertStatus(403);
        $this->assertSame(0, HrKpi::count());
    }

    /** @test */
    public function an_ordinary_employee_cannot_create_or_decide_a_review(): void
    {
        $e      = $this->employee();
        $review = $this->review($e);

        Sanctum::actingAs($this->plainUser());

        $this->postJson('/api/hr/performance/reviews', [
            'employee_id' => $e->id, 'review_type' => 'Annual', 'period_year' => 2026,
        ])->assertStatus(403);

        $this->patchJson('/api/hr/performance/reviews/'.$review->id.'/status', ['status' => 'Approved'])
            ->assertStatus(403);

        $this->assertSame('Draft', $review->fresh()->status);
    }

    /** @test */
    public function an_ordinary_employee_cannot_create_or_assign_a_goal(): void
    {
        Sanctum::actingAs($this->plainUser());

        $this->postJson('/api/hr/performance/goals', ['title' => 'Sneaky'])->assertStatus(403);
        $this->assertSame(0, HrGoal::count());
    }

    /** @test */
    public function an_hr_manager_can_do_all_of_it(): void
    {
        Sanctum::actingAs($this->hrUser());

        $this->postJson('/api/hr/performance/kpis', ['name' => 'Quality'])->assertStatus(201);
        $this->postJson('/api/hr/performance/goals', ['title' => 'Ship it'])->assertStatus(201);

        $this->assertSame(1, HrKpi::count());
        $this->assertSame(1, HrGoal::count());
    }

    /** Every performance route demands authentication. */
    public function test_performance_is_closed_to_anonymous_callers(): void
    {
        foreach ([
            ['get',  '/api/hr/performance/reviews'],
            ['get',  '/api/hr/performance/kpis'],
            ['get',  '/api/hr/performance/goals'],
            ['post', '/api/hr/performance/kpis'],
        ] as [$verb, $url]) {
            $this->json(strtoupper($verb), $url)->assertStatus(401);
        }
    }

    /* ═══════════════ 4. LIFECYCLE ═══════════════════════════════════ */

    /** @test */
    public function a_review_moves_through_its_lifecycle(): void
    {
        $review = $this->review($this->employee());
        Sanctum::actingAs($this->hrUser());

        $this->patchJson('/api/hr/performance/reviews/'.$review->id.'/status', ['status' => 'Submitted'])
            ->assertOk();
        $this->assertSame('Submitted', $review->fresh()->status);

        $this->patchJson('/api/hr/performance/reviews/'.$review->id.'/status', ['status' => 'Approved'])
            ->assertOk();
        $this->assertSame('Approved', $review->fresh()->status);
    }

    /** Approved is terminal — the service says so and this holds it to it. */
    public function test_an_approved_review_cannot_be_moved_again(): void
    {
        $review = $this->review($this->employee(), 'Approved');
        Sanctum::actingAs($this->hrUser());

        $this->patchJson('/api/hr/performance/reviews/'.$review->id.'/status', ['status' => 'Draft'])
            ->assertStatus(422);

        $this->assertSame('Approved', $review->fresh()->status);
    }

    /** @test */
    public function an_unknown_status_is_refused(): void
    {
        $review = $this->review($this->employee());
        Sanctum::actingAs($this->hrUser());

        $this->patchJson('/api/hr/performance/reviews/'.$review->id.'/status', ['status' => 'Whatever'])
            ->assertStatus(422);

        $this->assertSame('Draft', $review->fresh()->status);
    }
}
