<?php

namespace App\Http\Requests\Transport;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Container creation — `MDM-008`, `STOS-CTD §7`.
 *
 * Shape validation only. Uniqueness is NOT checked here, deliberately: the
 * rule is uniqueness of the NORMALISED number within the tenant, and a
 * `unique:` rule on the raw column would accept `ABCD1234567` alongside
 * `abcd-123456-7`. ContainerService does that check against the normalised
 * key, and the database enforces it underneath. See D-51 for why the
 * normalisation lives in PHP rather than in a generated column.
 *
 * ── WHAT THIS DELIBERATELY DOES NOT ACCEPT ───────────────────────────────
 *   container_number_normalized  derived in the model's saving() hook. It is
 *                                not fillable, and accepting it would let a
 *                                caller put a container beyond CTD-001 search
 *                                by supplying a key that does not match.
 *   size_feet, is_reefer         no document defines either — D-52.
 *   seal_number                  specified in STOS-CMP §76/§77 and owned by
 *                                Person 3, not here.
 *   status, dates, customer      a container has none of its own. It is the
 *                                physical unit; the consignment carries the
 *                                commercial facts (STOS-CTD §8).
 */
class StoreContainerRequest extends FormRequest
{
    /** Gating is the route group's job (transport.permission), not this class's. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Bounded to the column width. No format rule beyond that: CTD §7
            // says "configurable format validation" and NO document anywhere
            // names the format. ISO 6346 is the industry standard but the
            // package never cites it, and its check digit would reject
            // legitimate non-ISO numbers. Inventing a regex here would be the
            // D-9 mistake — see the coverage checklist, deferred to Product.
            'container_number' => ['required', 'string', 'max:32'],

            // Free text, not an enum: no document defines the vocabulary.
            // Searched all thirty package documents for 20ft/40ft/HC/high cube
            // — zero hits. Recorded rather than invented.
            'container_type' => ['nullable', 'string', 'max:40'],
        ];
    }

    public function messages(): array
    {
        return [
            'container_number.required' => 'Enter the container number.',
            'container_number.max'      => 'A container number is at most 32 characters.',
        ];
    }
}
