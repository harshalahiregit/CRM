<?php

namespace App\Http\Requests\Task;

use Illuminate\Foundation\Http\FormRequest;

class StoreChecklistItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'description' => 'required|string|max:500',
            // A checklist item can be handed to a staff member, vendor or TPV —
            // all three are Users, so a single FK covers every case.
            'assigned_to' => 'nullable|integer|exists:users,id',
        ];
    }
}
