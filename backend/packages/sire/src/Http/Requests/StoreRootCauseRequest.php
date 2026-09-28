<?php

namespace Sire\Http\Requests;

use Sire\Models\RootCause;
use Illuminate\Foundation\Http\FormRequest;
use Sire\Http\Requests\Concerns\ChecksBoundTenant;
use Illuminate\Validation\Rule;

class StoreRootCauseRequest extends FormRequest
{
    use ChecksBoundTenant;

    public function authorize(): bool
    {
        return $this->assertBoundTenant('report');
    }

    public function rules(): array
    {
        return [
            'category'    => ['required', Rule::in(RootCause::CATEGORIES)],

            // WHICH technique was used. Defaults to five_whys because that is
            // what every analysis written before this column existed was.
            'method'      => ['nullable', Rule::in(RootCause::METHODS)],

            // The working of a fishbone or a fault tree. A free JSON bag on
            // purpose: the two shapes are genuinely different -- branches of
            // contributing causes versus a boolean graph -- and neither is ever
            // queried. What IS queried is the category and the confirmation, and
            // those have columns of their own.
            'analysis'    => ['nullable', 'array'],
            'description' => ['required', 'string', 'max:20000'],

            'contributing_factors'   => ['nullable', 'array', 'max:20'],
            'contributing_factors.*' => ['string', 'max:500'],

            'detection_gap'     => ['nullable', 'string', 'max:20000'],
            'corrective_action' => ['nullable', 'string', 'max:20000'],
            'preventive_action' => ['nullable', 'string', 'max:20000'],

            // Exactly five, in order. The service enforces that a serious issue
            // has all five filled before an RCA can be confirmed.
            'five_whys'   => ['nullable', 'array', 'max:5'],
            'five_whys.*' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Confirmation is a sign-off with its own endpoint and capability, never a
        // field someone can set while saving a draft.
        $this->request->remove('confirmed_by');
        $this->request->remove('confirmed_at');
        $this->request->remove('tenant_id');
    }
}
