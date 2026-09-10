<?php

namespace Sire\Dto;

/**
 * SIRE SDK — something that happened, recorded so it cannot be argued with later.
 *
 * SIRE owns the MEANING of an audit event; the host owns where it is stored.
 *
 * WHAT MAKES THIS DIFFERENT FROM A NOTE
 *
 * A note is a person's opinion and its author may edit it. An audit event is a
 * fact and nobody may. There is no `update` and no `delete` anywhere in the audit
 * contract, and no SIRE endpoint edits one — because release approvals and
 * emergency overrides are defended by this trail, and a history that can be
 * rewritten afterwards proves nothing about what happened.
 *
 * The actor is SNAPSHOTTED rather than referenced. A trail that goes blank when
 * someone leaves the company is not a trail.
 */
final class SireAuditEvent
{
    /**
     * @param  string               $action      human-readable, shown on a timeline
     * @param  array<string, mixed> $before      prior values, where meaningful
     * @param  array<string, mixed> $after       new values, where meaningful
     * @param  array<string, mixed> $metadata    action key, from, to, automatic flag
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly string $subjectType,
        public readonly int $subjectId,
        public readonly string $action,
        public readonly ?SireUserIdentity $actor = null,
        public readonly ?string $comment = null,
        public readonly array $before = [],
        public readonly array $after = [],
        public readonly array $metadata = [],
        public readonly ?string $recordedAt = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'tenant_id'    => $this->tenantId,
            'subject_type' => $this->subjectType,
            'subject_id'   => $this->subjectId,
            'action'       => $this->action,
            'actor_id'     => $this->actor?->id,
            'actor_name'   => $this->actor?->displayName,
            'actor_role'   => $this->actor?->role,
            'comment'      => $this->comment,
            'before'       => $this->before,
            'after'        => $this->after,
            'metadata'     => $this->metadata,
            'recorded_at'  => $this->recordedAt,
        ];
    }

    /**
     * The timeline shape SIRE's UI renders.
     *
     * `editable` is hard-coded false and is not a parameter. There is no argument
     * anyone can pass that makes a system event editable.
     *
     * @return array<string, mixed>
     */
    public function toTimelineEntry(string $id): array
    {
        return [
            'id'         => $id,
            'kind'       => 'system',
            'action'     => $this->metadata['action'] ?? null,
            'from'       => $this->metadata['from'] ?? null,
            'to'         => $this->metadata['to'] ?? null,
            'automatic'  => (bool) ($this->metadata['automatic'] ?? false),
            'title'      => $this->action,
            'body'       => $this->comment,
            'actor_name' => $this->actor?->displayName,
            'actor_role' => $this->actor?->role,
            'at'         => $this->recordedAt,
            'editable'   => false,
        ];
    }
}
