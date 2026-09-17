<?php

namespace App\Http\Requests\Transport;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Blockers and follow-up on a receivable — SNG-TRN-016.
 *
 * Two of the four nouns in the acceptance criterion. Neither is in the registry
 * — DB-013 has one field row and IDX-009 names two columns — so both are
 * constructed against the criterion itself. See D-61.
 *
 * `blocker_reason` is `nullable` AND `present`-optional on purpose: sending it
 * as null is how a blocker is CLEARED, so the controller distinguishes "absent"
 * from "explicitly null". A validator that simply dropped nulls would make a
 * blocker impossible to remove.
 *
 * `next_follow_up_on` has no `after_or_equal:today`. Chasing something that was
 * already due yesterday is the normal case, and refusing a past date would make
 * the overdue queue unusable on the day somebody actually works it.
 */
class UpdateCollectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'blocker_reason'    => ['nullable', 'string', 'max:500'],
            'next_follow_up_on' => ['nullable', 'date'],
            'note'              => ['nullable', 'string', 'max:500'],
        ];
    }
}
