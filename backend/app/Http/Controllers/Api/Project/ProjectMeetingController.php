<?php

namespace App\Http\Controllers\Api\Project;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Models\Project\ProjectMeeting;
use App\Services\Project\ProjectService;
use Illuminate\Http\Request;

/**
 * Kickoff Meeting tab (owner: Shivam). Read guarded by project visibility;
 * writes guarded by project-manage. Access checks are delegated to ProjectService
 * (the same guards the Project workspace uses) so this tab never diverges.
 */
class ProjectMeetingController extends Controller
{
    use ApiResponse;

    public function __construct(private ProjectService $projects)
    {
    }

    private function isAdmin(Request $request): bool
    {
        return $request->user()?->role === 'admin';
    }

    private function guardView(Request $request, int $project): void
    {
        $this->projects->assertProjectVisible($project, $request->user()->tenant_id, $request->user()->id, $this->isAdmin($request));
    }

    private function guardManage(Request $request, int $project): void
    {
        $this->projects->assertProjectManage($project, $request->user()->tenant_id, $request->user()->id, $this->isAdmin($request));
    }

    /** A meeting scoped to this tenant + project, or a 404. */
    private function findMeeting(int $tenantId, int $project, int $meeting): ProjectMeeting
    {
        $row = ProjectMeeting::forTenant($tenantId)->where('project_id', $project)->find($meeting);
        if (! $row) {
            throw new BusinessException('Meeting not found.', 404);
        }

        return $row;
    }

    /** List meetings (newest first) with {total, completed, pending} counters. */
    public function index(Request $request, int $project)
    {
        $this->guardView($request, $project);
        $tenantId = $request->user()->tenant_id;

        $meetings = ProjectMeeting::forTenant($tenantId)
            ->where('project_id', $project)
            ->with('creator:id,name')
            ->latest()
            ->get();

        $counters = [
            'total'     => $meetings->count(),
            'completed' => $meetings->where('status', 'completed')->count(),
            'pending'   => $meetings->where('status', 'pending')->count(),
        ];

        return $this->success(['meetings' => $meetings, 'counters' => $counters], 'Meetings retrieved');
    }

    public function store(Request $request, int $project)
    {
        $this->guardManage($request, $project);
        $data = $request->validate([
            'title'        => 'required|string|max:255',
            'mode'         => 'nullable|in:online,offline,hybrid',
            'participants' => 'nullable|string|max:2000',
            'planned_date' => 'nullable|date',
            'meeting_date' => 'nullable|date',
            'status'       => 'nullable|in:pending,completed',
            'mom_sent'     => 'nullable|boolean',
            'notes'        => 'nullable|string|max:20000',
        ]);

        $meeting = ProjectMeeting::create([
            ...$data,
            'tenant_id'  => $request->user()->tenant_id,
            'project_id' => $project,
            'mode'       => $data['mode'] ?? 'online',
            'status'     => $data['status'] ?? 'pending',
            'mom_sent'   => $data['mom_sent'] ?? false,
            'created_by' => $request->user()->id,
        ]);

        return $this->success($meeting->load('creator:id,name'), 'Meeting created', 201);
    }

    public function update(Request $request, int $project, int $meeting)
    {
        $this->guardManage($request, $project);
        $row = $this->findMeeting($request->user()->tenant_id, $project, $meeting);

        $data = $request->validate([
            'title'        => 'sometimes|required|string|max:255',
            'mode'         => 'nullable|in:online,offline,hybrid',
            'participants' => 'nullable|string|max:2000',
            'planned_date' => 'nullable|date',
            'meeting_date' => 'nullable|date',
            'status'       => 'nullable|in:pending,completed',
            'mom_sent'     => 'nullable|boolean',
            'notes'        => 'nullable|string|max:20000',
        ]);

        $row->fill($data)->save();

        return $this->success($row->fresh('creator:id,name'), 'Meeting updated');
    }

    public function destroy(Request $request, int $project, int $meeting)
    {
        $this->guardManage($request, $project);
        $this->findMeeting($request->user()->tenant_id, $project, $meeting)->delete();

        return $this->success(null, 'Meeting deleted');
    }
}
