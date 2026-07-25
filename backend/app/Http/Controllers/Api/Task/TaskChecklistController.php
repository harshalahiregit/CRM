<?php

namespace App\Http\Controllers\Api\Task;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Http\Requests\Task\StoreChecklistItemRequest;
use App\Services\Task\TaskService;
use Illuminate\Http\Request;

class TaskChecklistController extends Controller
{
    use ApiResponse;
    use GuardsTaskAccess;

    public function __construct(private TaskService $tasks)
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
        return $this->success($this->tasks->addChecklistItem($task, $request->validated('description'), $request->user()->tenant_id), 'Item added', 201);
    }

    public function toggle(Request $request, int $item)
    {
        return $this->success($this->tasks->toggleChecklistItem($item, $request->user()->tenant_id, $request->user()->id), 'Item toggled');
    }
}
