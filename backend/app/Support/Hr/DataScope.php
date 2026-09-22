<?php

namespace App\Support\Hr;

/**
 * How WIDE a role's view is — the second half of an authorization answer.
 *
 * Permission and scope are different questions and must not collapse into each
 * other. A permission says "may this person open the leave queue at all"; a
 * scope says "whose leave do they see once they are in". The grid has always
 * answered the first. Nothing answered the second: StaffPermissionService::scope()
 * computes global/own and is read in exactly ONE place — UserResource, the
 * payload sent to the browser — so it decided which menu items rendered and
 * never which rows came back.
 *
 * This is the vocabulary for the server-side half. It is deliberately a short,
 * closed list: every value here has to be answerable from organisational data
 * that actually exists on hr_employees, and a scope nobody can resolve is worse
 * than one that was never offered.
 */
final class DataScope
{
    /** Everything the tenant holds — today's behaviour, and the default. */
    public const GLOBAL = 'global';

    /** The actor's own employee record and nothing else. */
    public const OWN = 'own';

    /** Everybody in the actor's department. */
    public const DEPARTMENT = 'department';

    /** Everybody at the actor's branch. */
    public const BRANCH = 'branch';

    /** The actor plus everyone reporting through them, to any depth. */
    public const TEAM = 'team';

    public const ALL = [
        self::GLOBAL,
        self::OWN,
        self::DEPARTMENT,
        self::BRANCH,
        self::TEAM,
    ];

    public static function isValid(?string $scope): bool
    {
        return $scope !== null && in_array($scope, self::ALL, true);
    }

    /**
     * Anything unrecognised reads as global.
     *
     * A stored value this class does not know — a rename, a hand-edited row, a
     * column added by a later migration — must not silently narrow somebody's
     * view to nothing. Widening on confusion is wrong in a vacuum; here the
     * alternative is locking people out of their job because of a typo, and the
     * permission check has already run by the time scope is asked.
     */
    public static function normalise(?string $scope): string
    {
        return self::isValid($scope) ? $scope : self::GLOBAL;
    }

    /** Whether this scope needs the actor to have an employee record at all. */
    public static function needsEmployee(string $scope): bool
    {
        return $scope !== self::GLOBAL;
    }
}
