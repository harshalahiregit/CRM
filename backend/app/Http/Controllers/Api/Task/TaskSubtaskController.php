<?php

namespace App\Http\Controllers\Api\Task;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Http\Requests\Task\StoreSubtaskRequest;
use App\Services\Task\TaskService;
use Illuminate\Http\Request;

/**
 * The subtask tree under a task.
 *
 * Every route funnels through guardTask() first, so a staff member cannot walk
 * ids into a tree they can't reach — and because visibility resolves through the
 * ROOT task (TaskService::canSeeTask), reaching the top of a tree reaches all of
 * it, which is what stops the progress bar counting work you can't see.
 */
class TaskSubtaskController extends Controller
{
    use ApiResponse;
    use GuardsTaskAccess;

    public function __construct(private TaskService $tasks)
    {
    }

    /** The whole nested tree under this task, each node with its own progress. */
    public function tree(Request $request, int $task)
    {
        $this->guardTask($request, $task);

        return $this->success([
            'progress' => $this->tasks->progressFor($task, $request->user()->tenant_id),
            'children' => $this->tasks->subtree($task, $request->user()->tenant_id),
        ], 'Subtasks retrieved');
    }

    /** Add a child under this task. */
    public function store(StoreSubtaskRequest $request, int $task)
    {
        $this->guardTask($request, $task);

        return $this->success(
            $this->tasks->addSubtask($task, $request->validated(), $request->user()->tenant_id, $request->user()->id),
            'Subtask added',
            201
        );
    }

    /**
     * Move a task somewhere else in the tree — under a different parent, or out
     * to the top level with parent_id = null.
     */
    public function move(Request $request, int $task)
    {
        $this->guardTask($request, $task);

        $data = $request->validate([
            'parent_id' => 'nullable|integer|min:1',
        ]);

        // You must be able to reach the destination too, or moving a task would
        // become a way to push work into a tree you can't see.
        if (! empty($data['parent_id'])) {
            $this->guardTask($request, (int) $data['parent_id']);
        }

        return $this->success(
            $this->tasks->moveSubtask($task, $data['parent_id'] ?? null, $request->user()->tenant_id),
            'Subtask moved'
        );
    }
}
