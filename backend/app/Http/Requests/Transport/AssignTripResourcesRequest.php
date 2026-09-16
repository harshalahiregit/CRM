<?php

namespace App\Http\Requests\Transport;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * API-004 — POST /trips/{trip}/assign.
 *
 * ── WHAT THE REGISTRY ACTUALLY SPECIFIES ──────────────────────────────────
 * CTR-007  vehicle_id | body | BIGINT | Required | "eligible vehicle" | tenant scope
 * CTR-008  driver_id  | body | BIGINT | Required | "eligible driver"  | tenant scope
 *
 * And nothing else. API-004 names ASN-REQ-001 as its request contract, but that
 * reference resolves nowhere — searched all 42 documents (defect D-10). The
 * document that would have defined it, STOS-API, contains the AI specification
 * instead (defect D-11), so the API specification is absent from the package.
 *
 * ── WHY "REQUIRED" IS RELAXED TO "ONE OF THE TWO" ─────────────────────────
 * Taking CTR-007/008 literally would force both in every call. But FRS
 * TRP-P0-004's trigger is "Vehicle allocated" — the driver is chosen AFTER the
 * vehicle — so a literal reading makes the specified workflow impossible to
 * perform. Ruled 2026-09-08: one call may carry either or both, and the trip
 * only reaches `allocated` once both are set, which is what SM-TRP's
 * "Vehicle+driver eligible" entry gate actually requires. Both readings hold.
 *
 * ── NO IDEMPOTENCY-KEY HEADER ─────────────────────────────────────────────
 * API-004 marks idempotency Required. Waived for R1 under the standing Q6
 * ruling, because the integrity it buys is already guaranteed a different way:
 * TripAssignmentService takes a row lock inside a transaction and three unique
 * indexes over generated columns make a duplicate active assignment impossible.
 * A repeated identical call folds into the same row and writes no second audit
 * entry. Recorded in docs/transport/registry-defects.md.
 *
 * Existence and tenancy are NOT checked here — AllocationService resolves both
 * ids through forTenant() and raises ResourceNotFoundException, so a
 * cross-tenant id reads as "no such vehicle" rather than a validation hint that
 * one exists somewhere.
 */
class AssignTripResourcesRequest extends FormRequest
{
    /** Gating is the route group's job (transport.permission:transport.trip.assign). */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vehicle_id' => ['nullable', 'integer', 'min:1', 'required_without:driver_id'],
            'driver_id'  => ['nullable', 'integer', 'min:1', 'required_without:vehicle_id'],

            // STOS-DB §47's history fields. Optional: an allocation is valid
            // without a note, and §47 lists them as things to STORE, not to demand.
            'reason'          => ['nullable', 'string', 'max:500'],
            'allocation_type' => ['nullable', 'string', 'max:30'],  // D-9 — no canonical values

            // allocation_override / override_reason are deliberately absent.
            // PLN-007 is P1; accepting an override here would let a client set a
            // field no ticket has yet authorised or audited.
        ];
    }

    public function messages(): array
    {
        return [
            'vehicle_id.required_without' => 'Choose a vehicle, a driver, or both.',
            'driver_id.required_without'  => 'Choose a vehicle, a driver, or both.',
        ];
    }
}
