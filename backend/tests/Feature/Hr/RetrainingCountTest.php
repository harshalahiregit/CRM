<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrTrainingCategory;
use App\Models\Hr\HrTrainingProvider;
use App\Models\Hr\HrTrainingType;
use App\Models\Hr\HrTrainingProgram;
use App\Models\Hr\HrTrainingSession;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\EmployeeTrainingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Review comment #23 — "Reflect retraining no. to same employee in their or main
 * training section".
 *
 * Retraining is DERIVED from the existing assignment rows, not stored separately.
 * The count is the number of prior assignments for the same programme, so it can
 * never disagree with the history it is counting.
 */
class RetrainingCountTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private User $actor;

    private HrTrainingProgram $program;

    private HrTrainingCategory $category;

    private HrTrainingType $type;

    private HrTrainingProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active',
        ])->save();

        $this->actor = User::create([
            'tenant_id' => self::TENANT, 'name' => 'HR', 'email' => 'hr'.uniqid().'@test.com',
            'password' => bcrypt('secret'), 'role' => 'admin', 'status' => 'active',
        ]);

        $this->category = HrTrainingCategory::create([
            'tenant_id' => self::TENANT, 'name' => 'Compliance', 'code' => 'CMP', 'is_active' => true,
        ]);
        $this->type = HrTrainingType::create([
            'tenant_id' => self::TENANT, 'name' => 'Classroom', 'code' => 'CLS', 'is_active' => true,
        ]);
        $this->provider = HrTrainingProvider::create([
            'tenant_id' => self::TENANT, 'name' => 'In-house', 'provider_type' => 'Internal', 'is_active' => true,
        ]);
        $this->program = $this->program('Fire Safety', 'FS-1');
    }

    private function program(string $name, string $code): HrTrainingProgram
    {
        return HrTrainingProgram::create([
            'tenant_id' => self::TENANT, 'category_id' => $this->category->id,
            'training_type_id' => $this->type->id, 'provider_id' => $this->provider->id,
            'program_name' => $name, 'program_code' => $code, 'is_active' => true,
        ]);
    }

    private function employee(): HrEmployee
    {
        return HrEmployee::create([
            'tenant_id' => self::TENANT, 'name' => 'Trainee', 'employee_code' => 'TR-'.uniqid(),
            'department' => 'Ops', 'designation' => 'Executive', 'status' => 'Active',
            'joining_date' => '2020-01-01',
        ]);
    }

    /**
     * A fresh session of the same programme — each retraining needs its own.
     *
     * Named trainingSession(), not session(): Laravel's TestCase already declares a
     * public session(), and a private override is a fatal error.
     */
    private function trainingSession(string $title): HrTrainingSession
    {
        return HrTrainingSession::create([
            'tenant_id' => self::TENANT, 'training_program_id' => $this->program->id,
            'title' => $title, 'trainer_name' => 'Trainer', 'mode' => 'Online', 'capacity' => 50,
            'start_at' => now()->addDays(7), 'end_at' => now()->addDays(8),
            'status' => 'Scheduled',
        ]);
    }

    private function assign(HrEmployee $employee, HrTrainingSession $session, ?string $reason = null): array
    {
        return app(EmployeeTrainingService::class)->assign(array_filter([
            'employee_id'         => $employee->id,
            'training_session_id' => $session->id,
            'retraining_reason'   => $reason,
        ]), self::TENANT, $this->actor);
    }

    /* ── Attempt numbering ────────────────────────────────────────────── */

    public function test_a_first_assignment_is_attempt_one_and_not_retraining(): void
    {
        $employee = $this->employee();

        $first = $this->assign($employee, $this->trainingSession('Batch 1'));

        $this->assertSame(1, $first['attempt_number']);
        $this->assertFalse($first['is_retraining']);
        $this->assertNull($first['previous_training_id']);
    }

    public function test_repeating_the_same_programme_increments_the_attempt(): void
    {
        $employee = $this->employee();

        $first  = $this->assign($employee, $this->trainingSession('Batch 1'));
        $second = $this->assign($employee, $this->trainingSession('Batch 2'), 'Failed the assessment');
        $third  = $this->assign($employee, $this->trainingSession('Batch 3'), 'Annual refresher');

        $this->assertSame(2, $second['attempt_number']);
        $this->assertTrue($second['is_retraining']);
        $this->assertSame($first['id'], $second['previous_training_id'], 'attempts chain in order');
        $this->assertSame('Failed the assessment', $second['retraining_reason']);

        $this->assertSame(3, $third['attempt_number']);
        $this->assertSame($second['id'], $third['previous_training_id']);
    }

    public function test_a_different_programme_starts_its_own_count(): void
    {
        $employee = $this->employee();
        $other = $this->program('First Aid', 'FA-1');
        $otherSession = HrTrainingSession::create([
            'tenant_id' => self::TENANT, 'training_program_id' => $other->id, 'title' => 'FA Batch 1',
            'trainer_name' => 'Trainer', 'mode' => 'Online', 'capacity' => 50, 'start_at' => now()->addDays(7), 'end_at' => now()->addDays(8),
            'status' => 'Scheduled',
        ]);

        $this->assign($employee, $this->trainingSession('Batch 1'));
        $firstAid = $this->assign($employee, $otherSession);

        $this->assertSame(1, $firstAid['attempt_number'], 'a different programme is not retraining');
        $this->assertFalse($firstAid['is_retraining']);
    }

    public function test_each_employee_counts_separately(): void
    {
        $a = $this->employee();
        $b = $this->employee();
        $session1 = $this->trainingSession('Batch 1');
        $session2 = $this->trainingSession('Batch 2');

        $this->assign($a, $session1);
        $this->assign($a, $session2);
        $bFirst = $this->assign($b, $session1);

        $this->assertSame(1, $bFirst['attempt_number']);
    }

    /* ── History + summary ────────────────────────────────────────────── */

    public function test_retraining_history_lists_every_attempt_oldest_first(): void
    {
        $employee = $this->employee();
        $this->assign($employee, $this->trainingSession('Batch 1'));
        $this->assign($employee, $this->trainingSession('Batch 2'), 'Refresher');

        $history = app(EmployeeTrainingService::class)
            ->retrainingHistory($employee->id, $this->program->id, self::TENANT);

        $this->assertSame(2, $history['total_attempts']);
        $this->assertSame(1, $history['retraining_count'], 'the first go is not a retraining');
        $this->assertSame(1, $history['attempts'][0]['attempt_number'], 'oldest first, so it reads in order');
        $this->assertSame('Batch 2', $history['attempts'][1]['session']);
        $this->assertSame('Refresher', $history['attempts'][1]['reason']);
    }

    public function test_the_summary_lists_only_programmes_that_were_repeated(): void
    {
        $employee = $this->employee();
        $other = $this->program('First Aid', 'FA-1');
        HrTrainingSession::create([
            'tenant_id' => self::TENANT, 'training_program_id' => $other->id, 'title' => 'FA 1',
            'trainer_name' => 'Trainer', 'mode' => 'Online', 'capacity' => 50, 'start_at' => now()->addDays(7), 'end_at' => now()->addDays(8),
            'status' => 'Scheduled',
        ]);

        // Fire Safety twice, First Aid once.
        $this->assign($employee, $this->trainingSession('Batch 1'));
        $this->assign($employee, $this->trainingSession('Batch 2'));
        $this->assign($employee, HrTrainingSession::where('title', 'FA 1')->first());

        $summary = app(EmployeeTrainingService::class)->retrainingSummary($employee->id, self::TENANT);

        $this->assertCount(1, $summary, 'a programme done once is not retraining');
        $this->assertSame('Fire Safety', $summary[0]['program_name']);
        $this->assertSame(2, $summary[0]['total_attempts']);
        $this->assertSame(1, $summary[0]['retraining_count']);
    }

    public function test_an_employee_with_no_repeats_has_an_empty_summary(): void
    {
        $employee = $this->employee();
        $this->assign($employee, $this->trainingSession('Batch 1'));

        $this->assertSame([], app(EmployeeTrainingService::class)->retrainingSummary($employee->id, self::TENANT));
    }

    public function test_the_attempt_number_is_derived_not_accepted_from_the_client(): void
    {
        $employee = $this->employee();

        // A client claiming attempt 99 must be ignored — the history is the truth.
        $result = app(EmployeeTrainingService::class)->assign([
            'employee_id' => $employee->id,
            'training_session_id' => $this->trainingSession('Batch 1')->id,
            'attempt_number' => 99,
            'is_retraining' => true,
        ], self::TENANT, $this->actor);

        $this->assertSame(1, $result['attempt_number']);
        $this->assertFalse($result['is_retraining']);
    }

    /* ── The two lists the UI actually renders ────────────────────────── */

    /**
     * The Employee Profile → Training tab reads this endpoint and shows the attempt
     * number, the "Retraining" badge and the repeated-programme summary from it.
     * If the fields are missing here, every repeat silently reads as a first go.
     */
    public function test_the_employee_training_list_carries_the_attempt_number(): void
    {
        Sanctum::actingAs($this->actor);
        $employee = $this->employee();

        $this->assign($employee, $this->trainingSession('Batch 1'));
        $this->assign($employee, $this->trainingSession('Batch 2'), 'Failed the assessment');

        $rows = $this->getJson("/api/hr/learning/assignments/employee/{$employee->id}")
            ->assertOk()->json();

        $byAttempt = collect($rows)->keyBy('attempt_number');

        $this->assertCount(2, $rows);
        $this->assertFalse($byAttempt[1]['is_retraining']);
        $this->assertTrue($byAttempt[2]['is_retraining']);
        $this->assertSame('Failed the assessment', $byAttempt[2]['retraining_reason']);
    }

    /** The L&D → Assignment table reads this one and shows the same badge. */
    public function test_the_assignment_list_carries_the_attempt_number(): void
    {
        Sanctum::actingAs($this->actor);
        $employee = $this->employee();

        $this->assign($employee, $this->trainingSession('Batch 1'));
        $this->assign($employee, $this->trainingSession('Batch 2'), 'Annual refresher');

        $rows = $this->getJson('/api/hr/learning/assignments')->assertOk()->json('data');

        $retraining = collect($rows)->firstWhere('is_retraining', true);

        $this->assertNotNull($retraining, 'A repeat assignment must be flagged in the list payload.');
        $this->assertSame(2, $retraining['attempt_number']);
        $this->assertSame('Annual refresher', $retraining['retraining_reason']);
    }
}
