<?php

namespace App\Http\Controllers\Api\Project;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Http\Requests\Project\StoreProjectRequest;
use App\Http\Requests\Project\SyncMembersRequest;
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
        $filters = $request->only(['status', 'customer_id', 'search', 'member', 'tag']);
        return $this->success($this->projects->list($request->user()->tenant_id, $filters, $request->user()->id), 'Projects retrieved');
    }

    public function store(StoreProjectRequest $request)
    {
        $project = $this->projects->create($request->validated(), $request->user()->tenant_id, $request->user()->id);
        return $this->success($project, 'Project created', 201);
    }

    public function show(Request $request, int $project)
    {
        return $this->success($this->projects->show($project, $request->user()->tenant_id, $request->user()->id), 'Project retrieved');
    }

    public function update(UpdateProjectRequest $request, int $project)
    {
        return $this->success($this->projects->update($project, $request->validated(), $request->user()->tenant_id, $request->user()->id), 'Project updated');
    }

    public function destroy(Request $request, int $project)
    {
        $this->projects->delete($project, $request->user()->tenant_id);
        return $this->success(null, 'Project deleted');
    }

    public function updateStatus(UpdateProjectStatusRequest $request, int $project)
    {
        return $this->success($this->projects->changeStatus($project, $request->validated('status'), $request->user()->tenant_id, $request->user()->id), 'Status updated');
    }

    public function progress(Request $request, int $project)
    {
        return $this->success($this->projects->progress($project, $request->user()->tenant_id), 'Progress computed');
    }

    public function members(SyncMembersRequest $request, int $project)
    {
        $members = $this->projects->syncMembers($project, $request->validated('user_ids'), $request->user()->tenant_id, $request->user()->id);
        return $this->success($members, 'Members updated');
    }

    /** Clone a project. Members/milestones are opt-in; tasks and files never copy. */
    public function copy(Request $request, int $project)
    {
        $opts = $request->validate([
            'name'            => 'nullable|string|max:255',
            'start_date'      => 'nullable|date',
            'deadline'        => 'nullable|date',
            'copy_members'    => 'nullable|boolean',
            'copy_milestones' => 'nullable|boolean',
        ]);

        $copy = $this->projects->copy($project, $opts, $request->user()->tenant_id, $request->user()->id);

        return $this->success($copy, 'Project copied', 201);
    }

    /** Pin/unpin for the current user only. */
    public function pin(Request $request, int $project)
    {
        $p = $this->projects->togglePin($project, $request->user()->tenant_id, $request->user()->id);

        return $this->success(['is_pinned' => in_array($request->user()->id, array_map('intval', $p->pinned_by ?? []), true)], 'Pin updated');
    }

    /**
     * Customers for the "which customer is this for?" picker. Reads through
     * CustomerServiceContract — Projects never touches the customers table.
     */
    public function customers(Request $request)
    {
        return $this->success($this->projects->listCustomers($request->user()->tenant_id), 'Customers retrieved');
    }

    /** Assignable staff for the member picker — mirrors /tasks/staff. */
    public function staff(Request $request)
    {
        $staff = \App\Models\User::where('tenant_id', $request->user()->tenant_id)
            ->where('status', 'active')
            ->whereNotIn('role', ['client', 'vendor', 'third_party_vendor'])
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role']);

        return $this->success($staff, 'Staff retrieved');
    }

    /* ── Integration 3a: tickets linked to this project ────────── */
    public function tickets(Request $request, int $project)
    {
        $tenantId = $request->user()->tenant_id;
        $tickets = \App\Models\Helpdesk\Ticket::forTenant($tenantId)
            ->where('project_id', $project)
            ->with('assignee:id,name')
            ->latest()
            ->get();

        return $this->success($tickets, 'Project tickets retrieved');
    }
}
