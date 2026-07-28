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

    /** Headline counts for the portal dashboard cards. */
    public function summary(Request $request)
    {
        $user = $request->user();
        $tid = $user->tenant_id;
        $uid = $user->id;

        $tasks = Task::forTenant($tid)->whereHas('assignees', fn ($q) => $q->where('user_id', $uid));

        return $this->success([
            'projects'   => Project::where('tenant_id', $tid)->where('vendor_user_id', $uid)->count(),
            'tasks'      => (clone $tasks)->count(),
            'open_tasks' => (clone $tasks)->whereNotIn('status', ['complete', 'finished', 'cancelled'])->count(),
            'tickets'    => Ticket::forTenant($tid)->where('assigned_to', $uid)->count(),
        ], 'Work summary retrieved');
    }

    /** Projects raised for this vendor / TPV. */
    public function projects(Request $request)
    {
        $user = $request->user();

        $rows = Project::where('tenant_id', $user->tenant_id)
            ->where('vendor_user_id', $user->id)
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

        $tasks = Task::forTenant($user->tenant_id)
            ->whereHas('assignees', fn ($q) => $q->where('user_id', $user->id))
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
