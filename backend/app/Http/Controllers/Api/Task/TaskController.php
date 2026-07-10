<?php

namespace App\Http\Controllers\Api\Task;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Http\Requests\Task\StoreTaskRequest;
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
        return $this->success($this->tasks->changeStatus($task, $request->validated('status'), $request->user()->tenant_id), 'Status updated');
    }
}
