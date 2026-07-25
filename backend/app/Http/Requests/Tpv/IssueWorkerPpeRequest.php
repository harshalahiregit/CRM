<?php

namespace App\Http\Requests\Tpv;

use App\Support\Tpv\TpvPpeItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IssueWorkerPpeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item'              => ['required', Rule::in(TpvPpeItem::ALL)],
            'qty'               => 'nullable|integer|min:1|max:20',
            'size'              => 'nullable|string|max:20',
            'issued_date'       => 'nullable|date',
            // Reserved for Shivam's Inventory — accepted but not yet constrained.
            'inventory_item_id' => 'nullable|integer',
            'notes'             => 'nullable|string',
        ];
    }
}
