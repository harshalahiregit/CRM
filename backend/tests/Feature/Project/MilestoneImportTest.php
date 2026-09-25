<?php

namespace Tests\Feature\Project;

use App\Models\Project\Project;
use App\Models\Project\ProjectMilestone;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A plan arrives as a spreadsheet, not as twenty trips through a form.
 *
 * The only way to create a milestone was the form, once each. Twenty milestones
 * with dates is the normal shape of a project plan and it already exists as a
 * sheet, so retyping it is both the slow path and the one that introduces the
 * typos.
 *
 * The behaviour that matters most here is what happens to a BAD row. A sheet of
 * twenty where row nine has an unreadable date should import nineteen and say
 * what happened to the other — refusing the lot means the person fixes one cell
 * and uploads again, and again.
 */
class MilestoneImportTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private User $admin;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();

        $this->admin = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Super Admin', 'role' => 'admin',
            'email' => 'admin-'.Str::random(6).'@t.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);

        $this->project = Project::create([
            'tenant_id' => self::TENANT, 'name' => 'Warehouse fit-out',
            'start_date' => now()->toDateString(), 'created_by' => $this->admin->id,
        ]);
    }

    /** A CSV with the header row the importer expects. */
    private function sheet(string $body): UploadedFile
    {
        $csv = "name,start date,due date,description,colour\n".$body;

        return UploadedFile::fake()->createWithContent('plan.csv', $csv);
    }

    private function import(UploadedFile $file, ?int $projectId = null)
    {
        Sanctum::actingAs($this->admin);

        return $this->post(
            '/api/projects/'.($projectId ?? $this->project->id).'/milestones/import',
            ['file' => $file],
            ['Accept' => 'application/json'],
        );
    }

    public function test_a_sheet_of_milestones_becomes_milestones(): void
    {
        $res = $this->import($this->sheet(
            "Groundworks,2026-01-05,2026-02-10,Piling and slab,#ff0000\n".
            "Steel frame,2026-02-11,2026-04-01,,\n".
            "Cladding,2026-04-02,2026-05-20,,\n"
        ))->assertOk()->json('data');

        $this->assertSame(3, $res['created']);
        $this->assertSame(0, $res['skipped']);

        $rows = ProjectMilestone::where('project_id', $this->project->id)->orderBy('order')->get();
        $this->assertSame(['Groundworks', 'Steel frame', 'Cladding'], $rows->pluck('name')->all());
        $this->assertSame('2026-01-05', $rows[0]->start_date->toDateString());
        $this->assertSame('2026-02-10', $rows[0]->due_date->toDateString());
        $this->assertSame('Piling and slab', $rows[0]->description);
    }

    public function test_each_imported_milestone_gets_its_own_order_number(): void
    {
        // Read once and counted up from, rather than max()+1 per row — twenty
        // rows would otherwise all land on the same number and the list would
        // come back in whatever order the database felt like.
        $this->import($this->sheet("A,,2026-01-10,,\nB,,2026-02-10,,\nC,,2026-03-10,,\n"))->assertOk();

        $orders = ProjectMilestone::where('project_id', $this->project->id)
            ->orderBy('id')->pluck('order')->all();

        $this->assertSame([1, 2, 3], $orders);
    }

    public function test_an_import_continues_from_the_milestones_already_there(): void
    {
        ProjectMilestone::create([
            'tenant_id' => self::TENANT, 'project_id' => $this->project->id,
            'name' => 'Already here', 'order' => 7, 'due_date' => '2026-01-01',
        ]);

        $this->import($this->sheet("Next one,,2026-06-01,,\n"))->assertOk();

        $this->assertSame(8, (int) ProjectMilestone::where('name', 'Next one')->value('order'));
    }

    public function test_one_bad_row_does_not_lose_the_other_nineteen(): void
    {
        $res = $this->import($this->sheet(
            "Good one,2026-01-05,2026-02-10,,\n".
            "Bad date,2026-01-05,not a date,,\n".
            "Another good one,2026-03-01,2026-03-20,,\n"
        ))->assertOk()->json('data');

        $this->assertSame(2, $res['created']);
        $this->assertSame(1, $res['skipped']);
        // And it says WHICH row and why — "1 skipped" alone means opening the
        // sheet and guessing.
        $this->assertCount(1, $res['errors']);
        $this->assertStringContainsString('Row 3', $res['errors'][0]);
        $this->assertStringContainsString('not a date', $res['errors'][0]);
    }

    public function test_a_milestone_that_finishes_before_it_starts_is_refused(): void
    {
        $res = $this->import($this->sheet("Backwards,2026-05-01,2026-04-01,,\n"))
            ->assertOk()->json('data');

        $this->assertSame(0, $res['created']);
        $this->assertStringContainsString('finishes before it starts', $res['errors'][0]);
    }

    public function test_a_blank_line_is_not_a_failure(): void
    {
        // Spreadsheets are full of trailing empty rows. Counting them as errors
        // makes every real import look half-broken.
        $res = $this->import($this->sheet("Real one,,2026-06-01,,\n,,,,\n,,,,\n"))
            ->assertOk()->json('data');

        $this->assertSame(1, $res['created']);
        $this->assertSame(0, $res['skipped']);
        $this->assertSame([], $res['errors']);
    }

    public function test_a_milestone_with_no_due_date_is_named_rather_than_crashing(): void
    {
        // The column is NOT NULL and the form has always required a due date.
        // Left to the database this would abort the whole import with an
        // integrity error and take the valid rows down with it.
        $res = $this->import($this->sheet("Dated,,2026-06-01,,\nUndated,2026-01-01,,,\n"))
            ->assertOk()->json('data');

        $this->assertSame(1, $res['created']);
        $this->assertSame(1, $res['skipped']);
        $this->assertStringContainsString('Undated', $res['errors'][0]);
        $this->assertStringContainsString('needs one', $res['errors'][0]);
    }

    public function test_dates_are_accepted_in_the_formats_people_actually_type(): void
    {
        $this->import($this->sheet("Slash,05/01/2026,10/02/2026,,\n"))->assertOk();

        $m = ProjectMilestone::where('name', 'Slash')->first();
        $this->assertSame('2026-01-05', $m->start_date->toDateString());
    }

    public function test_a_project_in_another_tenant_cannot_be_imported_into(): void
    {
        (new Tenant())->forceFill([
            'id' => 2, 'name' => 'T2', 'slug' => 't2', 'subdomain' => 't2', 'status' => 'active',
        ])->save();
        $foreign = Project::create([
            'tenant_id' => 2, 'name' => 'Someone elses',
            'start_date' => now()->toDateString(), 'created_by' => $this->admin->id,
        ]);

        $this->import($this->sheet("Sneaky,,2026-06-01,,\n"), $foreign->id)->assertStatus(404);

        $this->assertSame(0, ProjectMilestone::count());
    }

    public function test_something_that_is_not_a_spreadsheet_is_refused(): void
    {
        Sanctum::actingAs($this->admin);

        $this->post("/api/projects/{$this->project->id}/milestones/import", [
            'file' => UploadedFile::fake()->createWithContent('plan.exe', 'MZ'),
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }
}
