<?php

namespace Tests\Feature\Sire;

use Sire\Models\IssueTestCase;
use Sire\Models\Report;
use App\Models\Tenant;
use App\Models\User;
use Sire\Contracts\SireSettingsProvider;
use Sire\Support\Ai\AiCapability;
use Sire\Support\SireStatus;
use Sire\Support\SireTestCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Test cases, generation, and the invariant that holds the feature together.
 *
 * The assertions that matter most are the ones about `result`: a generated test
 * that arrived "passed" would be worse than no test at all, so several tests here
 * attack that from different directions.
 */
class SireTestCaseTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $a;
    private Tenant $b;
    private User $dev;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = Tenant::factory()->create();
        $this->b = Tenant::factory()->create();
        $this->dev = User::factory()->create(['tenant_id' => $this->a->id, 'role' => 'admin']);

        $settings = app(SireSettingsProvider::class);
        $settings->set($this->a->id, 'sire.ai.enabled', true);
        $settings->set($this->a->id, 'sire.ai.capabilities', [
            AiCapability::DEVELOPER_TEST_CASES => true,
            AiCapability::QA_TEST_CASES => true,
            AiCapability::ROOT_CAUSE_SUGGESTION => true,
        ]);
    }

    // ------------------------------------------- results are human-controlled

    public function test_a_new_test_case_is_always_unrun_whatever_the_payload_claims(): void
    {
        $report = $this->issue();

        Sanctum::actingAs($this->dev);
        $this->postJson("/api/sire/reports/{$report->id}/test-cases", [
            'test_cases' => [[
                'category' => SireTestCategory::HAPPY_PATH,
                'title' => 'It works',
                // A payload claiming a pass. It must not merely be ignored — it
                // must never reach the service.
                'result' => 'passed',
                'executed_by' => $this->dev->id,
                'executed_at' => now()->toIso8601String(),
            ]],
        ])->assertCreated();

        $case = IssueTestCase::where('report_id', $report->id)->firstOrFail();
        $this->assertNull($case->result, 'a test nobody ran has no result');
        $this->assertNull($case->executed_by);
        $this->assertSame(IssueTestCase::ACTIVE, $case->status);
    }

    public function test_generated_test_cases_are_never_created_automatically(): void
    {
        $report = $this->issue([
            'title' => 'Staff get a 403 saving notes over 5000 characters',
            'steps_to_reproduce' => 'Open a lead, paste a long note, press Save',
            'expected_result' => 'The note saves', 'actual_result' => 'A 403 is returned',
        ]);

        Sanctum::actingAs($this->dev);
        $data = $this->postJson("/api/sire/ai/reports/{$report->id}/insights")->assertOk()->json('data');

        // A suggestion exists and holds proposals; no test case row does.
        $this->assertNotNull($data['test_cases']);
        $this->assertNotEmpty($data['test_cases']['payload']['test_cases']);
        $this->assertDatabaseCount('sire_test_cases', 0);
    }

    public function test_every_generated_proposal_arrives_without_a_result(): void
    {
        $report = $this->issue(['title' => 'Lead save fails over 5000 chars', 'steps_to_reproduce' => 'Save']);

        Sanctum::actingAs($this->dev);
        $data = $this->postJson("/api/sire/ai/reports/{$report->id}/insights")->assertOk()->json('data');

        foreach ($data['test_cases']['payload']['test_cases'] as $case) {
            $this->assertNull($case['result'], "{$case['category']} arrived with a result");
            $this->assertSame('draft', $case['status']);
            $this->assertSame('ai_suggested', $case['source']);
        }
    }

    public function test_recording_a_result_requires_the_qa_capability(): void
    {
        $report = $this->issue(['status' => SireStatus::QA_IN_PROGRESS]);
        $case = $this->testCase($report);

        $outsider = User::factory()->create(['tenant_id' => $this->a->id, 'role' => 'client']);
        Sanctum::actingAs($outsider);

        $this->postJson("/api/sire/test-cases/{$case->id}/result", ['result' => 'passed'])->assertForbidden();
        $this->assertNull($case->fresh()->result);
    }

    public function test_a_result_cannot_be_recorded_outside_development_or_qa(): void
    {
        // A checklist going green before anyone looked at the build.
        $report = $this->issue(['status' => SireStatus::TRIAGED]);
        $case = $this->testCase($report);

        Sanctum::actingAs($this->dev);
        $this->postJson("/api/sire/test-cases/{$case->id}/result", ['result' => 'passed'])->assertStatus(409);
        $this->assertNull($case->fresh()->result);
    }

    public function test_a_recorded_result_names_the_person_who_recorded_it(): void
    {
        $report = $this->issue(['status' => SireStatus::QA_IN_PROGRESS]);
        $case = $this->testCase($report);

        Sanctum::actingAs($this->dev);
        $this->postJson("/api/sire/test-cases/{$case->id}/result", [
            'result' => 'failed', 'note' => 'Still 403 on the second save.',
        ])->assertOk();

        $fresh = $case->fresh();
        $this->assertSame('failed', $fresh->result);
        $this->assertSame((int) $this->dev->id, (int) $fresh->executed_by);
        $this->assertNotNull($fresh->executed_at);
        $this->assertTrue($fresh->hasHumanResult());
    }

    public function test_only_the_four_documented_results_are_accepted(): void
    {
        $report = $this->issue(['status' => SireStatus::QA_IN_PROGRESS]);
        $case = $this->testCase($report);

        Sanctum::actingAs($this->dev);
        foreach (['auto_passed', 'ok', 'green', ''] as $bogus) {
            $this->postJson("/api/sire/test-cases/{$case->id}/result", ['result' => $bogus])->assertStatus(422);
        }
        $this->assertNull($case->fresh()->result);
    }

    public function test_editing_a_test_case_cannot_set_a_result(): void
    {
        $report = $this->issue(['status' => SireStatus::QA_IN_PROGRESS]);
        $case = $this->testCase($report);

        Sanctum::actingAs($this->dev);
        $this->patchJson("/api/sire/test-cases/{$case->id}", [
            'title' => 'Renamed', 'result' => 'passed',
        ])->assertOk();

        $this->assertSame('Renamed', $case->fresh()->title);
        $this->assertNull($case->fresh()->result, 'update() must not be a back door to result');
    }

    public function test_removing_a_test_keeps_the_record_of_it_having_been_proposed(): void
    {
        $report = $this->issue();
        $case = $this->testCase($report, ['source' => IssueTestCase::SOURCE_AI]);

        Sanctum::actingAs($this->dev);
        $this->deleteJson("/api/sire/test-cases/{$case->id}", ['reason' => 'Covered by another test'])->assertOk();

        // Marked removed, not deleted — a rejected proposal is feedback about the
        // generator, and deleting the row throws it away.
        $this->assertDatabaseHas('sire_test_cases', ['id' => $case->id, 'status' => IssueTestCase::REMOVED]);
        $this->assertNull($case->fresh()->deleted_at);
    }

    // ------------------------------------------------------------- generation

    public function test_the_two_mandatory_categories_always_appear(): void
    {
        $report = $this->issue(['title' => 'Column header misaligned']);

        Sanctum::actingAs($this->dev);
        $data = $this->postJson("/api/sire/ai/reports/{$report->id}/insights")->assertOk()->json('data');

        $categories = array_column($data['test_cases']['payload']['test_cases'], 'category');
        foreach (SireTestCategory::MANDATORY as $mandatory) {
            $this->assertContains($mandatory, $categories);
        }
    }

    public function test_a_permission_case_appears_only_where_relevant(): void
    {
        Sanctum::actingAs($this->dev);

        $plain = $this->issue(['title' => 'Column header misaligned', 'related_type' => null]);
        $categories = array_column(
            $this->postJson("/api/sire/ai/reports/{$plain->id}/insights")->json('data.test_cases.payload.test_cases'),
            'category',
        );
        $this->assertNotContains(SireTestCategory::PERMISSION, $categories);

        $gated = $this->issue(['title' => 'Staff get a 403 opening a lead']);
        $categories = array_column(
            $this->postJson("/api/sire/ai/reports/{$gated->id}/insights")->json('data.test_cases.payload.test_cases'),
            'category',
        );
        $this->assertContains(SireTestCategory::PERMISSION, $categories);
    }

    // -------------------------------------------------------------- isolation

    public function test_test_cases_are_tenant_isolated(): void
    {
        $theirReport = Report::factory()->create(['tenant_id' => $this->b->id]);
        $theirCase = IssueTestCase::factory()->create(['tenant_id' => $this->b->id, 'report_id' => $theirReport->id]);

        Sanctum::actingAs($this->dev);

        $this->getJson("/api/sire/reports/{$theirReport->id}/test-cases")->assertNotFound();
        $this->postJson("/api/sire/reports/{$theirReport->id}/test-cases", [
            'test_cases' => [['category' => SireTestCategory::HAPPY_PATH, 'title' => 'x']],
        ])->assertNotFound();
        $this->postJson("/api/sire/test-cases/{$theirCase->id}/result", ['result' => 'passed'])->assertNotFound();

        $this->assertNull($theirCase->fresh()->result);
    }

    // ------------------------------------------------- AI failure is survivable

    public function test_the_qa_workflow_works_with_ai_disabled(): void
    {
        app(SireSettingsProvider::class)->set($this->a->id, 'sire.ai.enabled', false);

        $report = $this->issue(['status' => SireStatus::QA_IN_PROGRESS]);

        Sanctum::actingAs($this->dev);

        // Writing and running tests by hand is unaffected by AI being off.
        $this->postJson("/api/sire/reports/{$report->id}/test-cases", [
            'test_cases' => [['category' => SireTestCategory::FAILURE_PATH, 'title' => 'Hand-written']],
        ])->assertCreated();

        $case = IssueTestCase::where('report_id', $report->id)->firstOrFail();
        $this->postJson("/api/sire/test-cases/{$case->id}/result", ['result' => 'passed'])->assertOk();

        $this->assertSame('passed', $case->fresh()->result);
    }

    public function test_a_broken_generator_does_not_break_the_issue_page(): void
    {
        $this->mock(\Sire\AI\SireTestCaseGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->andThrow(new \RuntimeException('generator exploded'));
        });

        $report = $this->issue();

        Sanctum::actingAs($this->dev);
        $data = $this->postJson("/api/sire/ai/reports/{$report->id}/insights")->assertOk()->json('data');

        // Null means "no suggestions", never an error.
        $this->assertNull($data['test_cases']);
    }

    private function issue(array $attributes = []): Report
    {
        return Report::factory()->create(array_merge([
            'tenant_id' => $this->a->id,
            'module' => 'sales', 'section' => 'leads', 'screen' => 'lead-details',
            'related_type' => 'lead',
        ], $attributes));
    }

    private function testCase(Report $report, array $attributes = []): IssueTestCase
    {
        return IssueTestCase::factory()->create(array_merge([
            'tenant_id' => $this->a->id,
            'report_id' => $report->id,
            'category'  => SireTestCategory::FAILURE_PATH,
            'status'    => IssueTestCase::ACTIVE,
            'result'    => null,
        ], $attributes));
    }
}
