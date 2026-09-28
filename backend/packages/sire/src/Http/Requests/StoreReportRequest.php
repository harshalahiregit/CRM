<?php

namespace Sire\Http\Requests;

use Sire\Support\SireContextSchema;
use Sire\Support\SirePriority;
use Sire\Support\SireText;
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

            // ---- set by the reporter, at Stage 1 -----------------------------
            // The person hitting the bug is the one who knows how badly it blocks
            // them, so urgency is theirs to state rather than something a lead
            // guesses at later. Triage still owns the final call -- these arrive
            // as the reporter's view of it, and the triage transition overwrites
            // them if a lead disagrees.
            //
            // EVERY ONE OF THESE IS NULLABLE, AND MUST STAY THAT WAY. D45: two
            // required fields, and tests/plug-and-play.test.mjs fails the build
            // if a third appears. A field that blocks the form is a bug nobody
            // reports.
            'priority' => ['nullable', Rule::in(SirePriority::ALL)],

            'steps_to_reproduce' => ['nullable', 'string', 'max:20000'],
            'expected_result'    => ['nullable', 'string', 'max:20000'],
            'actual_result'      => ['nullable', 'string', 'max:20000'],

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
        // Sanitise BEFORE validation, so what the rules measure is what gets
        // stored. Running it afterwards would let a 5-character title pass its
        // min:5 and then be trimmed to nothing.
        $this->merge(SireText::cleanKeys($this->only([
            'title', 'description', 'steps_to_reproduce', 'expected_result', 'actual_result',
        ]), ['title', 'description', 'steps_to_reproduce', 'expected_result', 'actual_result']));

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
