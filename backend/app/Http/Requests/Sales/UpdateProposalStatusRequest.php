<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProposalStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => 'required|string|in:Draft,Pending Review,Sent,Viewed,Opened,Under Negotiation,Revision Requested,Accepted,Declined,Expired',
            'rejection_reason' => 'nullable|string|max:500',
        ];
    }
}
