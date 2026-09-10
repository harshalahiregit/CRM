<?php

namespace Sire\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MarkRegressionRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = (int) $this->user()->tenant_id;

        return [
            'regression_of_id' => [
                'nullable', 'integer',
                Rule::exists('sire_reports', 'id')->where('tenant_id', $tenantId),
            ],
            'caused_by_release_id' => [
                'nullable', 'integer',
                Rule::exists('sire_releases', 'id')->where('tenant_id', $tenantId),
            ],
            'regression_notes' => ['nullable', 'string', 'max:20000'],
        ];
    }
}
