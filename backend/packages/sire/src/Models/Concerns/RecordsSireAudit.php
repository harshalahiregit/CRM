<?php

namespace Sire\Models\Concerns;

use Sire\Contracts\SireAuditProvider;
use Sire\Dto\SireAuditEvent;
use Sire\Dto\SireUserIdentity;

/**
 * SIRE — the write half of the audit trail.
 *
 * Every status transition, assignment, approval, override, duplicate link and
 * QA result calls recordAudit(). There are around 45 such calls, and they all
 * arrive at one place: the bound SireAuditProvider. Change the binding and the
 * entire trail moves; no service is touched.
 *
 * WHAT IS NOT HERE, ON PURPOSE
 *
 * There is no updateAudit() and no deleteAudit(). Not because nobody needed one
 * yet, but because release approvals and emergency overrides are defended by
 * this trail. A history that can be edited afterwards proves nothing about what
 * happened.
 *
 * Comments are the opposite case and go elsewhere entirely — SireNotesProvider,
 * where the author can edit them. The timeline merges both and tags each entry
 * so the UI can keep showing which is which.
 */
trait RecordsSireAudit
{
    /**
     * @param  string      $action   human-readable, shown on the timeline
     * @param  object|null $actor    null for automatic transitions
     * @param  string|null $comment  free text supplied with the action
     * @param  array       $meta     machine-readable: action key, from, to, flags
     */
    public function recordAudit(
        string $action,
        ?SireUserIdentity $actor = null,
        ?string $comment = null,
        array $meta = [],
        array $before = [],
        array $after = [],
    ): void {
        app(SireAuditProvider::class)->record(new SireAuditEvent(
            tenantId: (int) ($this->tenant_id ?? 0),
            subjectType: static::class,
            subjectId: (int) ($this->id ?? 0),
            action: $action,
            actor: $actor,
            comment: $comment,
            before: $before,
            after: $after,
            metadata: $meta,
            recordedAt: now()->toIso8601String(),
        ));
    }
}
