<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class BulkLeadActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action'     => 'required|in:delete,status,assign,lost,junk,source',
            'lead_ids'   => 'required|array|min:1',
            'lead_ids.*' => 'integer',
            'value'      => 'nullable',
        ];
    }
}
