<?php

namespace App\Http\Requests\Hr;

use App\Services\Hr\ResumeService;
use Illuminate\Foundation\Http\FormRequest;

class UploadResumeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'resume' => [
                'required',
                'file',
                'max:'.ResumeService::MAX_SIZE_KB,
                'mimes:pdf,doc,docx',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'resume.max'   => 'Resume must be under 5 MB.',
            'resume.mimes' => 'Only PDF, DOC, and DOCX files are allowed.',
        ];
    }
}
