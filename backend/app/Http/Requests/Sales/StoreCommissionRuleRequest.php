<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class StoreCommissionRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'                   => 'required|string|max:255',
            'basis'                  => 'nullable|in:product,executive,region,target',
            'calc_type'              => 'nullable|in:percentage,flat',
            'rate'                   => 'required|numeric|min:0',
            'conditions'             => 'nullable|array',
            'conditions.min_amount'  => 'nullable|numeric|min:0',
            'applies_to'             => 'nullable|in:won_deal,paid_invoice,both',
            'is_active'              => 'nullable|boolean',
        ];
    }
}
