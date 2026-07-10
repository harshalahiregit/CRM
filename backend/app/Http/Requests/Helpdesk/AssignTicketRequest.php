<?php

namespace App\Http\Requests\Helpdesk;

use Illuminate\Foundation\Http\FormRequest;

class AssignTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // present + nullable: the key must be sent, but null means "unassign".
        return [
            'assigned_to' => 'present|nullable|integer|exists:users,id',
        ];
    }
}
