<?php

namespace App\Http\Requests\Transport;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /transport/trips/{trip}/prechecks — SNG-TRN-010 step 7.
 *
 * ── THE ONLY PRE-TRIP PATH THE PACKAGE SPECIFIES ──────────────────────────
 * Step 5's API sheet, verbatim and complete:
 *
 *   POST | /api/v1/transport/trips/{trip}/prechecks | Submit pre-check
 *        | check_items,evidence | checklist_id,result,state
 *
 * That is the whole specification. Step 11's API registry — the LOCKED
 * baseline — contains no pre-trip endpoint at all, and the document that would
 * have carried the contract, STOS-API, holds the AI specification instead
 * (D-11). So this path is quoted; everything about its body is reasoned.
 *
 * ── check_items IS ACCEPTED, AND OPTIONAL ─────────────────────────────────
 * Step 5 names it, so it is honoured here. It is optional because the same
 * endpoint has to serve the case Step 5 does not describe: BUILDING the
 * checklist. BRW-054 and OPS §28 both say the checklist is created
 * automatically ("Sangoe creates a readiness checklist"), and an automatic act
 * has no separate verb in the API list — so posting with no items reconciles
 * the checklist and returns it, and posting with items does that and then
 * records those confirmations.
 *
 * ── evidence IS REFUSED, LOUDLY ───────────────────────────────────────────
 * Step 5 names `evidence` alongside `check_items`, and FRS TRP-P0-005 requires
 * photo evidence. TRANSPORT HAS NO FILE-UPLOAD CAPABILITY AT ALL —
 * transport_documents.file_path exists and has never been populated by any
 * controller, request or service in the module. Ruled out of scope by the owner
 * on 2026-09-09 (Q4); recorded as D-22.
 *
 * Accepting the field and dropping it would be far worse than refusing it: a
 * supervisor would believe a photograph had been filed against a safety check
 * when nothing had been stored. So the field is rejected with a message that
 * says why. Silence here would be the "fake success" pattern the team
 * conventions forbid.
 */
class SubmitPretripChecksRequest extends FormRequest
{
    /** Gating is the route group's job (transport.permission:transport.pretrip.perform). */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Step 5's `check_items`. Each entry names a row of this trip's
            // checklist and, optionally, what the person wants recorded against
            // it (OPS §41 "remarks").
            'check_items'             => ['sometimes', 'array'],
            'check_items.*.id'        => ['required', 'integer', 'min:1'],
            'check_items.*.remarks'   => ['nullable', 'string', 'max:2000'],

            // Deliberately NOT accepted — see the class docblock. Declared so
            // the refusal is explicit rather than an "unexpected field" shrug.
            'evidence' => ['prohibited'],

            // A result is not accepted either, and that is a rule rather than an
            // omission. All five generated checks are evaluated by the system
            // from data it can read; a person supplying their own pass/fail would
            // be OVERRIDING the evaluation, and override is P1 in all three
            // places the package raises it (RTM CMP-007, BRW-049, PLN-007).
            'check_items.*.result' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'evidence.prohibited' => 'Photo evidence cannot be attached yet — Transport has no document upload. '
                .'Record what you saw in the check remarks instead.',
            'check_items.*.result.prohibited' => 'A pre-trip result is calculated, not entered. '
                .'Confirm the check, or resolve what it reports and refresh the checklist.',
        ];
    }
}
