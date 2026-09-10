<?php

namespace App\Services\Transport;

use App\Exceptions\BusinessException;
use App\Exceptions\ResourceNotFoundException;
use App\Models\Transport\TransportDocument;
use App\Models\User;
use App\Support\Transport\TransportDocumentEntity;
use App\Support\Transport\TransportDocumentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Filing and superseding compliance documents — DB-019.
 *
 * The third part of gap G-1: TransportDocument carried the audit trait with no
 * caller. It gets its own service rather than living inside the vehicle and
 * driver services, because a document's rules are the document's own — STOS-DOC
 * §26's versioning in particular — and duplicating them across two services is
 * how the two copies drift apart.
 *
 * ── §26 IS THE POINT ──────────────────────────────────────────────────────
 * "When a document is replaced: do not silently overwrite the previous version.
 *  Maintain Version 1, Version 2, Version 3 with history."
 * So renew() supersedes rather than updates. What was on file when a trip was
 * allocated last month stays readable, which is what makes an allocation audit
 * worth keeping.
 */
class TransportDocumentService
{
    private const EDITABLE = [
        'document_number', 'issued_on', 'valid_from', 'valid_until',
        'file_path', 'file_name', 'file_hash', 'source', 'notes',
    ];

    public function find(int $id, int $tenantId): TransportDocument
    {
        $document = TransportDocument::forTenant($tenantId)->find($id);

        if (! $document) {
            throw new ResourceNotFoundException('Document');
        }

        return $document;
    }

    /**
     * File a document against a vehicle or driver.
     *
     * The subject is passed as a model rather than as ids, so the tenant and the
     * entity type are both taken from a record the caller already resolved
     * through forTenant() — a caller cannot file a document against another
     * tenant's vehicle by passing a bare id.
     *
     * @param array<string,mixed> $data
     */
    public function file(Model $subject, string $documentType, array $data, int $tenantId, ?User $actor = null): TransportDocument
    {
        $entityType = $this->entityTypeFor($subject);

        if ((int) $subject->tenant_id !== $tenantId) {
            throw new ResourceNotFoundException(ucfirst($entityType));
        }

        if (! TransportDocumentType::isValid($documentType)) {
            throw new BusinessException('Unknown document type.', 422);
        }

        if (! in_array($documentType, TransportDocumentType::forEntity($entityType), true)) {
            throw new BusinessException(
                TransportDocumentType::label($documentType).' cannot be filed against a '.$entityType.'.',
                422
            );
        }

        return DB::transaction(function () use ($subject, $entityType, $documentType, $data, $tenantId, $actor) {
            /** @var TransportDocument $document */
            $document = TransportDocument::create(array_merge(
                array_intersect_key($data, array_flip(self::EDITABLE)),
                [
                    'tenant_id'     => $tenantId,
                    'entity_type'   => $entityType,
                    'entity_id'     => $subject->id,
                    'document_type' => $documentType,
                    'version'       => TransportDocument::nextVersion($tenantId, $entityType, (int) $subject->id, $documentType),
                    'created_by'    => $actor?->id,
                    'updated_by'    => $actor?->id,
                ],
            ));

            $document->audit('transport.document.filed', $actor, new: [
                'entity_type'   => $entityType,
                'entity_id'     => $subject->id,
                'document_type' => $documentType,
                'version'       => $document->version,
                'valid_until'   => $document->valid_until?->toDateString(),
            ]);

            Log::channel('transport')->info('Transport document filed', [
                'document_id' => $document->id, 'entity_type' => $entityType,
                'entity_id' => $subject->id, 'document_type' => $documentType,
                'tenant_id' => $tenantId, 'user_id' => $actor?->id,
            ]);

            return $document;
        });
    }

    /**
     * Replace a document with a new version — STOS-DOC §26.
     *
     * The old row is marked superseded, never edited away, and both acts are
     * audited so the trail shows what replaced what.
     *
     * @param array<string,mixed> $data
     */
    public function renew(TransportDocument $current, array $data, int $tenantId, ?User $actor = null): TransportDocument
    {
        $this->assertTenant($current, $tenantId);

        if ($current->status !== TransportDocument::STATUS_ACTIVE) {
            throw new BusinessException('Only the active version of a document can be renewed.', 422);
        }

        return DB::transaction(function () use ($current, $data, $tenantId, $actor) {
            $current->forceFill([
                'status' => TransportDocument::STATUS_SUPERSEDED,
                'updated_by' => $actor?->id,
            ])->save();

            /** @var TransportDocument $next */
            $next = TransportDocument::create(array_merge(
                array_intersect_key($data, array_flip(self::EDITABLE)),
                [
                    'tenant_id'     => $tenantId,
                    'entity_type'   => $current->entity_type,
                    'entity_id'     => $current->entity_id,
                    'document_type' => $current->document_type,
                    'version'       => TransportDocument::nextVersion(
                        $tenantId, $current->entity_type, (int) $current->entity_id, $current->document_type
                    ),
                    'created_by'    => $actor?->id,
                    'updated_by'    => $actor?->id,
                ],
            ));

            $current->audit('transport.document.superseded', $actor,
                old: ['status' => TransportDocument::STATUS_ACTIVE, 'version' => $current->version],
                new: ['status' => TransportDocument::STATUS_SUPERSEDED, 'superseded_by_version' => $next->version],
            );

            $next->audit('transport.document.renewed', $actor, new: [
                'document_type'  => $next->document_type,
                'version'        => $next->version,
                'valid_until'    => $next->valid_until?->toDateString(),
                'replaces_version' => $current->version,
            ]);

            Log::channel('transport')->info('Transport document renewed', [
                'previous_id' => $current->id, 'document_id' => $next->id,
                'version' => $next->version, 'tenant_id' => $tenantId, 'user_id' => $actor?->id,
            ]);

            return $next;
        });
    }

    /**
     * Correct a document in place.
     *
     * For fixing a mistyped policy number, NOT for replacing an expired
     * certificate — that is renew(). Kept separate so a correction can never be
     * mistaken for a renewal in the audit trail.
     *
     * @param array<string,mixed> $data
     */
    public function correct(TransportDocument $document, array $data, int $tenantId, ?User $actor = null): TransportDocument
    {
        $this->assertTenant($document, $tenantId);

        $before = $document->only(self::EDITABLE);

        $document->fill(array_merge(
            array_intersect_key($data, array_flip(self::EDITABLE)),
            ['updated_by' => $actor?->id],
        ))->save();

        $after = $document->only(self::EDITABLE);
        $norm = fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : (is_scalar($v) || $v === null ? $v : json_encode($v));
        $changed = array_keys(array_diff_assoc(array_map($norm, $after), array_map($norm, $before)));

        if ($changed !== []) {
            $document->audit(
                'transport.document.corrected',
                $actor,
                old: array_map($norm, array_intersect_key($before, array_flip($changed))),
                new: array_map($norm, array_intersect_key($after, array_flip($changed))),
            );
        }

        return $document->fresh();
    }

    /** Audit first, then delete. */
    public function delete(TransportDocument $document, int $tenantId, ?User $actor = null, ?string $reason = null): void
    {
        $this->assertTenant($document, $tenantId);

        DB::transaction(function () use ($document, $actor, $reason) {
            $document->audit(
                'transport.document.deleted',
                $actor,
                old: $document->only(['entity_type', 'entity_id', 'document_type', 'version', 'valid_until']),
                context: array_filter(['reason' => $reason]),
            );

            $document->delete();
        });

        Log::channel('transport')->warning('Transport document deleted', [
            'document_id' => $document->id, 'tenant_id' => $tenantId, 'user_id' => $actor?->id,
        ]);
    }

    private function entityTypeFor(Model $subject): string
    {
        return match (true) {
            $subject instanceof \App\Models\Transport\TransportVehicle => TransportDocumentEntity::VEHICLE,
            $subject instanceof \App\Models\Transport\TransportDriver  => TransportDocumentEntity::DRIVER,
            default => throw new BusinessException('Documents cannot be filed against that record.', 422),
        };
    }

    private function assertTenant(TransportDocument $document, int $tenantId): void
    {
        if ((int) $document->tenant_id !== $tenantId) {
            Log::channel('transport')->warning('Document tenant mismatch', [
                'document_id' => $document->id, 'tenant_id' => $tenantId,
            ]);

            throw new ResourceNotFoundException('Document');
        }
    }
}
