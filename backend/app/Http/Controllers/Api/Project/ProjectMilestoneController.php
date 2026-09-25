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

    private function isAdmin(Request $request): bool
    {
        return $request->user()?->role === 'admin';
    }

    public function index(Request $request, int $project)
    {
        $this->projects->assertProjectVisible($project, $request->user()->tenant_id, $request->user()->id, $this->isAdmin($request));
        return $this->success($this->projects->listMilestones($project, $request->user()->tenant_id), 'Milestones retrieved');
    }

    public function store(StoreMilestoneRequest $request, int $project)
    {
        $this->projects->assertProjectManage($project, $request->user()->tenant_id, $request->user()->id, $this->isAdmin($request));
        return $this->success($this->projects->createMilestone($project, $request->validated(), $request->user()->tenant_id), 'Milestone created', 201);
    }

    /**
     * Create many milestones from one uploaded sheet.
     *
     * A plan arrives as a spreadsheet; the only way in was a form, once per
     * milestone. Same file types the workforce importer takes — csv, xls, xlsx —
     * because it is the same reader.
     */
    public function import(Request $request, int $project)
    {
        $this->projects->assertProjectManage($project, $request->user()->tenant_id, $request->user()->id, $this->isAdmin($request));

        $request->validate([
            'file' => 'required|file|mimes:csv,txt,xls,xlsx|max:5120',
        ], [
            'file.required' => 'Choose the spreadsheet to import.',
            'file.mimes'    => 'Upload a .csv, .xls or .xlsx file.',
        ]);

        return $this->success(
            $this->projects->importMilestones($project, $request->file('file'), $request->user()->tenant_id),
            'Milestones imported'
        );
    }

    public function update(UpdateMilestoneRequest $request, int $milestone)
    {
        $this->projects->assertMilestoneManage($milestone, $request->user()->tenant_id, $request->user()->id, $this->isAdmin($request));
        return $this->success($this->projects->updateMilestone($milestone, $request->validated(), $request->user()->tenant_id), 'Milestone updated');
    }

    public function destroy(Request $request, int $milestone)
    {
        $this->projects->assertMilestoneManage($milestone, $request->user()->tenant_id, $request->user()->id, $this->isAdmin($request));
        $this->projects->deleteMilestone($milestone, $request->user()->tenant_id);
        return $this->success(null, 'Milestone deleted');
    }
}
