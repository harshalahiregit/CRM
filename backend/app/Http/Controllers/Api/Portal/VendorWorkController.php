<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Models\Helpdesk\Ticket;
use App\Models\Project\Project;
use App\Models\Task\Task;
use Illuminate\Http\Request;

/**
 * A vendor / third-party-vendor's own work: the projects raised for them, the
 * tasks assigned to them, and the tickets assigned to them.
 *
 * Deliberately NOT behind the `vendor.portal` middleware — that requires a
 * vendor-master profile row, whereas "my assigned work" is about the logged-in
 * User (roles vendor / third_party_vendor). Everything is filtered to
 * `$request->user()` and the caller's tenant; no vendor id is ever named in a
 * URL, so there is nothing to forge and no cross-vendor access.
 */
class VendorWorkController extends Controller
{
    use ApiResponse;

    /**
     * The caller's vendor-master record id, resolved by their login user OR their
     * email. A TPV vendor whose portal `user_id` was never captured (login-less)
     * still owns work linked to the vendor RECORD (`projects.vendor_id`); without
     * this fallback that work is silently invisible on the portal.
     */
    protected function ownVendorId(Request $request): ?int
    {
        $user = $request->user();

        return \App\Models\Vendor\Vendor::query()
            ->where('tenant_id', $user->tenant_id)
            ->where(fn ($q) => $q->where('user_id', $user->id)
                ->when($user->email, fn ($w) => $w->orWhere('email', $user->email)))
            ->value('id');
    }

    /**
     * Projects reach a vendor two ways: their login User is the `vendor_user_id`,
     * or the project is linked to the vendor RECORD (`vendor_id` + a tpv link_type).
     * The second path covers login-less vendors.
     */
    protected function scopeVendorProjects($query, Request $request)
    {
        $uid = $request->user()->id;
        $vendorId = $this->ownVendorId($request);

        return $query->where(function ($q) use ($uid, $vendorId) {
            $q->where('vendor_user_id', $uid);
            if ($vendorId) {
                $q->orWhere(fn ($r) => $r->whereIn('link_type', ['tpv', 'tpv_vendor'])->where('vendor_id', $vendorId));
            }
        });
    }

    /** Headline counts for the portal dashboard cards. */
    public function summary(Request $request)
    {
        $user = $request->user();
        $tid = $user->tenant_id;
        $uid = $user->id;

        $tasks = Task::forTenant($tid)->whereHas('assignees', fn ($q) => $q->where('user_id', $uid));

        return $this->success([
            'projects'   => $this->scopeVendorProjects(Project::where('tenant_id', $tid), $request)->count(),
            'tasks'      => (clone $tasks)->count(),
            'open_tasks' => (clone $tasks)->whereNotIn('status', ['complete', 'finished', 'cancelled'])->count(),
            'tickets'    => Ticket::forTenant($tid)->where('assigned_to', $uid)->count(),
        ], 'Work summary retrieved');
    }

    /** Projects raised for this vendor / TPV. */
    public function projects(Request $request)
    {
        $user = $request->user();

        $rows = $this->scopeVendorProjects(Project::where('tenant_id', $user->tenant_id), $request)
            ->orderByDesc('id')
            ->get(['id', 'name', 'status', 'progress', 'link_type', 'deadline', 'created_at'])
            ->map(fn (Project $p) => [
                'id'       => $p->id,
                'name'     => $p->name,
                'status'   => $p->status,
                'progress' => (int) $p->progress,
                'role'     => $p->link_type === 'tpv' ? 'Third-party vendor' : 'Vendor',
                'deadline' => optional($p->deadline)->toDateString(),
            ]);

        return $this->success($rows, 'Projects retrieved');
    }

    /** Tasks assigned to this vendor / TPV, newest first. */
    public function tasks(Request $request)
    {
        $user = $request->user();

        // Two ways a task reaches a TPV: the vendor's User is an assignee, or the
        // task is linked to the vendor RECORD via rel_type='tpv_vendor'. Before the
        // rel link existed only the first was possible, so a task raised against the
        // vendor as an organisation never appeared here.
        $vendorId = \App\Models\Vendor\Vendor::query()
            ->where('tenant_id', $user->tenant_id)
            ->where(fn ($q) => $q->where('user_id', $user->id)
                ->when($user->email, fn ($w) => $w->orWhere('email', $user->email)))
            ->value('id');

        $tasks = Task::forTenant($user->tenant_id)
            ->where(function ($q) use ($user, $vendorId) {
                $q->whereHas('assignees', fn ($a) => $a->where('user_id', $user->id));
                if ($vendorId) {
                    $q->orWhere(fn ($r) => $r->where('rel_type', 'tpv_vendor')->where('rel_id', $vendorId));
                }
            })
            ->orderByDesc('id')
            ->get(['id', 'name', 'status', 'priority', 'due_date', 'rel_type', 'rel_id']);

        // Resolve the parent project name for project-linked tasks in one query.
        $projectIds = $tasks->where('rel_type', 'project')->pluck('rel_id')->filter()->unique();
        $projectNames = $projectIds->isEmpty()
            ? collect()
            : Project::where('tenant_id', $user->tenant_id)->whereIn('id', $projectIds)->pluck('name', 'id');

        $rows = $tasks->map(fn (Task $t) => [
            'id'       => $t->id,
            'name'     => $t->name,
            'status'   => $t->status,
            'priority' => $t->priority,
            'due_date' => optional($t->due_date)->toDateString(),
            'project'  => $t->rel_type === 'project' ? ($projectNames[$t->rel_id] ?? null) : null,
        ]);

        return $this->success($rows, 'Tasks retrieved');
    }

    /** Tickets assigned to this vendor / TPV. */
    public function tickets(Request $request)
    {
        $user = $request->user();

        $rows = Ticket::forTenant($user->tenant_id)
            ->where('assigned_to', $user->id)
            ->orderByDesc('id')
            ->get(['id', 'subject', 'status', 'priority', 'created_at'])
            ->map(fn (Ticket $t) => [
                'id'       => $t->id,
                'subject'  => $t->subject,
                'status'   => $t->status,
                'priority' => $t->priority,
            ]);

        return $this->success($rows, 'Tickets retrieved');
    }
}
