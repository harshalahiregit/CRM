<?php

namespace App\Domains\Fleet\Services;

use App\Domains\Fleet\Models\DriverProfile;
use App\Exceptions\BusinessException;
use App\Models\Transport\TransportDocument;
use App\Services\Transport\TransportDocumentService;
use App\Support\Transport\TransportDocumentType;
use Illuminate\Support\Facades\Log;

/**
 * STOS-FLEET — a driver's paperwork (T-43).
 *
 * The twin of `VehicleDocumentService`, and deliberately the same shape: the
 * evidence store is STOS-DOC's (Person 3), every write goes through his
 * `TransportDocumentService`, and what Fleet owns is the CONSEQUENCE — a
 * verified driving licence sets `driver_profiles.licence_expiry`, which is what
 * `DriverService` blocks a dispatch on.
 *
 * ── AN UPLOAD IS NOT A CLEARANCE ──────────────────────────────────────────
 * Same rule as vehicles, and it matters more here: a driving licence is the one
 * document that stops a person being sent out. Photographing it must not clear
 * them. Only `project()` writes the expiry, and only from a VERIFIED document.
 *
 * ── ONLY THE LICENCE GATES TODAY, AND THAT IS NOT AN OVERSIGHT ────────────
 * `GATES_DISPATCH` for vehicles maps five types onto five date columns.
 * A driver has exactly one such column — `licence_expiry`. A medical
 * certificate is filed, versioned and shown, and it gates NOTHING, because
 * `driver_profiles` has no `medical_expiry` column yet (T-41). Inventing a
 * second gate here without the column would mean the screen claimed a block the
 * allocation engine does not enforce, which is worse than an honest gap.
 */
class DriverDocumentService
{
    /**
     * The driver equivalent of `TransportDocumentType::GATES_DISPATCH`.
     *
     * Kept as a map, not a single constant, so T-41 adds a row here rather than
     * rewriting the class.
     */
    public const GATES_DISPATCH = [
        TransportDocumentType::DRIVING_LICENSE => 'licence_expiry',
    ];

    public function __construct(private TransportDocumentService $documents)
    {
    }

    /**
     * File a document against a driver.
     *
     * Returns whether the gate moved as well as the document, because "saved"
     * and "this driver can now be dispatched" are different answers and the
     * screen must not imply the second when it only did the first.
     */
    public function file(int $companyId, string $source, int $sourceId, string $type, array $data, $actor = null): array
    {
        $profile = $this->profile($companyId, $source, $sourceId);
        $type = $this->normaliseType($type);

        $document = $this->documents->file($profile, $type, $data, $companyId, $actor);

        Log::channel('stos')->info('Driver document filed', [
            'company_id' => $companyId, 'driver_profile_id' => $profile->id,
            'ref' => $source.':'.$sourceId,
            'document_id' => $document->id, 'type' => $type,
            'gates_dispatch' => $this->gatedBy($type) !== null,
        ]);

        return [
            'document' => $document->fresh(),
            'gate_moved' => false,
            'notice' => $this->gatedBy($type)
                ? 'Filed. A licence does not clear a driver until somebody has verified it.'
                : 'Filed. This document does not gate dispatch.',
        ];
    }

    /** Replace a document with a newer one. Versioning is STOS-DOC's job. */
    public function renew(int $documentId, int $companyId, array $data, $actor = null): array
    {
        $current = $this->documents->find($documentId, $companyId);

        $document = $this->documents->renew($current, $data, $companyId, $actor);

        return [
            'document'   => $document->fresh(),
            'gate_moved' => false,
            'notice'     => 'Renewed. The new version still needs verifying before it clears anything.',
        ];
    }

    /** Everything on file for a driver, newest first, with the gate verdict. */
    public function forDriver(int $companyId, string $source, int $sourceId): array
    {
        $profile = $this->profile($companyId, $source, $sourceId);

        $documents = TransportDocument::where('tenant_id', $companyId)
            ->where('entity_type', 'driver')
            ->where('entity_id', $profile->id)
            ->orderByDesc('id')->get();

        $gating = [];

        foreach (self::GATES_DISPATCH as $type => $column) {
            $verified = $documents->first(fn ($d) => $d->document_type === $type
                && $d->verification_status === 'VERIFIED'
                && $d->status === TransportDocument::STATUS_ACTIVE);

            $gating[$type] = [
                'label'    => TransportDocumentType::label($type),
                'column'   => $column,
                // What the PROFILE currently says, which may still be a
                // hand-typed date from before documents were the master.
                'driver_date' => optional($profile->{$column})->toDateString(),
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
            'types'     => array_map(
                fn ($t) => ['value' => $t, 'label' => TransportDocumentType::label($t),
                            'gates_dispatch' => isset(self::GATES_DISPATCH[$t])],
                $this->offerableTypes()
            ),
        ];
    }

    /**
     * T-53 — push a VERIFIED licence's expiry onto the driver profile.
     *
     * The only thing that writes `licence_expiry` from evidence. Returns null
     * rather than silently doing nothing, so the caller can say why.
     */
    public function project(TransportDocument $document, int $companyId): ?DriverProfile
    {
        $column = $this->gatedBy($document->document_type);

        if (! $column) {
            return null;    // an ID proof is worth holding and gates nothing
        }

        if ($document->verification_status !== 'VERIFIED') {
            return null;    // an upload is not a clearance
        }

        if ($document->entity_type !== 'driver') {
            return null;
        }

        $profile = DriverProfile::forCompany($companyId)->find($document->entity_id);

        if (! $profile) {
            return null;
        }

        // Never move the date BACKWARDS from an older certificate. Licences get
        // verified out of order whenever somebody clears a backlog, and a stale
        // one overwriting a current one would ground a legal driver with
        // nothing on screen explaining it.
        $existing = $profile->{$column};

        if ($existing && $document->valid_until && $document->valid_until->lt($existing)) {
            Log::channel('stos')->info('Verified driver document is older than the date already held; not projected', [
                'company_id' => $companyId, 'driver_profile_id' => $profile->id,
                'document_id' => $document->id, 'column' => $column,
            ]);

            return $profile;
        }

        $profile->update([$column => $document->valid_until]);

        // A licence number on the certificate is worth carrying across too —
        // it is the thing a roadside check asks for, and retyping it is how it
        // gets mistyped. Only filled when the profile has none; a correction
        // belongs on the profile, not in a document upload.
        if (! $profile->licence_number && $document->document_number) {
            $profile->update(['licence_number' => $document->document_number]);
        }

        Log::channel('stos')->info('Verified licence projected onto the driver', [
            'company_id' => $companyId, 'driver_profile_id' => $profile->id,
            'document_id' => $document->id, 'column' => $column,
            'valid_until' => optional($document->valid_until)->toDateString(),
        ]);

        return $profile->fresh();
    }

    /**
     * Record a verification verdict, and project it if it clears.
     *
     * A seam, not Fleet's feature — the evidence lifecycle and the clerk's
     * screen belong to STOS-DOC. This is the minimum Fleet needs to stop
     * trusting unverified paperwork, and the hook his workflow calls.
     */
    public function verify(int $documentId, int $companyId, string $verdict, ?string $reason = null, $actor = null): array
    {
        $document = $this->documents->find($documentId, $companyId);
        $verdict = strtoupper(trim($verdict));

        if (! in_array($verdict, [TransportDocument::VERIFICATION_VERIFIED, TransportDocument::VERIFICATION_REJECTED], true)) {
            throw new BusinessException('A verification verdict is either VERIFIED or REJECTED.', 422);
        }

        if ($verdict === TransportDocument::VERIFICATION_REJECTED && ! $reason) {
            throw new BusinessException('Say why the document was rejected.', 422);
        }

        $document->update([
            'verification_status' => $verdict,
            'verified_at'         => now(),
            'verified_by'         => $actor?->id,
            'rejection_reason'    => $verdict === TransportDocument::VERIFICATION_REJECTED ? $reason : null,
        ]);

        $document = $document->fresh();

        $profile = $this->project($document, $companyId);

        Log::channel('stos')->info('Driver document verification recorded', [
            'company_id' => $companyId, 'document_id' => $document->id,
            'verdict' => $verdict, 'user_id' => $actor?->id,
            'projected_onto_driver' => $profile?->id,
        ]);

        return [
            'document'   => $document,
            'gate_moved' => $profile !== null,
            'driver'     => $profile,
        ];
    }

    /* ── helpers ────────────────────────────────────────────────── */

    /** Which profile date this document type sets, or null if it sets none. */
    public function gatedBy(string $type): ?string
    {
        return self::GATES_DISPATCH[$type] ?? null;
    }

    /**
     * What a screen may offer.
     *
     * `DRIVER_APPLICABLE` still accepts `fitness` and `permit` so that policies
     * configured before the 2026-09-19 approval keep working and documents
     * already filed under them stay readable. Both read as VEHICLE documents to
     * anyone using a driver screen, so they are accepted on the way in and not
     * offered in the picker.
     */
    private function offerableTypes(): array
    {
        return array_values(array_diff(
            TransportDocumentType::DRIVER_APPLICABLE,
            [TransportDocumentType::FITNESS, TransportDocumentType::PERMIT]
        ));
    }

    private function normaliseType(string $type): string
    {
        $type = strtolower(trim($type));

        if (! in_array($type, TransportDocumentType::DRIVER_APPLICABLE, true)) {
            throw new BusinessException(
                TransportDocumentType::label($type).' cannot be filed against a driver.',
                422
            );
        }

        return $type;
    }

    /**
     * The driver's Fleet profile.
     *
     * Refused rather than created. `DriverService::saveProfile()` is the one
     * place a profile comes into existence, and a document upload quietly
     * making a second one is exactly the duplicate entry point the owner ruled
     * against. The message names the step instead of just refusing.
     */
    private function profile(int $companyId, string $source, int $sourceId): DriverProfile
    {
        $profile = DriverProfile::forCompany($companyId)
            ->where('source', $source)->where('source_id', $sourceId)->first();

        if (! $profile) {
            throw new BusinessException(
                'That person has no driver profile yet. Record their licence details on the drivers '
                .'board first, then file the document against it.',
                404
            );
        }

        return $profile;
    }
}
