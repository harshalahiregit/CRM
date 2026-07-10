<?php

namespace App\Http\Requests\Helpdesk;

use Illuminate\Foundation\Http\FormRequest;

class StoreTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'subject'     => 'required|string|max:255',
            'description' => 'nullable|string',
            'status'      => 'nullable|in:open,in-progress,closed',
            'priority'    => 'nullable|in:low,medium,high,urgent',
            'assigned_to' => 'nullable|integer|exists:users,id',
            'customer_id' => 'nullable|integer|min:1',
            'due_date'    => 'nullable|date',
        ];
    }
}
