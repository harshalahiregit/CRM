<?php

namespace Sire\Http\Requests;

use Sire\Support\SireWorkflow;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordActionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'action'              => ['required', 'string', Rule::in(array_keys(SireWorkflow::ACTIONS))],
            'investigation_notes' => ['nullable', 'string', 'max:20000'],
            'fix_summary'         => ['nullable', 'string', 'max:20000'],
            'dev_test_notes'      => ['nullable', 'string', 'max:20000'],
            'qa_notes'            => ['nullable', 'string', 'max:20000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->request->remove('status');
        $this->request->remove('tenant_id');
    }
}
