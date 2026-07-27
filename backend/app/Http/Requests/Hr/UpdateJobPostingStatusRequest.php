<?php

namespace App\Http\Requests\Hr;

use App\Support\Hr\JobPostingStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateJobPostingStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(JobPostingStatus::ALL)],
        ];
    }
}
