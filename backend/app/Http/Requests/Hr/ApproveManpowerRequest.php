<?php

namespace App\Http\Requests\Hr;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Used for both approve-l1 and approve-l2 — remarks are optional on approval.
 */
class ApproveManpowerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Mandatory, same as a rejection: an approval decision must carry a
            // reason so the audit trail explains why, not just what.
            'remarks' => 'required|string|max:500',
        ];
    }
}
