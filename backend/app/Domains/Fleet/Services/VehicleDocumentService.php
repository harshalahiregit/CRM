<?php

namespace App\Domains\Fleet\Services;

use App\Domains\Fleet\Models\Vehicle;
use App\Exceptions\BusinessException;
use App\Models\Transport\TransportDocument;
use App\Services\Transport\TransportDocumentService;
use App\Support\Transport\TransportDocumentType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * STOS-FLEET — a vehicle's statutory paperwork (T-57).
 *
 * ── FLEET DOES NOT OWN DOCUMENTS, AND THIS DOES NOT PRETEND TO ────────────
 * The evidence store belongs to STOS-DOC (Person 3): file storage, versioning,
 * the audit trail. Every write below goes THROUGH his
 * `TransportDocumentService` rather than into `transport_documents` directly,
 * so versioning and auditing happen once, in his code, however a document
 * arrives.
 *
 * What Fleet owns is the consequence: five statutory documents gate dispatch,
 * and the vehicle's date columns are a projection of the VERIFIED ones.
 *
 * ── AN UPLOAD IS NOT A CLEARANCE ──────────────────────────────────────────
 * Ruled 2026-09-19. Attaching a file must never move the dispatch gate. A truck
 * is cleared when somebody has looked at the certificate — not when somebody
 * managed to photograph it. So nothing here touches a vehicle's expiry date;
 * only `project()` does, and only from a VERIFIED document.
 */
class VehicleDocumentService
{
    public function __construct(
        private TransportDocumentService $documents,
        private ComplianceService $compliance,
    ) {
    }

    /**
     * File a document against a vehicle.
     *
     * Returns the document AND whether the gate moved, because "saved" and
     * "this truck can now go out" are different answers and the screen must
     * not imply the second when it only did the first.
     */
    public function file(int $vehicleId, int $companyId, string $type, array $data, $actor = null): array
    {
        $vehicle = $this->vehicle($vehicleId, $companyId);
        $type = $this->normaliseType($type);

        $document = $this->documents->file($vehicle, $type, $this->withFile($data, $companyId), $companyId, $actor);

        Log::channel('stos')->info('Vehicle document filed', [
            'company_id' => $companyId, 'vehicle_id' => $vehicle->id,
            'document_id' => $document->id, 'type' => $type,
            'gates_dispatch' => $this->gatedBy($type) !== null,
        ]);

        return [
            'document' => $document->fresh(),
            // Said out loud rather than left to be inferred from a null.
            'gate_moved' => false,
            'notice' => $this->gatedBy($type)
                ? 'Filed. This document gates dispatch, so the vehicle stays blocked until it is verified.'
                : 'Filed. This document does not gate dispatch.',
        ];
    }

    /** Replace a document with a newer one. Versioning is STOS-DOC's job. */
    public function renew(int $documentId, int $companyId, array $data, $actor = null): array
    {
        $current = $this->documents->find($documentId, $companyId);

        $document = $this->documents->renew($current, $this->withFile($data, $companyId), $companyId, $actor);

        return [
            'document'   => $document->fresh(),
            'gate_moved' => false,
            'notice'     => 'Renewed. The new version still needs verifying before it clears anything.',
        ];
    }

    /**
     * Everything on file for a vehicle, newest first, with the gate verdict.
     */
    public function forVehicle(int $vehicleId, int $companyId): array
    {
        $vehicle = $this->vehicle($vehicleId, $companyId);

        $documents = TransportDocument::where('tenant_id', $companyId)
            ->where('entity_type', 'vehicle')
            ->where('entity_id', $vehicle->id)
            ->orderByDesc('id')->get();

        $gating = [];

        foreach (TransportDocumentType::GATES_DISPATCH as $type => $column) {
            $verified = $documents->first(fn ($d) => $d->document_type === $type
                && $d->verification_status === 'VERIFIED'
                && $d->status === TransportDocument::STATUS_ACTIVE);

            $gating[$type] = [
                'label'    => TransportDocumentType::label($type),
                'column'   => $column,
                // What the VEHICLE currently says, which may still be a
                // hand-entered date from before documents were the master.
                'vehicle_date' => optional($vehicle->{$column})->toDateString(),
                'verified' => $verified !== null,
                'document_id' => $verified?->id,
                'valid_until' => optional($verified?->valid_until)->toDateString(),
                'awaiting'    => $documents->contains(fn ($d) => $d->document_type === $type
                    && $d->verification_status !== 'VERIFIED'
                    && $d->status === TransportDocument::STATUS_ACTIVE),
            ];
        }

        return [
            'documents' => $documents->all(),
            'gating'    => $gating,
            // The types this vehicle may carry, so a screen never offers one
            // the service would then refuse.
            'types'     => array_map(
                fn ($t) => ['value' => $t, 'label' => TransportDocumentType::label($t),
                            'gates_dispatch' => isset(TransportDocumentType::GATES_DISPATCH[$t])],
                TransportDocumentType::VEHICLE_APPLICABLE
            ),
        ];
    }

    /**
     * T-53 — push a VERIFIED document's expiry onto the vehicle.
     *
     * This is the ONLY thing that writes those five date columns. They are a
     * read-optimised cache of the verified evidence, not a master, and nothing
     * else may set them.
     *
     * Returns null when the document does not gate dispatch or is not verified,
     * rather than silently doing nothing — the caller can then say why.
     */
    public function project(TransportDocument $document, int $companyId): ?Vehicle
    {
        $column = $this->gatedBy($document->document_type);

        if (! $column) {
            return null;    // a tax receipt is statutory but gates nothing
        }

        if ($document->verification_status !== 'VERIFIED') {
            return null;    // an upload is not a clearance
        }

        if ($document->entity_type !== 'vehicle') {
            return null;
        }

        $vehicle = Vehicle::forCompany($companyId)->find($document->entity_id);

        if (! $vehicle) {
            return null;
        }

        // Never move a date BACKWARDS from an older certificate. Documents are
        // verified out of order often enough — somebody clears a backlog — and
        // a stale renewal overwriting a current one would block a compliant
        // truck with nothing on screen explaining it.
        $existing = $vehicle->{$column};

        if ($existing && $document->valid_until && $document->valid_until->lt($existing)) {
            Log::channel('stos')->info('Verified document is older than the date already held; not projected', [
                'company_id' => $companyId, 'vehicle_id' => $vehicle->id,
                'document_id' => $document->id, 'column' => $column,
            ]);

            return $vehicle;
        }

        $vehicle->update([$column => $document->valid_until]);

        // The verdict is derived from all five dates, so it is re-run here
        // rather than left until the nightly sweep — otherwise a renewal
        // verified at 09:00 does not free the truck until midnight.
        $this->compliance->refresh($vehicle->fresh());

        Log::channel('stos')->info('Verified document projected onto the vehicle', [
            'company_id' => $companyId, 'vehicle_id' => $vehicle->id,
            'document_id' => $document->id, 'column' => $column,
            'valid_until' => optional($document->valid_until)->toDateString(),
        ]);

        return $vehicle->fresh();
    }

    /**
     * Record a verification verdict, and project it if it clears.
     *
     * ── THIS IS A SEAM, NOT FLEET'S FEATURE ───────────────────────────────
     * The evidence lifecycle belongs to STOS-DOC (Person 3) and so does the
     * screen a compliance clerk will actually use — who may verify, what they
     * must check, four-eyes, OCR assistance, the queue. None of that is here.
     *
     * What is here is the minimum Fleet needs to stop trusting unverified
     * paperwork today, and the hook his workflow calls when it reaches a
     * verdict. When he builds it, this becomes his entry point rather than
     * something to replace.
     */
    public function verify(int $documentId, int $companyId, string $verdict, ?string $reason = null, $actor = null): array
    {
        $document = $this->documents->find($documentId, $companyId);
        $verdict = strtoupper(trim($verdict));

        if (! in_array($verdict, [TransportDocument::VERIFICATION_VERIFIED, TransportDocument::VERIFICATION_REJECTED], true)) {
            throw new BusinessException('A verification verdict is either VERIFIED or REJECTED.', 422);
        }

        if ($verdict === TransportDocument::VERIFICATION_REJECTED && ! $reason) {
            // A rejection without a reason cannot be acted on: whoever uploaded
            // it has to know what to fix.
            throw new BusinessException('Say why the document was rejected.', 422);
        }

        $document->update([
            'verification_status' => $verdict,
            'verified_at'         => now(),
            'verified_by'         => $actor?->id,
            'rejection_reason'    => $verdict === TransportDocument::VERIFICATION_REJECTED ? $reason : null,
        ]);

        $document = $document->fresh();

        $vehicle = $this->project($document, $companyId);

        Log::channel('stos')->info('Document verification recorded', [
            'company_id' => $companyId, 'document_id' => $document->id,
            'verdict' => $verdict, 'user_id' => $actor?->id,
            'projected_onto_vehicle' => $vehicle?->id,
        ]);

        return [
            'document'   => $document,
            'gate_moved' => $vehicle !== null,
            'vehicle'    => $vehicle,
        ];
    }

    /* ── helpers ────────────────────────────────────────────────── */

    /**
     * Put the uploaded certificate somewhere, and name it for the store.
     *
     * `TransportDocumentService` stores `file_path`, `file_name` and
     * `file_hash` — it does not take an `UploadedFile`. The controller
     * validated one under the key `file`, handed the whole array over, and the
     * shared service intersected it against its editable columns, where `file`
     * is not one. So the upload was **dropped, silently**: the API answered
     * 201, the screen said "Filed", and no certificate was kept.
     *
     * Found by putting a real PDF through the endpoint. Every test passed a
     * document number and a date and no file, so none of them noticed — the
     * same shape of blind spot as the tests that passed while asserting D-116.
     *
     * Private disk, same as a fuel receipt: a statutory certificate is not
     * something to serve from a public URL.
     */
    private function withFile(array $data, int $companyId): array
    {
        $file = $data['file'] ?? null;
        unset($data['file']);

        if (! $file instanceof UploadedFile) {
            return $data;
        }

        return [
            ...$data,
            'file_path' => $file->store("stos/vehicle-documents/{$companyId}", 'local'),
            // The name a person recognises, beside a stored path that is a
            // hash nobody can read.
            'file_name' => $file->getClientOriginalName(),
            // So the same certificate uploaded twice is recognisable as one.
            'file_hash' => hash_file('sha256', $file->getRealPath()),
        ];
    }

    /** Which vehicle date this document type sets, or null if it sets none. */
    public function gatedBy(string $type): ?string
    {
        return TransportDocumentType::GATES_DISPATCH[$type] ?? null;
    }

    private function normaliseType(string $type): string
    {
        $type = strtolower(trim($type));

        if (! in_array($type, TransportDocumentType::VEHICLE_APPLICABLE, true)) {
            throw new BusinessException(
                TransportDocumentType::label($type).' cannot be filed against a vehicle.',
                422
            );
        }

        return $type;
    }

    private function vehicle(int $id, int $companyId): Vehicle
    {
        $vehicle = Vehicle::forCompany($companyId)->find($id);

        if (! $vehicle) {
            throw new BusinessException('That vehicle is not in your fleet.', 404);
        }

        return $vehicle;
    }
}
