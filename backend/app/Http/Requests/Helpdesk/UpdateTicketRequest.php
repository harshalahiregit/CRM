<?php

namespace App\Http\Requests\Helpdesk;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'subject'     => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'status'      => 'sometimes|in:open,in-progress,closed',
            'priority'    => 'sometimes|in:low,medium,high,urgent',
            'assigned_to' => 'nullable|integer|exists:users,id',
            'customer_id' => 'nullable|integer|min:1',
            'due_date'    => 'nullable|date',
        ];
    }
}
