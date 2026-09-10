<?php

namespace Sire\Contracts;

use Sire\Dto\SireUserIdentity;

/**
 * SIRE SDK — MAY THEY.
 *
 * SIRE owns the capability vocabulary (see SireCapability). The host owns who
 * holds what.
 *
 * WHAT THIS PROVIDER DOES NOT DECIDE
 *
 * Whether a developer may push their own issue to QA, or a lead may close
 * someone else's, is a PRODUCT rule and lives in SireAccessService. This
 * provider answers only two much narrower questions: has the host granted this
 * capability, and who is in this role.
 *
 * Keeping that line sharp matters. A provider that starts making product
 * decisions creates two authorization systems that can disagree, and the one
 * behind the integration seam is the one nobody reads during a review.
 *
 * FAILS CLOSED, ALWAYS
 *
 * `can()` returns false for anything it does not recognise and never throws. A
 * half-wired permission system must deny, not permit. Returning false for
 * EVERYTHING is a valid working state: SireAccessService falls through to SIRE's
 * own role rules, so SIRE stays usable while a host maps its permissions.
 */
interface SireAuthorizationProvider
{
    /**
     * Has the host granted $capability to $user?
     *
     * @param  string      $capability a SireCapability::CANONICAL or ::COARSE name
     * @param  object|null $subject    the SIRE record in scope, for hosts with
     *                                 record-level rules; ignore it otherwise
     */
    public function can(SireUserIdentity $user, string $capability, ?object $subject = null): bool;

    /**
     * User ids holding a named SIRE role: 'leads', 'developers', 'qa',
     * 'release_managers'.
     *
     * Drives assignment pickers and notification fan-out — never authorization
     * itself. Membership is a convenience list; can() is the gate.
     *
     * MUST be tenant-scoped: an unscoped roster puts another tenant's staff into
     * a dropdown.
     *
     * @return array<int, int>
     */
    public function roster(int $tenantId, string $roster): array;
}
