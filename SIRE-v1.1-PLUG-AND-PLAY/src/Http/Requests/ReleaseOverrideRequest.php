<?php

namespace Sire\Http\Requests;

use Sire\Models\ReleaseOverride;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An override must name what it is bypassing and say why in writing.
 *
 * A blanket "ignore everything" flag would make the register useless: the
 * interesting question is always which specific check was skipped, and on whose
 * authority.
 */
class ReleaseOverrideRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'gates'   => ['required', 'array', 'min:1', 'max:20'],
            'gates.*' => ['required', 'string', 'max:64'],

            'reason' => ['required', Rule::in(ReleaseOverride::REASONS)],

            // Long enough to be a sentence, because it will be read by someone
            // who was not in the room.
            'justification' => ['required', 'string', 'min:20', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'justification.min' => 'Explain the override in a sentence or two — this is read later, by someone who was not there.',
            'gates.required'    => 'Name the gates being overridden.',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Authorship is the token's, never the payload's.
        $this->request->remove('authorized_by');
        $this->request->remove('authorized_at');
        $this->request->remove('tenant_id');
        $this->request->remove('gate_snapshot');
    }
}
