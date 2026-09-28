<?php

namespace Sire\Adapters\Defaults;

use Sire\Contracts\SireAuthorizationProvider;
use Sire\Contracts\SireSettingsProvider;
use Sire\Support\SireCapability;
use Sire\Dto\SireUserIdentity;
use Illuminate\Support\Facades\Gate;

/**
 * The shipped authorization provider: Laravel's Gate, plus settings-backed rosters.
 *
 * TWO NARROW QUESTIONS, AND NOTHING ELSE
 *
 *   can()     has the host explicitly granted this capability?
 *   roster()  who is in this SIRE role?
 *
 * It does NOT decide whether a developer may move their own issue to QA. That is
 * a product rule and lives in SireAccessService, which combines these answers
 * with the state of the report in front of it. A provider that started making
 * product decisions would create two authorization systems that can disagree,
 * and the one behind the integration seam is the one nobody reads.
 *
 * RETURNING FALSE FOR EVERYTHING IS A VALID WORKING STATE. With no Gate abilities
 * defined, SireAccessService falls through to SIRE's own role rules and SIRE is
 * fully usable — which is what lets a host install SIRE before mapping a single
 * permission.
 *
 * Coarse grants are expanded here, so a host that grants `sire.manage` through a
 * Gate satisfies every canonical capability beneath it.
 */
class SireLocalAuthorizationProvider implements SireAuthorizationProvider
{
    public function __construct(private readonly SireSettingsProvider $settings)
    {
    }

    public function can(SireUserIdentity $user, string $capability, ?object $subject = null): bool
    {
        // An unknown capability is never granted. Fail closed, always.
        if (! SireCapability::isKnown($capability)) {
            return false;
        }

        if ($this->gateAllows($user, $capability, $subject)) {
            return true;
        }

        // A coarse grant satisfies every canonical capability beneath it, so a
        // host that mapped only 'sire.manage' still works.
        foreach (SireCapability::COARSE as $coarse => $expanded) {
            if (in_array($capability, $expanded, true) && $this->gateAllows($user, $coarse, $subject)) {
                return true;
            }
        }

        return false;
    }

    public function roster(int $tenantId, string $roster): array
    {
        $ids = $this->settings->get($tenantId, "sire.roles.{$roster}", []);

        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', array_filter($ids, 'is_numeric'))));
    }

    private function gateAllows(SireUserIdentity $user, string $ability, ?object $subject): bool
    {
        // Gate::has() first. Asking Gate about an undefined ability returns false
        // anyway, but going straight to allows() would also run any registered
        // `before` callback -- and a super-admin before-hook would then silently
        // grant every SIRE capability to everyone it covers.
        if (! Gate::has($ability)) {
            return false;
        }

        try {
            return Gate::forUser($user)->allows($ability, $subject);
        } catch (\Throwable $e) {
            // A host Gate written against its own User model may not accept a
            // SireUserIdentity. That is a mapping problem, not a grant: report it
            // and deny.
            report($e);

            return false;
        }
    }
}
