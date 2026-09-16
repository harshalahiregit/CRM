<?php

namespace App\Http\Requests\Purchase;

use App\Support\Purchase\PurchaseStrikeSeverity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The same shape TPV validates, so a strike means the same thing on both sides.
 *
 * `reason` is required and not nullable: a strike with no stated reason cannot
 * be appealed, and the third one of these ends somebody's site access.
 */
class IssuePurchaseSafetyStrikeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'severity'    => ['required', Rule::in(PurchaseStrikeSeverity::ALL)],
            'reason'      => 'required|string|max:255',
            'notes'       => 'nullable|string',
            'location'    => 'nullable|string|max:120',
            // Never in the future: a strike records something that happened.
            'occurred_at' => 'nullable|date|before_or_equal:now',
        ];
    }
}
