<?php

namespace Tests\Feature\Task;

use App\Models\Task\Task;
use App\Models\Task\TaskTimer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * PR1 — a task's effective billable amount is the fixed billable_amount when set,
 * else rate × logged hours; and the amount is visible only to admins (hidden from
 * staff at the API layer).
 */
class TaskBillableAmountTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private int $uid;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill(['id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active'])->save();
        $this->uid = $this->user('admin')->id;
    }

    private function user(string $role): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => ucfirst($role), 'role' => $role,
            'email' => $role.'-'.Str::random(6).'@test.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function task(array $over = []): Task
    {
        return Task::create(array_merge([
            'tenant_id' => self::TENANT, 'name' => 'Fix pump', 'status' => 'not_started',
            'priority' => 'medium', 'billable' => true, 'is_public' => true, 'created_by' => $this->uid,
            'start_date' => '2026-01-01',
        ], $over));
    }

    public function test_fixed_amount_wins_over_hours(): void
    {
        $t = $this->task(['billable_amount' => 6000, 'hourly_rate' => 800]);
        // Even with logged hours, the fixed amount is authoritative.
        TaskTimer::create([
            'tenant_id' => self::TENANT, 'task_id' => $t->id, 'user_id' => $this->uid,
            'start_time' => '2026-01-01 10:00:00', 'end_time' => '2026-01-01 12:00:00', 'hourly_rate' => 800,
        ]);

        $this->assertSame(6000.0, $t->fresh()->effectiveBillableAmount());
    }

    public function test_falls_back_to_rate_times_hours(): void
    {
        $t = $this->task(['billable_amount' => null, 'hourly_rate' => 800]);
        // 90 minutes = 1.5h × ₹800 = ₹1,200.
        TaskTimer::create([
            'tenant_id' => self::TENANT, 'task_id' => $t->id, 'user_id' => $this->uid,
            'start_time' => '2026-01-01 10:00:00', 'end_time' => '2026-01-01 11:30:00', 'hourly_rate' => 800,
        ]);

        $this->assertSame(1200.0, $t->fresh()->effectiveBillableAmount());
    }

    public function test_no_rate_and_no_amount_is_zero(): void
    {
        $t = $this->task(['billable_amount' => null, 'hourly_rate' => 0]);
        $this->assertSame(0.0, $t->fresh()->effectiveBillableAmount());
    }

    public function test_admin_sees_the_amount_but_staff_does_not(): void
    {
        $t = $this->task(['billable_amount' => 5000]);

        Sanctum::actingAs($this->user('admin'));
        $this->getJson("/api/tasks/{$t->id}")
            ->assertOk()
            ->assertJsonPath('data.billable_amount_effective', 5000);

        Sanctum::actingAs($this->user('staff'));
        $res = $this->getJson("/api/tasks/{$t->id}")->assertOk();
        $this->assertArrayNotHasKey('billable_amount_effective', $res->json('data'));
        $this->assertArrayNotHasKey('billable_amount', $res->json('data'));
    }
}
