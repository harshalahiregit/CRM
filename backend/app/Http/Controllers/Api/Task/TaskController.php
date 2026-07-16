<?php

namespace App\Http\Controllers\Api\Task;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Http\Requests\Task\BulkTaskActionRequest;
use App\Http\Requests\Task\StoreTaskRequest;
use App\Http\Requests\Task\SyncTaskUsersRequest;
use App\Http\Requests\Task\UpdateTaskRequest;
use App\Http\Requests\Task\UpdateTaskStatusRequest;
use App\Services\StatusService;
use App\Services\Task\TaskService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    use ApiResponse;

    public function __construct(private TaskService $tasks, private StatusService $statuses)
    {
    }

    /** Configured task-status keys for this tenant — used by inline validators. */
    private function statusKeys(Request $request): array
    {
        return $this->statuses->keys('task', $request->user()->tenant_id);
    }

    private function isAdmin(Request $request): bool
    {
        return $request->user()?->role === 'admin';
    }

    /** 403s a staff member who tries to reach a task they can't see. */
    private function guard(Request $request, int $task): void
    {
        $this->tasks->assertTaskVisible($task, $request->user()->tenant_id, $request->user()->id, $this->isAdmin($request));
    }

    public function index(Request $request)
    {
        $filters = $request->only(['rel_type', 'rel_id', 'status', 'priority', 'assignee', 'search', 'tag']);
        return $this->success(
            $this->tasks->list($request->user()->tenant_id, $filters, $request->user()->id, $this->isAdmin($request)),
            'Tasks retrieved'
        );
    }

    public function store(StoreTaskRequest $request)
    {
        $task = $this->tasks->create($request->validated(), $request->user()->tenant_id, $request->user()->id);
        return $this->success($task, 'Task created', 201);
    }

    public function show(Request $request, int $task)
    {
        $this->guard($request, $task);
        return $this->success($this->tasks->show($task, $request->user()->tenant_id), 'Task retrieved');
    }

    public function update(UpdateTaskRequest $request, int $task)
    {
        $this->guard($request, $task);
        return $this->success($this->tasks->update($task, $request->validated(), $request->user()->tenant_id), 'Task updated');
    }

    public function destroy(Request $request, int $task)
    {
        $this->guard($request, $task);
        $this->tasks->delete($task, $request->user()->tenant_id);
        return $this->success(null, 'Task deleted');
    }

    public function updateStatus(UpdateTaskStatusRequest $request, int $task)
    {
        $this->guard($request, $task);
        return $this->success($this->tasks->changeStatus($task, $request->validated('status'), $request->user()->tenant_id, $request->user()->id), 'Status updated');
    }

    public function assignees(SyncTaskUsersRequest $request, int $task)
    {
        $this->guard($request, $task);
        return $this->success($this->tasks->syncAssignees($task, $request->validated('user_ids'), $request->user()->tenant_id, $request->user()->id), 'Assignees updated');
    }

    public function followers(SyncTaskUsersRequest $request, int $task)
    {
        $this->guard($request, $task);
        return $this->success($this->tasks->syncFollowers($task, $request->validated('user_ids'), $request->user()->tenant_id, $request->user()->id), 'Followers updated');
    }

    /** Counts for the list KPI cards. */
    public function stats(Request $request)
    {
        return $this->success($this->tasks->stats($request->user()->tenant_id, $request->user()->id, $this->isAdmin($request)), 'Stats retrieved');
    }

    /** Apply one action (delete/status/priority/assign) to many tasks at once. */
    public function bulk(BulkTaskActionRequest $request)
    {
        $count = $this->tasks->bulkAction($request->validated(), $request->user()->tenant_id, $request->user()->id, $this->isAdmin($request));

        return $this->success(['affected' => $count], "Applied to {$count} ".($count === 1 ? 'task' : 'tasks'));
    }

    /** Clone a task. Assignees/followers/checklist are opt-in; history never copies. */
    public function copy(Request $request, int $task)
    {
        $opts = $request->validate([
            'name'           => 'nullable|string|max:255',
            'status'         => ['nullable', Rule::in($this->statusKeys($request))],
            'start_date'     => 'nullable|date',
            'due_date'       => 'nullable|date',
            'copy_checklist' => 'nullable|boolean',
            'copy_assignees' => 'nullable|boolean',
            'copy_followers' => 'nullable|boolean',
        ]);

        $this->guard($request, $task);
        $copy = $this->tasks->copy($task, $opts, $request->user()->tenant_id, $request->user()->id);

        return $this->success($copy, 'Task copied', 201);
    }

    /** Persist a kanban column order after a drag (also applies cross-column moves). */
    public function reorder(Request $request)
    {
        $data = $request->validate([
            'status'        => ['required', Rule::in($this->statusKeys($request))],
            'ordered_ids'   => 'present|array|max:500',
            'ordered_ids.*' => 'integer|min:1',
        ]);

        $count = $this->tasks->reorder($data['ordered_ids'], $data['status'], $request->user()->tenant_id, $request->user()->id, $this->isAdmin($request));

        return $this->success(['reordered' => $count], 'Board updated');
    }

    /** Billable, unbilled tasks — optionally filtered by rel_id / customer_id. */
    public function billable(Request $request)
    {
        return $this->success($this->tasks->billable($request->user()->tenant_id, $request->only(['rel_id', 'customer_id'])), 'Billable tasks retrieved');
    }
}
