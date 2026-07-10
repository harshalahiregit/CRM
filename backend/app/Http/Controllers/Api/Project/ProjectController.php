<?php

namespace App\Http\Controllers\Api\Project;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Http\Requests\Project\StoreProjectRequest;
use App\Http\Requests\Project\UpdateProjectRequest;
use App\Http\Requests\Project\UpdateProjectStatusRequest;
use App\Services\Project\ProjectService;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    use ApiResponse;

    public function __construct(private ProjectService $projects)
    {
    }

    public function index(Request $request)
    {
        $filters = $request->only(['status', 'customer_id', 'search']);
        return $this->success($this->projects->list($request->user()->tenant_id, $filters), 'Projects retrieved');
    }

    public function store(StoreProjectRequest $request)
    {
        $project = $this->projects->create($request->validated(), $request->user()->tenant_id, $request->user()->id);
        return $this->success($project, 'Project created', 201);
    }

    public function show(Request $request, int $project)
    {
        return $this->success($this->projects->show($project, $request->user()->tenant_id), 'Project retrieved');
    }

    public function update(UpdateProjectRequest $request, int $project)
    {
        return $this->success($this->projects->update($project, $request->validated(), $request->user()->tenant_id), 'Project updated');
    }

    public function destroy(Request $request, int $project)
    {
        $this->projects->delete($project, $request->user()->tenant_id);
        return $this->success(null, 'Project deleted');
    }

    public function updateStatus(UpdateProjectStatusRequest $request, int $project)
    {
        return $this->success($this->projects->changeStatus($project, $request->validated('status'), $request->user()->tenant_id), 'Status updated');
    }

    public function progress(Request $request, int $project)
    {
        return $this->success($this->projects->progress($project, $request->user()->tenant_id), 'Progress computed');
    }
}
