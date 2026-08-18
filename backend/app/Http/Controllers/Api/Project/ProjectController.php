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

    private function isAdmin(Request $request): bool
    {
        return $request->user()?->role === 'admin';
    }

    /** View access (admin, creator, or member). */
    private function guardView(Request $request, int $project): void
    {
        $this->projects->assertProjectVisible($project, $request->user()->tenant_id, $request->user()->id, $this->isAdmin($request));
    }

    /** Manage access (admin or the project's creator). */
    private function guardManage(Request $request, int $project): void
    {
        $this->projects->assertProjectManage($project, $request->user()->tenant_id, $request->user()->id, $this->isAdmin($request));
    }

    /**
     * The Project Settings catalog: the full tab list (with which are actually
     * implemented), the customer-permission toggles, and the contact-notification
     * modes — plus the default tab set. The two-tab create form and the workspace
     * tab bar both render from this, so frontend options never drift from what the
     * backend validates. Static config, so any authed staff member may read it.
     */
    public function meta()
    {
        return $this->success([
            'tabs'                    => config('projects.tabs', []),
            'default_tabs'           => \App\Services\Project\ProjectService::defaultVisibleTabs(),
            'customer_permissions'   => config('projects.customer_permissions', []),
            'vendor_permissions'     => config('projects.vendor_permissions', []),
            'tpv_permissions'        => config('projects.tpv_permissions', []),
            'contacts_notification'  => config('projects.contacts_notification', []),
            'billing_types'          => [
                ['key' => 'fixed',         'label' => 'Fixed Rate'],
                ['key' => 'project_hours', 'label' => 'Project Hours'],
                ['key' => 'task_hours',    'label' => 'Task Hours'],
            ],
        ], 'Project settings catalog');
    }

    /**
     * Lightweight ACTIVE-project list for cross-module dropdowns (reused by HR
     * Recruitment). Minimal fields only — no duplicate project logic. Any authed
     * staff member may read it (same trust level as the settings catalog).
     */
    public function options(Request $request)
    {
        return $this->success($this->projects->options($request->user()->tenant_id), 'Active project options');
    }

    public function index(Request $request)
    {
        // vendor_id powers a vendor detail Projects tab; vendor_type says WHICH
        // kind of vendor, because the same integer is a different company under
        // each party type. Both ride the normal project list, so tenant scoping
        // and the non-admin visibility barrier below still apply unchanged.
        //
        // vendor_type is validated here and resolved against a fixed map inside
        // the scope — a raw link_type is never accepted from a caller.
        $filters = $request->only(['status', 'customer_id', 'search', 'member', 'tag', 'vendor_id', 'vendor_type']);
        $filters['vendor_type'] = $this->vendorType($request);
        return $this->success(
            $this->projects->list($request->user()->tenant_id, $filters, $request->user()->id, $this->isAdmin($request)),
            'Projects retrieved'
        );
    }

    public function store(StoreProjectRequest $request)
    {
        // Portal roles (client/vendor) never create projects — only internal staff.
        abort_if(in_array($request->user()->role, ['client', 'vendor', 'third_party_vendor'], true), 403, 'You cannot create projects.');

        $project = $this->projects->create($request->validated(), $request->user()->tenant_id, $request->user()->id);
        return $this->success($project, 'Project created', 201);
    }

    public function show(Request $request, int $project)
    {
        $this->guardView($request, $project);
        return $this->success($this->projects->show($project, $request->user()->tenant_id, $request->user()->id), 'Project retrieved');
    }

    public function update(UpdateProjectRequest $request, int $project)
    {
        $this->guardManage($request, $project);
        return $this->success($this->projects->update($project, $request->validated(), $request->user()->tenant_id, $request->user()->id), 'Project updated');
    }

    public function destroy(Request $request, int $project)
    {
        $this->guardManage($request, $project);
        $this->projects->delete($project, $request->user()->tenant_id);
        return $this->success(null, 'Project deleted');
    }

    public function updateStatus(UpdateProjectStatusRequest $request, int $project)
    {
        $this->guardManage($request, $project);
        return $this->success($this->projects->changeStatus($project, $request->validated('status'), $request->user()->tenant_id, $request->user()->id), 'Status updated');
    }

    public function progress(Request $request, int $project)
    {
        $this->guardView($request, $project);
        return $this->success($this->projects->progress($project, $request->user()->tenant_id), 'Progress computed');
    }

    public function members(SyncMembersRequest $request, int $project)
    {
        $this->guardManage($request, $project);
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

        $this->guardView($request, $project);
        $copy = $this->projects->copy($project, $opts, $request->user()->tenant_id, $request->user()->id);

        return $this->success($copy, 'Project copied', 201);
    }

    /** Pin/unpin for the current user only. */
    public function pin(Request $request, int $project)
    {
        $this->guardView($request, $project);
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

    /**
     * Vendors / third-party vendors for the "who is this project for?" picker.
     * `?type=vendor` (purchase-side) or `?type=tpv` (third-party), default vendor.
     */
    public function vendors(Request $request)
    {
        $type = $request->query('type') === 'tpv' ? 'tpv' : 'vendor';

        return $this->success($this->projects->listVendors($request->user()->tenant_id, $type), 'Vendors retrieved');
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

    /* ── Notes / Activity / Timesheets tabs ────────────────────── */

    public function notes(Request $request, int $project)
    {
        $this->guardView($request, $project);
        return $this->success($this->projects->listNotes($project, $request->user()->tenant_id), 'Notes retrieved');
    }

    public function storeNote(Request $request, int $project)
    {
        $this->guardView($request, $project);
        $data = $request->validate([
            'title'       => 'required|string|max:255',
            'content'     => 'nullable|string|max:20000',
            'assigned_to' => 'nullable|integer|exists:users,id',
            'remind_at'   => 'nullable|date',
        ]);
        $note = $this->projects->addNote($project, $data, $request->user()->tenant_id, $request->user()->id);

        return $this->success($note, 'Note added', 201);
    }

    public function updateNote(Request $request, int $project, int $note)
    {
        $this->guardView($request, $project);
        $data = $request->validate([
            'title'       => 'sometimes|required|string|max:255',
            'content'     => 'nullable|string|max:20000',
            'assigned_to' => 'nullable|integer|exists:users,id',
            'remind_at'   => 'nullable|date',
        ]);
        $note = $this->projects->updateNote($note, $project, $data, $request->user()->tenant_id);

        return $this->success($note, 'Note updated');
    }

    public function destroyNote(Request $request, int $project, int $note)
    {
        $this->guardView($request, $project);
        $this->projects->deleteNote($note, $project, $request->user()->tenant_id);

        return $this->success(null, 'Note deleted');
    }

    public function storeNoteAttachment(Request $request, int $project, int $note)
    {
        $this->guardView($request, $project);
        $request->validate(['file' => 'required|file|max:10240']);
        $att = $this->projects->addNoteAttachment($note, $project, $request->file('file'), $request->user()->tenant_id, $request->user()->id);

        return $this->success($att, 'Attachment added', 201);
    }

    public function downloadNoteAttachment(Request $request, int $project, int $attachment)
    {
        $this->guardView($request, $project);
        $att = $this->projects->noteAttachment($attachment, $project, $request->user()->tenant_id);

        return \Illuminate\Support\Facades\Storage::disk('local')->download($att->path, $att->original_name);
    }

    public function destroyNoteAttachment(Request $request, int $project, int $attachment)
    {
        $this->guardView($request, $project);
        $this->projects->deleteNoteAttachment($attachment, $project, $request->user()->tenant_id);

        return $this->success(null, 'Attachment removed');
    }

    /* ── Expenses tab ──────────────────────────────────────────── */

    /**
     * Project expenses for one vendor, across every project it is linked to.
     *
     * Deliberately NOT keyed by a project the caller names: the vendor id is
     * resolved to projects server-side, through the same visibility path as the
     * Projects tab, so this cannot reach a project the caller cannot open.
     */
    public function vendorExpenses(Request $request)
    {
        $vendorId = (int) $request->query('vendor_id');
        abort_if($vendorId <= 0, 422, 'A vendor is required.');

        return $this->success(
            $this->projects->listVendorExpenses(
                $request->user()->tenant_id, $vendorId, $request->user()->id, $this->isAdmin($request),
                $this->vendorType($request),
            ),
            'Expenses retrieved'
        );
    }

    /**
     * The party type a vendor filter refers to, validated against the fixed map.
     *
     * Defaults to tpv_vendor: every caller that predates the Purchase vendor
     * screen omits the parameter and means TPV. An unrecognised value is refused
     * outright rather than silently defaulting, so a typo cannot quietly return
     * the wrong module's projects.
     */
    private function vendorType(Request $request): string
    {
        $type = (string) ($request->query('vendor_type') ?: 'tpv_vendor');

        abort_unless(
            array_key_exists($type, \App\Models\Project\Project::VENDOR_LINK_MAP),
            422,
            'Unknown vendor type.',
        );

        return $type;
    }

    public function expenses(Request $request, int $project)
    {
        $this->guardView($request, $project);
        return $this->success($this->projects->listExpenses($project, $request->user()->tenant_id), 'Expenses retrieved');
    }

    public function storeExpense(Request $request, int $project)
    {
        $this->guardView($request, $project);
        $data = $request->validate([
            'title'        => 'required|string|max:255',
            'category'     => 'nullable|string|max:120',
            'amount'       => 'required|numeric|min:0',
            'expense_date' => 'required|date',
            'note'         => 'nullable|string|max:2000',
            'billable'     => 'nullable|boolean',
        ]);

        return $this->success($this->projects->addExpense($project, $data, $request->user()->tenant_id, $request->user()->id), 'Expense added', 201);
    }

    public function updateExpense(Request $request, int $project, int $expense)
    {
        $this->guardView($request, $project);
        $data = $request->validate([
            'title'        => 'sometimes|required|string|max:255',
            'category'     => 'nullable|string|max:120',
            'amount'       => 'sometimes|required|numeric|min:0',
            'expense_date' => 'sometimes|required|date',
            'note'         => 'nullable|string|max:2000',
            'billable'     => 'nullable|boolean',
        ]);

        return $this->success($this->projects->updateExpense($expense, $project, $data, $request->user()->tenant_id), 'Expense updated');
    }

    public function destroyExpense(Request $request, int $project, int $expense)
    {
        $this->guardView($request, $project);
        $this->projects->deleteExpense($expense, $project, $request->user()->tenant_id);

        return $this->success(null, 'Expense deleted');
    }

    public function activity(Request $request, int $project)
    {
        $this->guardView($request, $project);
        return $this->success($this->projects->listActivity($project, $request->user()->tenant_id), 'Activity retrieved');
    }

    public function timesheets(Request $request, int $project)
    {
        $this->guardView($request, $project);
        return $this->success($this->projects->timesheets($project, $request->user()->tenant_id), 'Timesheets retrieved');
    }

    /* ── Integration 3a: tickets linked to this project ────────── */
    public function tickets(Request $request, int $project)
    {
        $this->guardView($request, $project);
        $tenantId = $request->user()->tenant_id;
        $tickets = \App\Models\Helpdesk\Ticket::forTenant($tenantId)
            ->where('project_id', $project)
            ->with('assignee:id,name')
            ->latest()
            ->get();

        return $this->success($tickets, 'Project tickets retrieved');
    }
}
