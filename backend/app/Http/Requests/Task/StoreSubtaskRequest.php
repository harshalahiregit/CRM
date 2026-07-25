<?php

namespace App\Http\Requests\Task;

use App\Services\StatusService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating a subtask.
 *
 * Deliberately the same field set as StoreTaskRequest minus the recurrence and
 * project-link fields — a subtask is a full task, so it accepts its own
 * deadline, its own assignees and its own status. The one thing it does NOT
 * accept is anything that would let it reach back up and change its parent.
 *
 * `start_date` is optional here (unlike a top-level task): people add subtasks
 * in a burst while breaking work down, and demanding a start date for each one
 * turns a quick list into a form-filling exercise. It defaults to today.
 */
class StoreSubtaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('start_date')) {
            $this->merge(['start_date' => now()->toDateString()]);
        }
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;
        $statusKeys = app(StatusService::class)->keys('task', $tenantId);

        return [
            'name'           => 'required|string|max:255',
            'description'    => 'nullable|string',
            'priority'       => 'nullable|in:low,medium,high,urgent',
            'status'         => ['nullable', Rule::in($statusKeys)],
            'start_date'     => 'required|date',
            'due_date'       => 'nullable|date|after_or_equal:start_date',
            // Its own people, not the parent's. Assigning here never touches the
            // parent's assignees — that independence is the whole point.
            'assignee_ids'   => 'nullable|array|max:20',
            'assignee_ids.*' => 'integer|exists:users,id',
            'follower_ids'   => 'nullable|array|max:20',
            'follower_ids.*' => 'integer|exists:users,id',
            'tags'           => 'nullable|array|max:15',
            'tags.*'         => 'string|max:60',
        ];
    }
}
