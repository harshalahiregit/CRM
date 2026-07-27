<?php

namespace App\Http\Requests\Hr;

use App\Models\Hr\HrCandidateDocument;
use App\Services\Hr\CandidateDocumentService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCandidateDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type'     => ['nullable', Rule::in(HrCandidateDocument::TYPES)],
            'document' => [
                'required',
                'file',
                'mimes:'.implode(',', CandidateDocumentService::ALLOWED_MIMES),
                'max:'.CandidateDocumentService::MAX_SIZE_KB,
            ],
        ];
    }
}
