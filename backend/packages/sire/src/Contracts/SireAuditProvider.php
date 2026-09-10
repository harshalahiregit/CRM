<?php

namespace Sire\Contracts;

use Sire\Dto\SireAuditEvent;

/**
 * SIRE SDK — WHAT HAPPENED.
 *
 * SIRE owns the meaning of an audit event; the host owns where it lives. A host
 * with its own audit trail implements this and SIRE's history appears alongside
 * everything else in the product. A host without one leaves it on SIRE's local
 * provider, which keeps its own table — SIRE is never without a trail.
 *
 * THE INVARIANT: THERE IS NO UPDATE AND NO DELETE
 *
 * Not an oversight, and not something to add later. Release approvals and
 * emergency overrides are defended by this trail; a history that can be
 * rewritten afterwards proves nothing about what happened. No SIRE endpoint
 * edits a recorded event either, and a test asserts that no such route exists.
 *
 * User comments are the opposite case and go through SireNotesProvider, where
 * the author may edit them. SIRE's timeline merges both and tags each entry
 * `kind: system|comment` so the difference stays visible on screen.
 *
 * WRITES MUST NOT THROW. Audit is evidence, not flow control: a transition that
 * succeeded with a failed audit write beats a transition that could not happen.
 */
interface SireAuditProvider
{
    /** @throws never — implementations MUST catch and report internally */
    public function record(SireAuditEvent $event): void;

    /**
     * @param  array<int, SireAuditEvent> $events
     */
    public function recordMany(array $events): void;

    /**
     * History for one record, newest first.
     *
     * MUST be scoped by tenant as well as subject. Subject type plus id is
     * already unique; the tenant filter is defence in depth and costs one
     * indexed column.
     *
     * @return array<int, SireAuditEvent>
     */
    public function for(string $subjectType, int $subjectId, int $tenantId, int $limit = 200): array;
}
