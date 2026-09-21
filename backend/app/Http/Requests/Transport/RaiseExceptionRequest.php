<?php

namespace App\Http\Requests\Transport;

use App\Support\Transport\ExceptionCategory;
use App\Support\Transport\ExceptionSeverity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /transport/trips/{trip}/exceptions — API-007.
 *
 * CTR-011 is the only contract row: `severity`, body, ENUM, required,
 * low/medium/high/critical, "Escalation policy applies".
 *
 * `category` is not in the contract and is required here anyway. OPS §87 lists
 * it among the twelve fields every exception "must contain", and an exception
 * with no category cannot be routed, filtered or counted — D-37 records that
 * Step 11's Enums sheet has no category enum, so OPS §88's eight are used
 * because they are the only list any document gives.
 *
 * `cause` is required by the service rather than only here, because an
 * exception nobody can act on is worse than one nobody raised.
 */
class RaiseExceptionRequest extends FormRequest
{
    /** Gating is the route group's job (transport.permission:…exception.create). */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category' => ['required', 'string', Rule::in(ExceptionCategory::ALL)],
            'severity' => ['required', 'string', Rule::in(ExceptionSeverity::ALL)],
            'cause'    => ['required', 'string', 'min:8', 'max:2000'],
            'owner_id' => ['nullable', 'integer'],

            // OPS §87 requires both and nothing in the package can compute
            // either — no formula exists and no cost model does until
            // SNG-TRN-012/018. Refused rather than accepted as a number
            // somebody typed, because a stored impact nobody can reproduce is
            // worse than an absent one. D-32.
            'financial_impact' => ['prohibited'],
            'customer_impact'  => ['prohibited'],

            // D-30. `waived` is not in the vocabulary and the waiver is not
            // built; refusing it with its reason is how a caller learns that
            // rather than getting a silent drop.
            'status' => ['prohibited'],
            'waive'  => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        $impact = ' OPS §87 asks for it and no document defines a formula, so nothing here can compute it honestly (D-32).';

        return [
            'category.required' => 'Choose a category — it is what decides who this reaches.',
            'category.in'       => 'Choose one of OPS §88\'s eight categories: '.implode(', ', ExceptionCategory::ALL).'.',
            'severity.in'       => 'Severity must be low, medium, high or critical (CTR-011).',
            'cause.required'    => 'Say what went wrong. Whoever picks this up has only this sentence to go on.',
            'cause.min'         => 'Give a little more detail — a few words is not something the next person can act on.',
            'financial_impact.prohibited' => 'Financial impact is not recorded here.'.$impact,
            'customer_impact.prohibited'  => 'Customer impact is not recorded here.'.$impact,
            'status.prohibited' => 'An exception always starts open. It moves by being acknowledged and resolved, not by being set.',
            'waive.prohibited'  => 'BR-P0-011 allows an Owner to waive the evidence requirement. That waiver is specified but not built yet (D-30).',
        ];
    }
}
