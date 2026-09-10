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
            'qa_assignee_id'  => ['nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
            'severity_id'     => ['nullable', 'integer', Rule::exists('sire_severities', 'id')->where('tenant_id', $tenantId)],
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
