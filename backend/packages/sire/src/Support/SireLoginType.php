<?php

namespace Sire\Support;

use Sire\Dto\SireUserIdentity;

/**
 * SIRE — the four logical account categories, and which of them reach SIRE.
 *
 * Every CRM has some version of these four, and every CRM calls them something
 * different. SIRE cannot know whether "partner" means a colleague or an outside
 * supplier, so it does not try: the host maps its own role names onto these four
 * in config, and `sire:install` makes an administrator confirm that mapping
 * before writing it.
 *
 *   ADMIN          full SIRE access, subject to capabilities
 *   INTERNAL_USER  engineering access, subject to capabilities
 *   CUSTOMER       NO SIRE access
 *   VENDOR         NO SIRE access
 *
 * WHY CUSTOMER AND VENDOR ARE EXCLUDED BY DEFAULT
 *
 * A SIRE issue says what is broken, how badly, since when, and how far the fix
 * has got. That is exactly the information a customer should hear from an
 * account manager and never read raw, and exactly the information a competitor's
 * supplier should never see at all. Helpdesk is the customer-facing surface;
 * SIRE is the engineering one, and the boundary between them is this class.
 *
 * FAIL CLOSED, AND LOUDLY UNMAPPED
 *
 * A role appearing in NO list gets NO access. That is deliberate: the failure
 * mode of "unmapped means denied" is a colleague who has to ask an administrator
 * for access, and the failure mode of "unmapped means allowed" is a customer
 * reading your defect backlog. Only one of those is recoverable.
 */
final class SireLoginType
{
    public const ADMIN         = 'admin';
    public const INTERNAL_USER = 'internal_user';
    public const CUSTOMER      = 'customer';
    public const VENDOR        = 'vendor';

    public const ALL = [self::ADMIN, self::INTERNAL_USER, self::CUSTOMER, self::VENDOR];

    /** The two that reach SIRE at all. Everything else is denied before capabilities are consulted. */
    public const ENGINEERING = [self::ADMIN, self::INTERNAL_USER];

    public const LABELS = [
        self::ADMIN         => 'Administrator',
        self::INTERNAL_USER => 'Internal user',
        self::CUSTOMER      => 'Customer',
        self::VENDOR        => 'Vendor',
    ];

    /**
     * Which category a host role name belongs to, or null when it is unmapped.
     *
     * Matching is case-insensitive and ignores separators, so 'super_admin',
     * 'Super Admin' and 'super-admin' are the same role. That is not laxity: a
     * mapping that fails because someone typed a hyphen would be blamed on SIRE
     * and fixed by widening the mapping, which is worse.
     */
    public static function forRole(?string $role): ?string
    {
        if ($role === null || $role === '') {
            return null;
        }

        $needle = self::normalise($role);

        foreach (self::ALL as $type) {
            foreach ((array) config("sire.login_types.{$type}", []) as $candidate) {
                if (self::normalise((string) $candidate) === $needle) {
                    return $type;
                }
            }
        }

        return null;
    }

    /**
     * All categories a user belongs to. A host where users hold several roles
     * passes them all; the most permissive wins, because a developer who is also
     * an administrator is still a developer.
     *
     * @param  array<int, string> $roles
     * @return array<int, string>
     */
    public static function forRoles(array $roles): array
    {
        $types = [];

        foreach ($roles as $role) {
            $type = self::forRole((string) $role);
            if ($type !== null) {
                $types[] = $type;
            }
        }

        return array_values(array_unique($types));
    }

    /** May this identity reach SIRE at all? */
    public static function permits(?SireUserIdentity $user): bool
    {
        if ($user === null) {
            return false;
        }

        // An unmapped installation — nobody has configured login_types yet —
        // denies everyone rather than admitting everyone. `sire:doctor` reports
        // this state explicitly so it is a visible task, not a mystery.
        return in_array(self::forRole($user->role), self::ENGINEERING, true);
    }

    /** Whether ANY role has been mapped. Drives the doctor's warning. */
    public static function isConfigured(): bool
    {
        foreach (self::ALL as $type) {
            if ((array) config("sire.login_types.{$type}", []) !== []) {
                return true;
            }
        }

        return false;
    }

    private static function normalise(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($value)) ?? '';
    }
}
