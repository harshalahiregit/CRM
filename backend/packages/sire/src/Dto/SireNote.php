<?php

namespace Sire\Dto;

/**
 * SIRE SDK — something a person typed.
 *
 * The mirror image of SireAuditEvent, and the difference is the point: a note has
 * an author who may edit it, so it carries `updatedAt`; an audit event has no
 * edit path at all. SIRE's timeline merges both and tags each `kind` so the UI
 * can keep showing which is which.
 *
 * `internal` defaults to true everywhere in SIRE. An engineering issue discusses
 * defects in a customer's data, and nothing here should reach a customer-visible
 * surface unless somebody deliberately says so.
 *
 * `updatedAt` should be null unless the note was GENUINELY edited — SIRE renders
 * an "edited" marker from its presence, and always populating it marks every
 * comment as edited.
 */
final class SireNote
{
    public function __construct(
        public readonly int|string|null $id,
        public readonly int $tenantId,
        public readonly string $subjectType,
        public readonly int $subjectId,
        public readonly string $body,
        public readonly ?SireUserIdentity $author = null,
        public readonly bool $internal = true,
        public readonly ?string $createdAt = null,
        public readonly ?string $updatedAt = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'] ?? null,
            tenantId: (int) ($data['tenant_id'] ?? 0),
            subjectType: (string) ($data['subject_type'] ?? ''),
            subjectId: (int) ($data['subject_id'] ?? 0),
            body: (string) ($data['body'] ?? ''),
            author: isset($data['author']) && is_array($data['author'])
                ? SireUserIdentity::fromArray($data['author'])
                : null,
            internal: (bool) ($data['internal'] ?? true),
            createdAt: $data['created_at'] ?? null,
            updatedAt: $data['updated_at'] ?? null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'           => $this->id,
            'tenant_id'    => $this->tenantId,
            'subject_type' => $this->subjectType,
            'subject_id'   => $this->subjectId,
            'body'         => $this->body,
            'author'       => $this->author?->toArray(),
            'internal'     => $this->internal,
            'created_at'   => $this->createdAt,
            'updated_at'   => $this->updatedAt,
        ];
    }

    /**
     * @param  SireUserIdentity|null $viewer
     * @param  bool                  $mayModerate whether $viewer holds sire.comment.moderate
     * @return array<string, mixed>
     */
    public function toTimelineEntry(?SireUserIdentity $viewer, bool $mayModerate = false): array
    {
        return [
            'id'         => 'note-'.($this->id ?? 0),
            'note_id'    => $this->id,
            'kind'       => 'comment',
            'title'      => null,
            'body'       => $this->body,
            'actor_name' => $this->author?->displayName,
            'actor_role' => $this->author?->role,
            'at'         => $this->createdAt,
            'edited_at'  => $this->updatedAt,
            'editable'   => $viewer !== null
                && ($this->author?->is($viewer) === true || $mayModerate),
        ];
    }
}
