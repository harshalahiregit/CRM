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
            // One id or a list of them. The endpoint took a single integer
            // before checklist lines could be shared, and old callers still send
            // one, so both shapes are accepted and normalised in the service.
            'assigned_to'   => 'nullable',
            'assigned_to.*' => 'integer|exists:users,id',
        ];
    }
}
