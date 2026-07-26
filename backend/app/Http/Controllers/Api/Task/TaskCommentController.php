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
    use GuardsTaskAccess;

    public function __construct(private TaskService $tasks)
    {
    }

    public function index(Request $request, int $task)
    {
        $this->guardTask($request, $task);
        return $this->success($this->tasks->listComments($task, $request->user()->tenant_id), 'Comments retrieved');
    }

    public function store(StoreCommentRequest $request, int $task)
    {
        $this->guardTask($request, $task);
        $comment = $this->tasks->addComment(
            $task,
            (string) $request->input('content', ''),
            $request->user()->tenant_id,
            $request->user()->id,
            $request->file('files', []),
        );

        return $this->success($comment, 'Comment added', 201);
    }
}
