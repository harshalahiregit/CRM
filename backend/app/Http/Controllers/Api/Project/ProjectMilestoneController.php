<?php

namespace App\Http\Controllers\Api\Project;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Http\Requests\Project\StoreMilestoneRequest;
use App\Http\Requests\Project\UpdateMilestoneRequest;
use App\Services\Project\ProjectService;
use Illuminate\Http\Request;

class ProjectMilestoneController extends Controller
{
    use ApiResponse;

    public function __construct(private ProjectService $projects)
    {
    }

    public function index(Request $request, int $project)
    {
        return $this->success($this->projects->listMilestones($project, $request->user()->tenant_id), 'Milestones retrieved');
    }

    public function store(StoreMilestoneRequest $request, int $project)
    {
        return $this->success($this->projects->createMilestone($project, $request->validated(), $request->user()->tenant_id), 'Milestone created', 201);
    }

    public function update(UpdateMilestoneRequest $request, int $milestone)
    {
        return $this->success($this->projects->updateMilestone($milestone, $request->validated(), $request->user()->tenant_id), 'Milestone updated');
    }

    public function destroy(Request $request, int $milestone)
    {
        $this->projects->deleteMilestone($milestone, $request->user()->tenant_id);
        return $this->success(null, 'Milestone deleted');
    }
}
