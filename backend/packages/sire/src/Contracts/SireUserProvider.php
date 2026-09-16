<?php

namespace Sire\Contracts;

use Sire\Dto\SireUserIdentity;

/**
 * SIRE SDK — WHO.
 *
 * Split out from the tenant provider deliberately. They answer different
 * questions, fail in different ways, and a host may well have them in different
 * places — tenancy from a subdomain, identity from a session.
 *
 * SIRE NEVER SEES YOUR USER MODEL
 *
 * Everything here returns SireUserIdentity: four fields, all readonly. SIRE has
 * no use for a password hash, a phone number or a preferences blob, and passing
 * a whole User model through SIRE would hand it all of them by default.
 *
 * That matters most at the AI boundary. AiContextSchema allowlists what may
 * leave a tenant; a rich User model sitting behind it is a standing invitation
 * for someone to append `$user->email` to a prompt payload. A four-field
 * readonly object cannot leak a fifth.
 *
 * `lookup()` and `lookupMany()` exist for one reason: rendering names on a
 * timeline and resolving assignment pickers. They MUST be tenant-scoped — an
 * unscoped lookup turns a SIRE issue page into a way to enumerate another
 * tenant's staff.
 */
interface SireUserProvider
{
    /**
     * The authenticated user, or null outside a request (console, scheduler).
     *
     * Null is a normal, expected answer. SIRE's scheduled SLA sweep runs with no
     * user at all, and records its transitions as automatic.
     */
    public function currentUser(): ?SireUserIdentity;

    /**
     * One user by id, within a tenant. Null when absent, deleted, or belonging
     * to another tenant — the three are deliberately indistinguishable.
     */
    public function lookup(int $tenantId, int $userId): ?SireUserIdentity;

    /**
     * Several at once. SIRE renders timelines and assignment lists in bulk;
     * without this, a 200-entry timeline is 200 queries.
     *
     * @param  array<int, int> $userIds
     * @return array<int, SireUserIdentity> keyed by user id, missing ids omitted
     */
    public function lookupMany(int $tenantId, array $userIds): array;

    /**
     * Whether the user can still be assigned work.
     *
     * Return true when the host has no concept of inactive users. SIRE uses this
     * only to keep leavers out of assignment pickers; it never hides history,
     * because a person who left still did the thing the timeline says they did.
     */
    public function isActive(int $tenantId, int $userId): bool;
}
