<?php

namespace App\Http\Requests\Hr;

use Illuminate\Foundation\Http\FormRequest;

class UpdateJobPostingExternalIdRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'platform'    => 'required|in:trulytalents,linkedin,naukri,indeed,monster',
            'external_id' => 'required|string|max:100',
        ];
    }
}
