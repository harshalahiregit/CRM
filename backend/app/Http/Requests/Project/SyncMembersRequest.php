<?php

namespace App\Http\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;

class SyncMembersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_ids'   => 'present|array',
            'user_ids.*' => 'integer|exists:users,id',
        ];
    }
}
