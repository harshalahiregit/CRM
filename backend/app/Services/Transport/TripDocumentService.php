<?php

namespace App\Services\Transport;

use App\Events\Transport\PodReceived;
use App\Exceptions\BusinessException;
use App\Exceptions\ResourceNotFoundException;
use App\Models\Transport\TransportTrip;
use App\Models\Transport\TripDocument;
use App\Models\User;
use App\Support\Transport\ExceptionStatus;
use App\Support\Transport\TransportDocumentType;
use App\Support\Transport\TripDocumentStatus;
use App\Support\Transport\TripStatus;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * SNG-TRN-014 — POD capture and verification.
 *
 * The acceptance criterion is "POD required before billable state unless
 * approved exception", and STT-008 is the transition it guards:
 *
 *   STT-008 | delivered -> pod_verified | Verify POD | DocumentEngine
 *           | guard "POD valid" | effect "Unlock billing" | audited | LOCKED
 *
 * ── THE FILE IS EVIDENCE, SO IT IS STORED AS IT ARRIVED ──────────────────
 * Not routed through Hr\AttachmentStore, and that is a decision rather than an
 * oversight: that helper re-encodes convertible images to WebP, which is right
 * for an expense receipt and wrong for a POD. A proof of delivery may end up in
 * front of somebody arguing about a consignment, and "we compressed it" is not
 * a sentence anybody wants to say. Bytes in, bytes stored, SHA-256 recorded.
 *
 * Stored on the `local` disk, which is private. CTR-012 asks for "signed
 * upload"; a POD must never be world-readable from a guessable path.
 *
 * ── DUPLICATE UPLOADS ────────────────────────────────────────────────────
 * A unique index on (tenant_id, trip_id, file_hash) means the same bytes cannot
 * be filed twice against one trip, and a retry is absorbed rather than erroring
 * — the same idempotency the cost service gives system sources, for the same
 * reason. A DIFFERENT file is always accepted: multi-drop trips produce several
 * PODs, and a rejected one is followed by a replacement.
 *
 * ── THE WAIVER ARM IS REAL BUT CURRENTLY UNREACHABLE ─────────────────────
 * "unless approved exception" needs `trip_exceptions`, which is DB-010 and
 * belongs to P1. There is no TripException model — SNG-TRN-013 built the schema
 * and the vocabulary, not the model — so this service asks the table a single
 * narrow read-only question rather than creating a model it does not own
 * (TEAM-CONTRACTS §1: "Owner = the only person who writes migrations, models or
 * endpoints for it").
 *
 * That read is a temporary seam, raised as C-08. And the arm cannot fire today
 * regardless: ExceptionStatus::WAIVED is itself declared-but-unreachable,
 * because a waiver needs an authorising role and BLK-10 means no CRM account
 * maps to one. Implemented anyway, because a gate with no bypass would
 * contradict the acceptance criterion the moment waivers become reachable.
 */
class TripDocumentService
{
    /**
     * The shared consignment timeline — STOS-CTD §31/§32, P1's recorder.
     *
     * Injected rather than resolved inline so it is visible in the constructor
     * that this service writes to a table it does not own. The recorder never
     * throws into its caller: a POD that was filed must not be un-filed because
     * its timeline row failed.
     */
    public function __construct(private TripEventRecorder $events)
    {
    }

    /**
     * CTR-012's "allowed MIME/size", made specific.
     *
     * The registry says "allowed MIME/size" and names neither. These are
     * constructed: a POD is a photograph or a scan, so images and PDF, and
     * nothing that executes. Recorded with D-59 rather than presented as spec.
     *
     * @var list<string>
     */
    public const ALLOWED_MIME = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/heic',
        'image/heif',
    ];

    /** 10 MB. A phone photo of a signed sheet, with room to spare. */
    public const MAX_BYTES = 10 * 1024 * 1024;

    /** Where PODs live on the private disk. */
    private const FOLDER = 'transport/trip-documents';

    /* ── Reading ──────────────────────────────────────────────────────── */

    public function find(int $id, int $tenantId): TripDocument
    {
        $document = TripDocument::forTenant($tenantId)->find($id);

        if (! $document) {
            throw new ResourceNotFoundException('Trip document');
        }

        return $document;
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, TripDocument> */
    public function forTrip(int $tripId, int $tenantId)
    {
        return TripDocument::forTenant($tenantId)
            ->forTrip($tripId)
            ->orderByDesc('id')
            ->get();
    }

    /* ── The gate SNG-TRN-015 will ask ────────────────────────────────── */

    /**
     * May this trip be billed?
     *
     * 014's acceptance criterion, expressed as one question so SNG-TRN-015 has
     * something to call rather than reimplementing the rule. Returns the reason
     * as well as the verdict, because a screen that can only say "no" sends
     * somebody hunting.
     *
     * @return array{billable: bool, reason: string, has_verified_pod: bool, waived: bool}
     */
    public function billingReadiness(TransportTrip $trip, int $tenantId): array
    {
        $hasPod = TripDocument::forTenant($tenantId)
            ->forTrip($trip->id)
            ->ofType(TransportDocumentType::POD)
            ->verified()
            ->exists();

        if ($hasPod) {
            return [
                'billable' => true, 'reason' => 'A verified POD is on file.',
                'has_verified_pod' => true, 'waived' => false,
            ];
        }

        $waived = $this->hasWaivedException($trip->id, $tenantId);

        if ($waived) {
            return [
                'billable' => true, 'reason' => 'POD requirement waived by an approved exception.',
                'has_verified_pod' => false, 'waived' => true,
            ];
        }

        return [
            'billable' => false,
            'reason'   => 'This trip has no verified POD and no approved exception waiving one.',
            'has_verified_pod' => false, 'waived' => false,
        ];
    }

    /**
     * Is there a waived exception on this trip?
     *
     * A single read against P1's table — see the class docblock for why this is
     * a direct query rather than a model, and why it is raised as C-08.
     * Deliberately narrow: it asks one boolean and reads no other column, so
     * when P1 exposes a service this is one line to replace.
     */
    private function hasWaivedException(int $tripId, int $tenantId): bool
    {
        if (! DB::getSchemaBuilder()->hasTable('trip_exceptions')) {
            return false;
        }

        // No deleted_at filter: `trip_exceptions` deliberately has no soft
        // deletes. Its own migration records why — "it must never disappear
        // from the system". Filtering on a column that does not exist would
        // take the billing gate down with a SQL error.
        return DB::table('trip_exceptions')
            ->where('tenant_id', $tenantId)
            ->where('trip_id', $tripId)
            ->where('status', ExceptionStatus::WAIVED)
            ->exists();
    }

    /* ── Writing ──────────────────────────────────────────────────────── */

    /**
     * File a document against a trip — API-008 for the POD case.
     *
     * @param  array{document_type?:string,notes?:string|null}  $data
     */
    public function file(
        TransportTrip $trip,
        UploadedFile $file,
        array $data,
        int $tenantId,
        ?User $actor = null,
    ): TripDocument {
        $this->assertTripBelongsToTenant($trip, $tenantId);

        $type = $this->assertDocumentType($data['document_type'] ?? TransportDocumentType::POD);
        $this->assertFileAcceptable($file);

        // Hashed BEFORE storing. If the same bytes are already on this trip the
        // upload is a retry, and writing a second copy to disk then deleting it
        // is work nobody needs.
        $hash = hash_file('sha256', $file->getRealPath());

        $existing = TripDocument::forTenant($tenantId)
            ->forTrip($trip->id)
            ->where('file_hash', $hash)
            ->first();

        if ($existing) {
            Log::channel('transport')->info('Trip document re-uploaded, ignored', [
                'document_id' => $existing->id, 'trip_id' => $trip->id, 'tenant_id' => $tenantId,
            ]);

            return $existing;
        }

        $path = $file->store(self::FOLDER, 'local');

        if ($path === false) {
            throw new BusinessException('The document could not be stored. Please try again.');
        }

        try {
            $document = DB::transaction(function () use ($trip, $file, $path, $hash, $type, $data, $tenantId, $actor) {
                $document = TripDocument::create([
                    'tenant_id'     => $tenantId,
                    'trip_id'       => $trip->id,
                    'document_type' => $type,
                    'file_path'     => $path,
                    'file_name'     => $file->getClientOriginalName(),
                    'file_mime'     => $file->getClientMimeType(),
                    'file_size'     => (int) $file->getSize(),
                    'file_hash'     => $hash,
                    'status'        => TripDocumentStatus::INITIAL,
                    'uploaded_by'   => $actor?->id,
                    'notes'         => $data['notes'] ?? null,
                    'created_by'    => $actor?->id,
                ]);

                $document->audit('transport.trip_document.filed', $actor, new: [
                    'trip_id'       => $trip->id,
                    'document_type' => $type,
                    'file_hash'     => $hash,
                    'status'        => TripDocumentStatus::INITIAL,
                ]);

                return $document;
            });
        } catch (QueryException $e) {
            // The unique index caught a concurrent duplicate that the read above
            // missed. Clean up the orphan we just wrote and return the winner.
            Storage::disk('local')->delete($path);

            $winner = TripDocument::forTenant($tenantId)->forTrip($trip->id)
                ->where('file_hash', $hash)->first();

            if ($winner) {
                return $winner;
            }

            throw $e;
        }

        // EVT-009. Emitted on RECEIPT, not on verification — see the event class.
        if ($type === TransportDocumentType::POD) {
            PodReceived::dispatch($document);
        }

        // CTD §31's timeline. Two different lines, because the Passport reads
        // as a story and "POD uploaded" at 17:00 is a different moment from
        // "Documents handed over" at 10:05 — the LR and e-way bill that travel
        // WITH the load, handed over before it leaves.
        $this->events->record(
            type: $type === TransportDocumentType::POD ? 'pod.uploaded' : 'documents.handed_over',
            trip: $trip,
            actor: $actor,
            detail: ['document_type' => $type, 'file_name' => $document->file_name],
            occurredAt: $document->created_at,
        );

        Log::channel('transport')->info('Trip document filed', [
            'document_id' => $document->id, 'trip_id' => $trip->id, 'type' => $type,
            'tenant_id' => $tenantId, 'user_id' => $actor?->id,
        ]);

        return $document->fresh();
    }

    /**
     * STT-008 — verify a POD and unlock billing.
     *
     * The transition on the TRIP is part of the same act, not a separate call:
     * STT-008's side effect is "Unlock billing", and a verification that left
     * the trip in `delivered` would satisfy the document and not the machine.
     */
    public function verify(TripDocument $document, int $tenantId, ?User $actor = null): TripDocument
    {
        $this->assertDocumentBelongsToTenant($document, $tenantId);
        $this->assertDecidable($document);

        return DB::transaction(function () use ($document, $actor, $tenantId) {
            $document->forceFill([
                'status'      => TripDocumentStatus::VERIFIED,
                'verified_by' => $actor?->id,
                'verified_at' => now(),
                'updated_by'  => $actor?->id,
            ])->save();

            $document->auditTransition(
                'transport.trip_document.verified',
                TripDocumentStatus::RECEIVED,
                TripDocumentStatus::VERIFIED,
                $actor,
            );

            $this->advanceTripOnVerification($document, $actor, $tenantId);

            // Derived from STT-008 rather than quoted from CTD §31, which lists
            // only the upload. Verification is the moment billing unlocks, so a
            // timeline that showed the upload and not the decision would leave
            // the reader unable to see why the next step became possible.
            $this->events->record(
                type: 'pod.verified',
                trip: $document->trip()->first(),
                actor: $actor,
                detail: ['document_id' => $document->id, 'file_hash' => $document->file_hash],
            );

            Log::channel('transport')->info('Trip document verified', [
                'document_id' => $document->id, 'trip_id' => $document->trip_id,
                'tenant_id' => $tenantId, 'user_id' => $actor?->id,
            ]);

            return $document->fresh();
        });
    }

    /** A refusal that cannot say why is not reviewable. */
    public function reject(TripDocument $document, string $reason, int $tenantId, ?User $actor = null): TripDocument
    {
        $this->assertDocumentBelongsToTenant($document, $tenantId);
        $this->assertDecidable($document);

        if (trim($reason) === '') {
            throw new BusinessException('A reason is required to reject a document.');
        }

        $document->forceFill([
            'status'           => TripDocumentStatus::REJECTED,
            'verified_by'      => $actor?->id,
            'verified_at'      => now(),
            'rejection_reason' => trim($reason),
            'updated_by'       => $actor?->id,
        ])->save();

        $document->auditTransition(
            'transport.trip_document.rejected',
            TripDocumentStatus::RECEIVED,
            TripDocumentStatus::REJECTED,
            $actor,
            ['reason' => trim($reason)],
        );

        Log::channel('transport')->info('Trip document rejected', [
            'document_id' => $document->id, 'trip_id' => $document->trip_id,
            'reason' => trim($reason), 'tenant_id' => $tenantId, 'user_id' => $actor?->id,
        ]);

        return $document->fresh();
    }

    /* ── Rules ────────────────────────────────────────────────────────── */

    /**
     * Move the trip delivered -> pod_verified, if it is standing there.
     *
     * Conditional rather than mandatory, and that is the honest shape today.
     * STT-006 (dispatched -> in_transit) is NOT wired — it is the Transit half
     * of SNG-TRN-013 and belongs to P1 — so no trip can currently REACH
     * `delivered`. Verifying a POD on a trip in any other state therefore
     * records the document and leaves the trip alone, rather than throwing over
     * a gap that is not the verifier's fault.
     *
     * The moment P1 wires STT-006 and STT-007, this edge starts firing with no
     * change here.
     */
    private function advanceTripOnVerification(TripDocument $document, ?User $actor, int $tenantId): void
    {
        if ($document->document_type !== TransportDocumentType::POD) {
            return;
        }

        $trip = TransportTrip::forTenant($tenantId)->find($document->trip_id);

        if (! $trip || $trip->status !== TripStatus::DELIVERED) {
            return;
        }

        $from = (string) $trip->status;
        $trip->forceFill(['status' => TripStatus::POD_VERIFIED, 'updated_by' => $actor?->id])->save();

        $trip->auditTransition('transport.trip.pod_verified', $from, TripStatus::POD_VERIFIED, $actor);
    }

    private function assertTripBelongsToTenant(TransportTrip $trip, int $tenantId): void
    {
        // "Not found", never "forbidden" — a trip in another tenant must not be
        // distinguishable from one that does not exist.
        if ((int) $trip->tenant_id !== $tenantId) {
            throw new ResourceNotFoundException('Trip');
        }
    }

    private function assertDocumentBelongsToTenant(TripDocument $document, int $tenantId): void
    {
        if ((int) $document->tenant_id !== $tenantId) {
            throw new ResourceNotFoundException('Trip document');
        }
    }

    /** CTR-012 — a decided document is closed to further decisions. */
    private function assertDecidable(TripDocument $document): void
    {
        if ($document->isDecided()) {
            throw new BusinessException(
                'This document has already been '.TripDocumentStatus::label((string) $document->status)
                .'. File a replacement rather than changing the decision.'
            );
        }
    }

    private function assertDocumentType(string $type): string
    {
        // ENUM-006, reused rather than re-declared. `forEntity` is not consulted
        // because no TransportDocumentEntity value describes a trip — that
        // vocabulary belongs to DB-019, a different table. See D-59.
        if (! in_array($type, TransportDocumentType::ALL, true)) {
            throw new BusinessException('Unknown document type.');
        }

        return $type;
    }

    private function assertFileAcceptable(UploadedFile $file): void
    {
        if (! $file->isValid()) {
            throw new BusinessException('The upload did not complete. Please try again.');
        }

        if ((int) $file->getSize() > self::MAX_BYTES) {
            throw new BusinessException(
                'A document may be at most '.(self::MAX_BYTES / 1024 / 1024).' MB.'
            );
        }

        // getMimeType() reads the file's own bytes; getClientMimeType() trusts
        // the browser. Checking the former is the difference between a MIME
        // check and a suggestion.
        if (! in_array($file->getMimeType(), self::ALLOWED_MIME, true)) {
            throw new BusinessException('A document must be a PDF or an image.');
        }
    }
}
