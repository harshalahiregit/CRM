<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Models\Shared\PartyAssignee;
use App\Models\Task\Task;
use App\Services\Shared\PartyAssignmentService;
use App\Services\Task\TaskTreeService;
use Illuminate\Http\Request;

/**
 * "What has been assigned to me" — for somebody who is not staff.
 *
 * One endpoint for all three portals rather than one per portal, because the
 * rule is the same in all three and it is the rule that must not drift: you see
 * the tasks assigned to you, and the work nested underneath them. Nothing else.
 *
 * Deliberately NOT under role: middleware. The three callers authenticate as
 * three different things — a ClientContact, a PurchaseVendor, a User with the
 * third_party_vendor role — and a role list can only name the last of those. The
 * gate here is identity instead: PartyAssignmentService::identify() returns null for
 * anyone who is not an external party, and null is a 403. Staff have their own
 * task module and are turned away from this one, so there is no second path into
 * task data with a different set of rules behind it.
 */
class PortalAssignedTaskController extends Controller
{
    use ApiResponse;

    public function __construct(
        private PartyAssignmentService $parties,
        private TaskTreeService $tree,
    ) {
    }

    /** The list. Assigned to me, plus everything under it. */
    public function index(Request $request)
    {
        [$identity, $tenantId] = $this->identify($request);

        $query = Task::forTenant($tenantId)->with('creator:id,name');
        $tasks = $this->parties
            ->scopeForParty($query, $identity, $tenantId)
            ->orderByRaw('due_date IS NULL')      // undated work sinks to the bottom
            ->orderBy('due_date')
            ->orderByDesc('id')
            ->limit(500)
            ->get();

        $mine = $this->parties->assignedTaskIds($identity, $tenantId);
        $chips = $this->parties->forSubjects(PartyAssignee::SUBJECT_TASK, $tasks->pluck('id')->all());

        return $this->success($tasks->map(fn (Task $t) => [
            'id'          => (int) $t->id,
            'name'        => (string) $t->name,
            'description' => (string) ($t->description ?? ''),
            'status'      => (string) $t->status,
            'priority'    => (string) $t->priority,
            'due_date'    => optional($t->due_date)->toDateString(),
            'depth'       => (int) $t->depth,
            'parent_id'   => $t->parent_id ? (int) $t->parent_id : null,
            // Whether this row is the work they were handed, or a piece of it.
            // Without the distinction a subtask reads as a second assignment.
            'assigned_to_me' => in_array((int) $t->id, $mine, true),
            'progress'    => $this->tree->progressForTask($t, $tenantId),
            'people'      => $chips[$t->id] ?? [],
        ])->values(), 'Assigned tasks retrieved');
    }

    /** One task, with its tree — refused unless it is in the set above. */
    public function show(Request $request, int $task)
    {
        [$identity, $tenantId] = $this->identify($request);

        $model = Task::forTenant($tenantId)->find($task);

        // 404 rather than 403 for a task that exists but is not theirs: a 403
        // confirms the id is real, which is how an id range gets walked.
        if (! $model || ! $this->parties->canSee($model, $identity, $tenantId)) {
            return $this->error('That task was not found.', 404);
        }

        return $this->success([
            'id'          => (int) $model->id,
            'name'        => (string) $model->name,
            'description' => (string) ($model->description ?? ''),
            'status'      => (string) $model->status,
            'priority'    => (string) $model->priority,
            'due_date'    => optional($model->due_date)->toDateString(),
            'progress'    => $this->tree->progressForTask($model, $tenantId),
            'checklist'   => $model->checklistItems()->get(['id', 'description', 'finished'])
                ->map(fn ($i) => [
                    'id' => (int) $i->id, 'description' => (string) $i->description,
                    'finished' => (bool) $i->finished,
                ])->all(),
            'subtasks'    => $this->tree->tree($model->id, $tenantId),
            'people'      => $this->parties->forSubject(PartyAssignee::SUBJECT_TASK, $model->id),
        ], 'Task retrieved');
    }

    /**
     * Resolve the caller to a party, or refuse.
     *
     * @return array{0:array,1:int}
     */
    private function identify(Request $request): array
    {
        $caller = $request->user();
        $identity = $this->parties->identify($caller);

        abort_if($identity === null, 403, 'This area is for assigned client, vendor and third-party contacts.');

        return [$identity, (int) $caller->tenant_id];
    }
}
