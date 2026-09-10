<?php

namespace Sire\Contracts;

use Sire\Dto\SireNote;
use Sire\Dto\SireUserIdentity;

/**
 * SIRE SDK — WHAT PEOPLE SAID.
 *
 * Human commentary on an issue. A host with an existing notes system implements
 * this and SIRE comments behave exactly like comments everywhere else in the
 * product — same mentions, same moderation, same visibility rules. A host
 * without one leaves it on SIRE's local provider, which keeps its own table.
 *
 * COMMENTS ARE EDITABLE; AUDIT EVENTS ARE NOT
 *
 * That single difference is why this interface has update() and delete() and
 * SireAuditProvider has neither. A comment is a person's opinion and its author
 * may revise it. An audit event is a fact.
 *
 * WHY find() CARRIES THE SUBJECT
 *
 * Note ids are a global sequence. SIRE checks that the note id in a URL actually
 * belongs to the report in that same URL before touching it — without
 * subjectType/subjectId it cannot, and `/sire/reports/1/comments/999` would
 * happily edit a note attached to an invoice in another tenant.
 */
interface SireNotesProvider
{
    public function add(object $subject, string $body, SireUserIdentity $author, bool $internal = true): SireNote;

    /**
     * Comments on one record, newest first.
     *
     * Implementations MUST filter by what $viewer is allowed to see.
     *
     * @return array<int, SireNote>
     */
    public function listFor(object $subject, ?SireUserIdentity $viewer = null, int $limit = 200): array;

    /** Null when absent or belonging to another tenant — indistinguishable, deliberately. */
    public function find(int|string $noteId): ?SireNote;

    /**
     * Edit an existing comment.
     *
     * Authorship and any time-window rules are the host's to enforce. SIRE
     * checks only that the editor is the author or holds sire.comment.moderate.
     */
    public function update(int|string $noteId, string $body, SireUserIdentity $user): SireNote;

    public function delete(int|string $noteId, SireUserIdentity $user): void;
}
