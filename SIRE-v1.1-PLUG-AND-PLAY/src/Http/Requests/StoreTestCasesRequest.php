<?php

namespace Sire\Http\Requests;

use Sire\Models\IssueTestCase;
use Sire\Support\SireTestCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTestCasesRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'test_cases'              => ['required', 'array', 'min:1', 'max:50'],
            'test_cases.*.category'   => ['required', Rule::in(SireTestCategory::ALL)],
            'test_cases.*.title'      => ['required', 'string', 'max:255'],
            'test_cases.*.given'      => ['nullable', 'string', 'max:5000'],
            'test_cases.*.when'       => ['nullable', 'string', 'max:5000'],
            'test_cases.*.then'       => ['nullable', 'string', 'max:5000'],
            'test_cases.*.rationale'  => ['nullable', 'string', 'max:1000'],
            'test_cases.*.phase'      => ['nullable', Rule::in(IssueTestCase::PHASES)],
            // Recorded honestly: a test accepted from a generator is still a
            // generated test, and the register should say so.
            'test_cases.*.source'     => ['nullable', Rule::in([IssueTestCase::SOURCE_AI, IssueTestCase::SOURCE_HUMAN])],

            'ai_suggestion_id' => ['nullable', 'integer'],
        ];
    }

    protected function prepareForValidation(): void
    {
        /*
         * A result cannot arrive with a test case, at any nesting depth.
         *
         * This is the request-level half of the invariant: `result` is written in
         * exactly one place, by a person, with the QA capability. Stripping it here
         * means a payload claiming a pass is not merely ignored — it never reaches
         * the service at all.
         */
        $cases = $this->input('test_cases');

        if (is_array($cases)) {
            $this->merge([
                'test_cases' => array_map(function ($case) {
                    if (is_array($case)) {
                        unset($case['result'], $case['executed_by'], $case['executed_at'], $case['status'], $case['tenant_id']);
                    }

                    return $case;
                }, $cases),
            ]);
        }
    }
}
