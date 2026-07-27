<?php

namespace App\Http\Requests\Hr;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOfferStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status'           => 'required|in:Draft,Pending Approval,Approved,Generated,Sent,Viewed,Accepted,Declined,Rejected,Expired,Withdrawn,Completed',
            'rejection_reason' => 'nullable|string|max:500',
        ];
    }
}
