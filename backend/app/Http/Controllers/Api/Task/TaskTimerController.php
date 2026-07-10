<?php

namespace App\Http\Controllers\Api\Task;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Services\Task\TaskService;
use Illuminate\Http\Request;

class TaskTimerController extends Controller
{
    use ApiResponse;

    public function __construct(private TaskService $tasks)
    {
    }

    public function start(Request $request, int $task)
    {
        $request->validate(['note' => 'nullable|string|max:500']);
        return $this->success($this->tasks->startTimer($task, $request->input('note'), $request->user()->tenant_id, $request->user()->id), 'Timer started', 201);
    }

    public function stop(Request $request, int $task)
    {
        return $this->success($this->tasks->stopTimer($task, $request->user()->tenant_id, $request->user()->id), 'Timer stopped');
    }

    public function total(Request $request, int $task)
    {
        return $this->success($this->tasks->totalTime($task, $request->user()->tenant_id), 'Total time computed');
    }
}
