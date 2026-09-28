<?php

namespace Sire\Http\Requests;

use Sire\Models\ReportLink;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReportLinkRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = (int) $this->user()->tenant_id;

        return [
            // Tenant-scoped exists: a bare exists() would accept another tenant's
            // issue and quietly cross-link the two registers.
            'to_report_id' => [
                'required', 'integer',
                Rule::exists('sire_reports', 'id')->where('tenant_id', $tenantId),
                Rule::notIn([$this->route('report')?->id]),
            ],
            'link_type' => ['required', Rule::in(ReportLink::TYPES)],
            'note'      => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return ['to_report_id.not_in' => 'An issue cannot be linked to itself.'];
    }
}
