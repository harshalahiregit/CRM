<?php

namespace Tests\Feature\Sire;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Sire\Models\Report;
use Sire\Models\ReportCategory;
use Sire\Models\ReportSeverity;
use Sire\Support\SirePriority;
use Tests\TestCase;

/**
 * Stage 1: what the person hitting the bug tells us, and what we do with it.
 *
 * The reporter states the category, how severe it is and how urgent it is,
 * because they are the one it is happening to -- a lead triaging a queue is
 * guessing. They can attach several images, not one.
 *
 * NONE OF IT IS REQUIRED. D45 stands: a title and a description, and nothing
 * else may block the form. Every extra required field is a person deciding not
 * to report the bug. These tests exist as much to hold that line as to prove
 * the fields work.
 */
class SireReportIntakeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'admin']);
        Sanctum::actingAs($this->user);
    }

    /** @return array<string, ReportSeverity> */
    private function severities(): array
    {
        $bands = [['s1', 'Critical', 4], ['s2', 'High', 3], ['s3', 'Medium', 2], ['s4', 'Low', 1]];
        $out = [];

        foreach ($bands as [$code, $name, $level]) {
            $out[$code] = ReportSeverity::factory()->create([
                'tenant_id' => $this->tenant->id, 'code' => $code, 'name' => $name, 'level' => $level,
            ]);
        }

        return $out;
    }

    private function file(array $payload = []): int
    {
        return (int) $this->postJson('/api/sire/reports', array_merge([
            'title'       => 'Saving a lead returns a 500',
            'description' => 'The spinner runs and then nothing happens at all.',
        ], $payload))->assertCreated()->json('data.report.id');
    }

    // --------------------------------------------------- the reporter triages

    public function test_the_reporter_sets_category_severity_and_urgency(): void
    {
        $severities = $this->severities();
        $category = ReportCategory::factory()->create([
            'tenant_id' => $this->tenant->id, 'code' => 'bug', 'name' => 'Bug',
        ]);

        $report = Report::findOrFail($this->file([
            'category_id' => $category->id,
            'severity_id' => $severities['s1']->id,
            'priority'    => SirePriority::P1,
        ]));

        $this->assertSame($category->id, $report->category_id);
        $this->assertSame($severities['s1']->id, $report->severity_id);
        $this->assertSame(SirePriority::P1, $report->priority);
    }

    public function test_the_reproduction_fields_are_kept(): void
    {
        $report = Report::findOrFail($this->file([
            'steps_to_reproduce' => "1. Open a lead\n2. Change the owner\n3. Save",
            'expected_result'    => 'The lead saves and the list refreshes.',
            'actual_result'      => 'A red toast reading Something went wrong.',
        ]));

        $this->assertStringContainsString('Change the owner', $report->steps_to_reproduce);
        $this->assertStringContainsString('saves', $report->expected_result);
        $this->assertStringContainsString('red toast', $report->actual_result);
    }

    public function test_none_of_it_is_required_d45(): void
    {
        // Two fields. This is the whole contract of the global button.
        $this->postJson('/api/sire/reports', [
            'title'       => 'Saving a lead returns a 500',
            'description' => 'The spinner runs and then nothing happens at all.',
        ])->assertCreated();
    }

    public function test_a_severity_from_another_tenant_is_refused(): void
    {
        $theirs = ReportSeverity::factory()->create([
            'tenant_id' => Tenant::factory()->create()->id,
            'code' => 's1', 'name' => 'Critical', 'level' => 4,
        ]);

        $this->postJson('/api/sire/reports', [
            'title'       => 'Saving a lead returns a 500',
            'description' => 'The spinner runs and then nothing happens at all.',
            'severity_id' => $theirs->id,
        ])->assertStatus(422);
    }

    public function test_an_unknown_priority_is_refused(): void
    {
        $this->postJson('/api/sire/reports', [
            'title'       => 'Saving a lead returns a 500',
            'description' => 'The spinner runs and then nothing happens at all.',
            'priority'    => 'whenever',
        ])->assertStatus(422);
    }

    // ------------------------------------------------------- the form's lists

    public function test_the_form_options_carry_the_three_lists_and_a_middling_default(): void
    {
        $severities = $this->severities();
        ReportCategory::factory()->create(['tenant_id' => $this->tenant->id, 'code' => 'bug', 'name' => 'Bug']);

        $data = $this->getJson('/api/sire/report-options')->assertOk()->json('data');

        $this->assertCount(1, $data['categories']);
        $this->assertCount(4, $data['severities']);
        $this->assertCount(4, $data['priorities']);

        // The middle of the scale, not the top. A default of Critical would be a
        // claim every reporter who left it alone had made by accident.
        $this->assertSame($severities['s3']->id, $data['defaults']['severity_id']);
        $this->assertSame(SirePriority::P3, $data['defaults']['priority']);

        // Highest band first, so the list reads the way people think about it.
        $this->assertSame('Critical', $data['severities'][0]['name']);
    }

    public function test_the_form_options_are_tenant_scoped(): void
    {
        $other = Tenant::factory()->create();
        ReportCategory::factory()->create(['tenant_id' => $other->id, 'code' => 'theirs', 'name' => 'Theirs']);
        ReportCategory::factory()->create(['tenant_id' => $this->tenant->id, 'code' => 'mine', 'name' => 'Mine']);

        $names = collect($this->getJson('/api/sire/report-options')->assertOk()->json('data.categories'))
            ->pluck('name');

        $this->assertTrue($names->contains('Mine'));
        $this->assertFalse($names->contains('Theirs'), 'another workspace category leaked into the form');
    }

    // ------------------------------------------------------------ several images

    public function test_several_images_can_be_attached_to_one_report(): void
    {
        Storage::fake('local');

        $id = $this->file();

        foreach (['form.jpg', 'error.jpg', 'record.jpg'] as $name) {
            $this->post("/api/sire/reports/{$id}/attachments", [
                'file' => UploadedFile::fake()->image($name, 640, 480),
            ])->assertSuccessful();
        }

        $listed = $this->getJson("/api/sire/reports/{$id}/attachments")->assertOk()->json('data');

        $this->assertCount(3, $listed, 'all three images should be listed against the issue');
    }

    public function test_evidence_stays_inside_the_reporters_tenant(): void
    {
        Storage::fake('local');

        $id = $this->file();

        $this->post("/api/sire/reports/{$id}/attachments", [
            'file' => UploadedFile::fake()->image('error.jpg', 640, 480),
        ])->assertSuccessful();

        $intruder = User::factory()->create([
            'tenant_id' => Tenant::factory()->create()->id, 'role' => 'admin',
        ]);
        Sanctum::actingAs($intruder);

        $this->getJson("/api/sire/reports/{$id}/attachments")->assertNotFound();
    }

    // --------------------------------------------------------- the ticket number

    public function test_submitting_generates_a_unique_reference(): void
    {
        $numbers = [];

        foreach (range(1, 3) as $i) {
            $numbers[] = $this->postJson('/api/sire/reports', [
                'title'       => "Something broke number {$i}",
                'description' => 'The spinner runs and then nothing happens at all.',
            ])->assertCreated()->json('data.report.report_number');
        }

        $this->assertCount(3, array_unique($numbers), 'every report needs its own reference');

        foreach ($numbers as $number) {
            $this->assertMatchesRegularExpression('/^SIR-\d{6}$/', $number);
        }
    }
}
