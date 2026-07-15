<?php

namespace App\Http\Controllers\Api\Task;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Services\Task\TaskService;
use Illuminate\Http\Request;

class TaskReminderController extends Controller
{
    use ApiResponse;

    public function __construct(private TaskService $tasks)
    {
    }

    public function index(Request $request, int $task)
    {
        return $this->success($this->tasks->listReminders($task, $request->user()->tenant_id), 'Reminders retrieved');
    }

    public function store(Request $request, int $task)
    {
        $data = $request->validate([
            'user_id'     => 'nullable|integer|exists:users,id',   // defaults to self
            'description' => 'nullable|string|max:255',
            'remind_at'   => 'required|date|after:now',
        ]);

        $reminder = $this->tasks->addReminder($task, $data, $request->user()->tenant_id, $request->user()->id);

        return $this->success($reminder, 'Reminder set', 201);
    }

    public function destroy(Request $request, int $task, int $reminder)
    {
        $this->tasks->deleteReminder($reminder, $task, $request->user()->tenant_id);

        return $this->success(null, 'Reminder removed');
    }
}
