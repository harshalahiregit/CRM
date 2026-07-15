<?php

namespace App\Http\Controllers\Api\Task;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Http\Requests\Task\StoreTaskRequest;
use App\Http\Requests\Task\SyncTaskUsersRequest;
use App\Http\Requests\Task\UpdateTaskRequest;
use App\Http\Requests\Task\UpdateTaskStatusRequest;
use App\Services\Task\TaskService;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    use ApiResponse;

    public function __construct(private TaskService $tasks)
    {
    }

    public function index(Request $request)
    {
        $filters = $request->only(['rel_type', 'rel_id', 'status', 'priority', 'assignee', 'search']);
        return $this->success($this->tasks->list($request->user()->tenant_id, $filters), 'Tasks retrieved');
    }

    public function store(StoreTaskRequest $request)
    {
        $task = $this->tasks->create($request->validated(), $request->user()->tenant_id, $request->user()->id);
        return $this->success($task, 'Task created', 201);
    }

    public function show(Request $request, int $task)
    {
        return $this->success($this->tasks->show($task, $request->user()->tenant_id), 'Task retrieved');
    }

    public function update(UpdateTaskRequest $request, int $task)
    {
        return $this->success($this->tasks->update($task, $request->validated(), $request->user()->tenant_id), 'Task updated');
    }

    public function destroy(Request $request, int $task)
    {
        $this->tasks->delete($task, $request->user()->tenant_id);
        return $this->success(null, 'Task deleted');
    }

    public function updateStatus(UpdateTaskStatusRequest $request, int $task)
    {
        return $this->success($this->tasks->changeStatus($task, $request->validated('status'), $request->user()->tenant_id, $request->user()->id), 'Status updated');
    }

    public function assignees(SyncTaskUsersRequest $request, int $task)
    {
        return $this->success($this->tasks->syncAssignees($task, $request->validated('user_ids'), $request->user()->tenant_id, $request->user()->id), 'Assignees updated');
    }

    public function followers(SyncTaskUsersRequest $request, int $task)
    {
        return $this->success($this->tasks->syncFollowers($task, $request->validated('user_ids'), $request->user()->tenant_id, $request->user()->id), 'Followers updated');
    }

    /** Clone a task. Assignees/followers/checklist are opt-in; history never copies. */
    public function copy(Request $request, int $task)
    {
        $opts = $request->validate([
            'name'           => 'nullable|string|max:255',
            'status'         => 'nullable|in:not_started,in_progress,awaiting_feedback,testing,complete',
            'start_date'     => 'nullable|date',
            'due_date'       => 'nullable|date',
            'copy_checklist' => 'nullable|boolean',
            'copy_assignees' => 'nullable|boolean',
            'copy_followers' => 'nullable|boolean',
        ]);

        $copy = $this->tasks->copy($task, $opts, $request->user()->tenant_id, $request->user()->id);

        return $this->success($copy, 'Task copied', 201);
    }

    /** Persist a kanban column order after a drag (also applies cross-column moves). */
    public function reorder(Request $request)
    {
        $data = $request->validate([
            'status'        => 'required|in:not_started,in_progress,awaiting_feedback,testing,complete',
            'ordered_ids'   => 'present|array|max:500',
            'ordered_ids.*' => 'integer|min:1',
        ]);

        $count = $this->tasks->reorder($data['ordered_ids'], $data['status'], $request->user()->tenant_id, $request->user()->id);

        return $this->success(['reordered' => $count], 'Board updated');
    }

    /** Billable, unbilled tasks — optionally filtered by rel_id / customer_id. */
    public function billable(Request $request)
    {
        return $this->success($this->tasks->billable($request->user()->tenant_id, $request->only(['rel_id', 'customer_id'])), 'Billable tasks retrieved');
    }
}
