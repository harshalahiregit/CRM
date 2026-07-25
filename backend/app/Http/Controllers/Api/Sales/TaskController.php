<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreTaskRequest;
use App\Http\Requests\Sales\UpdateTaskRequest;
use App\Models\Sales\Task;
use App\Models\Sales\TaskChecklistItem;
use App\Models\Sales\TaskComment;
use App\Services\Sales\TaskService;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    public function __construct(private TaskService $taskService)
    {
    }

    public function index(Request $request)
    {
        $tasks = $this->taskService->list($request->user()->tenant_id, $request->only([
            'status_id', 'priority', 'assignee', 'taskable_type', 'taskable_id', 'search', 'sort', 'order',
        ]));
        return response()->json($tasks);
    }

    public function kanban(Request $request)
    {
        return response()->json($this->taskService->kanban($request->user()->tenant_id));
    }

    public function store(StoreTaskRequest $request)
    {
        $task = $this->taskService->create($request->validated(), $request->user()->tenant_id, $request->user()->id);
        return response()->json($task, 201);
    }

    public function show(Task $task, Request $request)
    {
        return response()->json($this->taskService->show($task, $request->user()->tenant_id));
    }

    public function update(UpdateTaskRequest $request, Task $task)
    {
        return response()->json($this->taskService->update($task, $request->validated(), $request->user()->tenant_id));
    }

    public function destroy(Task $task, Request $request)
    {
        $this->taskService->delete($task, $request->user()->tenant_id);
        return response()->json(['message' => 'Task deleted']);
    }

    public function updateStatus(Request $request, Task $task)
    {
        $data = $request->validate(['status_id' => 'required|exists:task_statuses,id']);
        return response()->json($this->taskService->updateStatus($task, (int) $data['status_id'], $request->user()->tenant_id, $request->user()->id));
    }

    public function reorder(Request $request)
    {
        $data = $request->validate([
            'order'                 => 'required|array',
            'order.*.id'            => 'required|integer',
            'order.*.status_id'     => 'nullable|integer',
            'order.*.kanban_order'  => 'nullable|integer',
        ]);
        $this->taskService->reorder($data['order'], $request->user()->tenant_id);
        return response()->json(['message' => 'Reordered']);
    }

    public function assign(Request $request, Task $task)
    {
        $data = $request->validate([
            'assignees'   => 'present|array',
            'assignees.*' => 'integer|exists:users,id',
        ]);
        return response()->json($this->taskService->assign($task, $data['assignees'], $request->user()->tenant_id));
    }

    public function updateProgress(Request $request, Task $task)
    {
        $data = $request->validate(['progress' => 'required|integer|min:0|max:100']);
        return response()->json($this->taskService->updateProgress($task, (int) $data['progress'], $request->user()->tenant_id));
    }

    /* ── Checklist ──────────────────────────────────────────────── */
    public function addChecklistItem(Request $request, Task $task)
    {
        $data = $request->validate(['description' => 'required|string|max:500']);
        return response()->json($this->taskService->addChecklistItem($task, $data['description'], $request->user()->tenant_id), 201);
    }

    public function toggleChecklistItem(Request $request, TaskChecklistItem $item)
    {
        return response()->json($this->taskService->toggleChecklistItem($item, $request->user()->tenant_id, $request->user()->id));
    }

    public function deleteChecklistItem(Request $request, TaskChecklistItem $item)
    {
        $this->taskService->deleteChecklistItem($item, $request->user()->tenant_id);
        return response()->json(['message' => 'Deleted']);
    }

    /* ── Comments ───────────────────────────────────────────────── */
    public function addComment(Request $request, Task $task)
    {
        $data = $request->validate(['content' => 'required|string']);
        return response()->json($this->taskService->addComment($task, $data['content'], $request->user()->tenant_id, $request->user()->id), 201);
    }

    public function deleteComment(Request $request, TaskComment $comment)
    {
        $this->taskService->deleteComment($comment, $request->user()->tenant_id);
        return response()->json(['message' => 'Deleted']);
    }
}
