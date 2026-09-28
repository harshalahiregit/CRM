<?php

namespace Sire\Http\Requests;

use Sire\Support\SirePriority;
use Sire\Support\SireWorkflow;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape only. Whether the transition is LEGAL from the current status, whether
 * the caller may perform it, and whether its required fields are satisfied are
 * all decided in SireWorkflowService — the state machine is not duplicated here.
 */
class ApplyTransitionRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = (int) $this->user()->tenant_id;

        return [
            'action' => ['required', 'string', Rule::in(array_keys(SireWorkflow::TRANSITIONS))],

            'assignee_id'     => ['nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],

            // More than one person can work an issue, but exactly one OWNS it.
            // assignee_id is the owner -- every workflow guard is written against
            // it -- and these are the others, who see it on their queue and hear
            // about it. Capped: a defect assigned to twelve people is assigned to
            // nobody, and the cap is the honest way to say so.
            'co_assignee_ids'   => ['nullable', 'array', 'max:10'],
            'co_assignee_ids.*' => ['integer', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
            'qa_assignee_id'  => ['nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
            'severity_id'     => ['nullable', 'integer', Rule::exists('sire_severities', 'id')->where('tenant_id', $tenantId)],
            // Category was only settable at CREATION, and Report Issue deliberately
            // asks for two fields -- so no issue ever had one and the register's
            // Type column was dead for every row. Triage is where it belongs.
            'category_id'     => ['nullable', 'integer', Rule::exists('sire_report_categories', 'id')->where('tenant_id', $tenantId)],
            'duplicate_of_id' => ['nullable', 'integer', Rule::exists('sire_reports', 'id')->where('tenant_id', $tenantId)],
            'priority'        => ['nullable', Rule::in(SirePriority::ALL)],

            'fix_summary'         => ['nullable', 'string', 'max:20000'],
            'investigation_notes' => ['nullable', 'string', 'max:20000'],
            'dev_test_notes'      => ['nullable', 'string', 'max:20000'],
            'qa_notes'            => ['nullable', 'string', 'max:20000'],
            'hold_reason'         => ['nullable', 'string', 'max:500'],
            'resolution_note'     => ['nullable', 'string', 'max:2000'],
            'release_ref'         => ['nullable', 'string', 'max:120'],
            'comment'             => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Status is never client-supplied. The machine decides where an issue goes.
        $this->request->remove('status');
        $this->request->remove('tenant_id');
        $this->request->remove('resolution');
    }
}
