<?php

namespace App\Http\Controllers\Api\Task;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Http\Requests\Task\StoreChecklistItemRequest;
use App\Services\Task\TaskNotifier;
use App\Services\Task\TaskService;
use Illuminate\Http\Request;

class TaskChecklistController extends Controller
{
    use ApiResponse;
    use GuardsTaskAccess;

    public function __construct(private TaskService $tasks, private TaskNotifier $notifier)
    {
    }

    public function index(Request $request, int $task)
    {
        $this->guardTask($request, $task);
        return $this->success($this->tasks->listChecklist($task, $request->user()->tenant_id), 'Checklist retrieved');
    }

    public function store(StoreChecklistItemRequest $request, int $task)
    {
        $this->guardTask($request, $task);
        $tenantId = $request->user()->tenant_id;

        $created = $this->tasks->addChecklistItem(
            $task,
            $request->validated('description'),
            $tenantId,
            $request->validated('assigned_to'),
        );

        // Notify everyone on it (in-app + email) — a checklist item had no alert
        // at all before, and once a line can be shared, telling only the first
        // person is worse than telling nobody.
        $this->notifyAssignees($created, $task, $tenantId, $request->user()->id);

        return $this->success($created, 'Item added', 201);
    }

    /** Edit an item's text and/or reassign it to a person. */
    public function update(Request $request, int $item)
    {
        $data = $request->validate([
            'description'   => 'sometimes|required|string|max:500',
            'assigned_to'   => 'nullable',
            'assigned_to.*' => 'integer|exists:users,id',
        ]);
        $tenantId = $request->user()->tenant_id;

        // Who was already on it, so only people NEWLY added are told. Without
        // this, editing the text of a shared line mails everyone on it again.
        $before = \App\Models\Task\TaskChecklistItem::forTenant($tenantId)->find($item)
            ?->assignees()->pluck('user_id')->map(fn ($i) => (int) $i)->all() ?? [];

        $updated = $this->tasks->updateChecklistItem($item, $data, $tenantId);

        if (array_key_exists('assigned_to', $data)) {
            $this->notifyAssignees($updated, $updated->task_id, $tenantId, $request->user()->id, $before);
        }

        return $this->success($updated, 'Item updated');
    }

    /**
     * Tell the people newly put on a checklist line.
     *
     * @param  int[]  $alreadyTold  user ids that were on it before this change
     */
    private function notifyAssignees($item, int $taskId, int $tenantId, int $actorId, array $alreadyTold = []): void
    {
        $ids = $item->assignees->pluck('user_id')->map(fn ($i) => (int) $i)->all();
        $fresh = array_values(array_diff($ids, $alreadyTold, [$actorId]));

        if (! $fresh) {
            return;
        }

        $taskModel = $this->tasks->find($taskId, $tenantId);
        foreach ($fresh as $uid) {
            $this->notifier->checklistAssigned($taskModel, $item->description, $uid, $actorId);
        }
    }

    public function toggle(Request $request, int $item)
    {
        return $this->success($this->tasks->toggleChecklistItem($item, $request->user()->tenant_id, $request->user()->id), 'Item toggled');
    }
}
