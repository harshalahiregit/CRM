<?php

namespace App\Http\Controllers\Api\Task;

use Illuminate\Http\Request;

/**
 * Shared task access guard for the sub-resource controllers (files, comments,
 * checklist, timers, reminders). Each of them holds a TaskService as $this->tasks;
 * this funnels every sub-resource call through the same visibility check so a
 * staff member can't reach a task's children by walking ids.
 */
trait GuardsTaskAccess
{
    protected function guardTask(Request $request, int $task): void
    {
        $this->tasks->assertTaskVisible(
            $task,
            $request->user()->tenant_id,
            $request->user()->id,
            $request->user()?->role === 'admin',
        );
    }
}
