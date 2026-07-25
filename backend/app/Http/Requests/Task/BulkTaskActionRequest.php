<?php

namespace App\Http\Requests\Task;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One action applied to many tasks. `value` is deliberately untyped here — its
 * meaning depends on the action (a status key, a priority, or a user id) — so
 * TaskService::bulkAction validates it per action, once, before touching a row.
 */
class BulkTaskActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action'     => 'required|in:delete,status,priority,assign',
            'task_ids'   => 'required|array|min:1|max:500',
            'task_ids.*' => 'integer|min:1',
            'value'      => 'nullable|required_unless:action,delete',
        ];
    }
}
