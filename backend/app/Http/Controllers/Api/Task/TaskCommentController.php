<?php

namespace App\Http\Controllers\Api\Task;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Http\Requests\Task\StoreCommentRequest;
use App\Services\Task\TaskService;
use Illuminate\Http\Request;

class TaskCommentController extends Controller
{
    use ApiResponse;

    public function __construct(private TaskService $tasks)
    {
    }

    public function index(Request $request, int $task)
    {
        return $this->success($this->tasks->listComments($task, $request->user()->tenant_id), 'Comments retrieved');
    }

    public function store(StoreCommentRequest $request, int $task)
    {
        return $this->success($this->tasks->addComment($task, $request->validated('content'), $request->user()->tenant_id, $request->user()->id), 'Comment added', 201);
    }
}
