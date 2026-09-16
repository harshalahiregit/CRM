<?php

namespace Sire\Http\Requests;

use Sire\Support\SireContextSchema;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * SIRE — create a report, optionally with Report Issue context.
 *
 * If this file already exists from slice 2, MERGE the `context.*` and `submit`
 * rules into it. The tenant-scoped exists() rules are the ones that matter most:
 * a bare exists() accepts another tenant's category and cross-links the record.
 */
class StoreReportRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = (int) $this->user()->tenant_id;

        $rules = [
            'title'       => ['required', 'string', 'min:5', 'max:255'],
            'description' => ['required', 'string', 'min:10', 'max:20000'],
            'occurred_at' => ['nullable', 'date'],
            'origin'      => ['nullable', Rule::in(['internal', 'client_portal', 'vendor_portal', 'converted', 'import'])],
            'submit'      => ['nullable', 'boolean'],

            'category_id' => [
                'nullable', 'integer',
                Rule::exists('sire_report_categories', 'id')->where('tenant_id', $tenantId),
            ],
            'severity_id' => [
                'nullable', 'integer',
                Rule::exists('sire_severities', 'id')->where('tenant_id', $tenantId),
            ],

            // ---- Report Issue context -------------------------------------
            // Loosely typed on purpose: SireContextService applies the real
            // allowlist. Validation here only rejects the malformed and the
            // oversized, so a bad payload fails fast with a 422 instead of being
            // silently truncated.
            'context'                            => ['nullable', 'array'],
            'context.failed_requests'            => ['nullable', 'array', 'max:'.SireContextSchema::MAX_FAILED_REQUESTS],
            'context.failed_requests.*.method'   => ['nullable', 'string', 'max:10'],
            'context.failed_requests.*.path'     => ['nullable', 'string', 'max:255'],
            'context.failed_requests.*.status'   => ['nullable', 'integer', 'between:0,599'],
            'context.page_context'               => ['nullable', 'array', 'max:'.SireContextSchema::MAX_PAGE_CONTEXT_KEYS],
            'context.context_confidence'         => ['nullable', Rule::in(SireContextSchema::CONFIDENCE)],
            'context.context_source'             => ['nullable', Rule::in(SireContextSchema::SOURCES)],
        ];

        foreach (SireContextSchema::SCALARS as $key => $maxLength) {
            $rules["context.$key"] = ['nullable', 'string', "max:$maxLength"];
        }

        // These two are validated by SCALARS above; re-declaring them as enums
        // would fight the allowlist. Left as strings on purpose.
        unset($rules['context.context_confidence_duplicate']);

        return $rules;
    }

    /**
     * Reject any attempt to set tenancy or identity from the client. These are
     * taken from the authenticated token in the controller; accepting them here
     * — even to ignore them — invites someone to wire them up later.
     */
    protected function prepareForValidation(): void
    {
        $this->request->remove('tenant_id');
        $this->request->remove('reporter_id');
        $this->request->remove('user_id');

        if (is_array($this->input('context'))) {
            $context = $this->input('context');
            unset($context['tenant_id'], $context['user_id'], $context['reporter_id']);
            $this->merge(['context' => $context]);
        }
    }
}
