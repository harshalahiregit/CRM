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

        // Notify the assignee (in-app + email) — a checklist item had no alert before.
        if ($created->assigned_to) {
            $taskModel = $this->tasks->find($task, $tenantId);
            $this->notifier->checklistAssigned($taskModel, $created->description, (int) $created->assigned_to, $request->user()->id);
        }

        return $this->success($created, 'Item added', 201);
    }

    /** Edit an item's text and/or reassign it to a person. */
    public function update(Request $request, int $item)
    {
        $data = $request->validate([
            'description' => 'sometimes|required|string|max:500',
            'assigned_to' => 'nullable|integer|exists:users,id',
        ]);
        $tenantId = $request->user()->tenant_id;
        $updated = $this->tasks->updateChecklistItem($item, $data, $tenantId);

        // A (re)assignment carries the assigned_to key with a real user — tell them.
        if (array_key_exists('assigned_to', $data) && $updated->assigned_to) {
            $taskModel = $this->tasks->find($updated->task_id, $tenantId);
            $this->notifier->checklistAssigned($taskModel, $updated->description, (int) $updated->assigned_to, $request->user()->id);
        }

        return $this->success($updated, 'Item updated');
    }

    public function toggle(Request $request, int $item)
    {
        return $this->success($this->tasks->toggleChecklistItem($item, $request->user()->tenant_id, $request->user()->id), 'Item toggled');
    }
}
