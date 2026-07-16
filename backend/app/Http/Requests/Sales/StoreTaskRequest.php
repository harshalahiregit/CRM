<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class StoreTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'            => 'required|string|max:255',
            'description'     => 'nullable|string',
            'priority'        => 'nullable|in:low,medium,high,urgent',
            'taskable_type'   => 'nullable|in:lead,proposal,estimate,invoice,credit_note,contract,customer',
            'taskable_id'     => 'nullable|integer',
            'status_id'       => 'nullable|exists:task_statuses,id',
            'start_date'      => 'nullable|date',
            'due_date'        => 'nullable|date',
            'progress'        => 'nullable|integer|min:0|max:100',
            'is_billable'     => 'nullable|boolean',
            'billable_amount' => 'nullable|numeric|min:0',
            'is_recurring'    => 'nullable|boolean',
            'recur_every'     => 'nullable|integer|min:1',
            'recur_type'      => 'nullable|in:day,week,month,year',
            'recur_cycles'    => 'nullable|integer|min:0',
            'assignees'       => 'nullable|array',
            'assignees.*'     => 'integer|exists:users,id',
        ];
    }
}
