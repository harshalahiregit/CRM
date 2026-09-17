<?php

namespace Tests\Feature\Project;

use App\Models\Project\Project;
use App\Models\Project\ProjectMilestone;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Project\ProjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A new milestone goes on the end of the list.
 *
 * `order` is what the milestone list sorts by, and nothing was choosing it. The
 * form offered the number 1 as a default and posted it unchanged, so every
 * milestone anybody created arrived at 1 — three milestones, three 1s, and an
 * ordered list whose order was decided by whatever the database happened to
 * return. Reported as "auto order no is not getting generated while creating
 * milestone", which is exactly what it was: nothing generated one.
 *
 * Chosen on the server rather than in the form because the next number depends
 * on the other milestones — a client would have to fetch them first and could
 * race another person doing the same thing.
 */
class MilestonesGetTheNextOrderNumberTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'status' => 'active',
        ])->save();

        $owner = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Priya', 'email' => 'priya@ms.test',
            'password' => Hash::make('x'), 'role' => 'admin', 'status' => 'active',
        ]);

        $this->project = Project::create([
            'tenant_id' => self::TENANT, 'name' => 'Plant upgrade',
            'status' => 'in_progress', 'created_by' => $owner->id,
            'start_date' => now()->toDateString(),
        ]);
    }

    private function add(array $data = []): ProjectMilestone
    {
        return app(ProjectService::class)->createMilestone(
            $this->project->id,
            ['name' => 'M'.uniqid(), 'due_date' => now()->addDays(7)->toDateString()] + $data,
            self::TENANT,
        );
    }

    public function test_each_new_milestone_takes_the_next_number(): void
    {
        $this->assertSame(1, $this->add()->order);
        $this->assertSame(2, $this->add()->order);
        $this->assertSame(3, $this->add()->order);
    }

    /** It continues from what is already there, not from one. */
    public function test_it_continues_an_existing_list(): void
    {
        ProjectMilestone::create([
            'tenant_id' => self::TENANT, 'project_id' => $this->project->id,
            'name' => 'Discovery', 'due_date' => now()->addDay()->toDateString(), 'order' => 7,
        ]);

        $this->assertSame(8, $this->add()->order);
    }

    /** A deliberate position still wins — this fills a gap, it does not override. */
    public function test_an_explicit_order_is_honoured(): void
    {
        $this->assertSame(42, $this->add(['order' => 42])->order);
    }

    /**
     * Numbering is per project. Two projects each start their own list, and a
     * busy one must not push the next milestone on a quiet one to 300.
     */
    public function test_numbering_does_not_leak_between_projects(): void
    {
        $this->add();
        $this->add();

        $other = Project::create([
            'tenant_id' => self::TENANT, 'name' => 'Second site',
            'status' => 'in_progress', 'created_by' => User::first()->id,
            'start_date' => now()->toDateString(),
        ]);

        $first = app(ProjectService::class)->createMilestone(
            $other->id,
            ['name' => 'Kickoff', 'due_date' => now()->addDays(7)->toDateString()],
            self::TENANT,
        );

        $this->assertSame(1, $first->order, 'a new project starts its own numbering');
    }
}
