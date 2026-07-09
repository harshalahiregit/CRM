<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'subject'       => 'sometimes|string|max:255',
            'date'          => 'sometimes|date',
            'open_till'     => 'nullable|date',
            'status'        => 'sometimes|in:Draft,Open,Sent,Accepted,Declined,Expired',
            'notes'         => 'nullable|string',
            'tags'          => 'nullable|string',
            'allow_comments'=> 'nullable|boolean',
            'line_items'    => 'nullable|array',
        ];
    }
}
