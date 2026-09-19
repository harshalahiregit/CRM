<?php

namespace App\Http\Requests\Transport;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /transport/trips/{trip}/deliver — STT-007, RTM STOS-REQ-OPS-010.
 *
 * The same one optional field as departure, for the same reason: OPS-010's
 * whole acceptance criterion is "Delivery confirmed", and Q3 set the precedent
 * for what a manually recorded milestone on this table looks like.
 *
 * ── EVERYTHING FRS TRP-P0-013 LISTS IS REFUSED HERE, AND BELONGS TO P3 ────
 * "POD image/PDF; signature; timestamp; location; shortage/damage remarks" is
 * the field list for POD CAPTURE, which is `trip_documents` (DB-009), API-008,
 * PERM-010 — Person 3's, with its own upload path and its own verification.
 *
 * Accepting a `shortage_remarks` here would create a second place where the
 * same fact lives, and the two would disagree the first time someone edited
 * one. The refusal says where the field actually goes, so the caller is
 * redirected rather than merely blocked.
 */
class RecordDeliveryRequest extends FormRequest
{
    /** Gating is the route group's job (transport.permission:transport.trip.deliver). */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'delivered_at' => ['nullable', 'date'],

            'pod_file'          => ['prohibited'],
            'signature'         => ['prohibited'],
            'shortage_remarks'  => ['prohibited'],
            'damage_remarks'    => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        $pod = ' Record it against the proof of delivery instead (POST /trips/{trip}/pod), which is where FRS TRP-P0-013 puts it.';

        return [
            'pod_file.prohibited'         => 'Proof of delivery is uploaded separately.'.$pod,
            'signature.prohibited'        => 'A signature is part of the proof of delivery.'.$pod,
            'shortage_remarks.prohibited' => 'Shortage remarks are part of the proof of delivery.'.$pod,
            'damage_remarks.prohibited'   => 'Damage remarks are part of the proof of delivery.'.$pod,
        ];
    }
}
