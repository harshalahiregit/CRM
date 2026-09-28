<?php

namespace Sire\Discovery;

use Sire\Support\SireLoginType;

/**
 * SIRE — proposes which of the four login types a host role name belongs to.
 *
 * Extracted from RoleDetector so it can be TESTED. The rule it encodes decides
 * who can read an organisation's defect backlog, and a rule that important
 * should not be a private method reachable only through a database connection.
 *
 * IT PROPOSES. IT NEVER DECIDES.
 *
 * `super_admin` is obviously ADMIN. `partner` might be a colleague or a
 * competitor's supplier, and no amount of string matching will tell you which.
 * So this returns a suggestion, the installer shows it to a human, and the human
 * confirms it before anything is written.
 *
 * LONGEST MATCH WINS
 *
 * 'super_admin' contains both 'admin' and 'superadmin'. Matching the longest
 * hint means it lands in ADMIN rather than depending on iteration order — and
 * order-dependent security rules are how a refactor becomes an incident.
 *
 * VENDOR AND CUSTOMER ARE CHECKED FIRST
 *
 * 'support_partner' contains 'support' (internal) and 'partner' (vendor). The
 * external reading is the safe one: mistaking a colleague for a supplier costs
 * them an access request, while the reverse shows an outsider the backlog.
 */
final class RoleClassifier
{
    /** Substring hints per category. Deliberately conservative. */
    public const HINTS = [
        SireLoginType::ADMIN => ['superadmin', 'administrator', 'admin', 'owner', 'root'],
        SireLoginType::INTERNAL_USER => [
            'staff', 'employee', 'developer', 'engineer', 'qa', 'tester', 'internal',
            'agent', 'support', 'manager', 'lead', 'analyst', 'operator', 'technician',
        ],
        SireLoginType::CUSTOMER => ['customer', 'client', 'subscriber', 'portal'],
        SireLoginType::VENDOR   => ['vendor', 'supplier', 'contractor', 'partner', 'thirdparty'],
    ];

    /** Checked in this order; see the note above on why external comes first. */
    private const ORDER = [
        SireLoginType::VENDOR,
        SireLoginType::CUSTOMER,
        SireLoginType::ADMIN,
        SireLoginType::INTERNAL_USER,
    ];

    public function classify(string $role): ?string
    {
        $needle = self::normalise($role);

        if ($needle === '') {
            return null;
        }

        $best = null;
        $bestLength = 0;

        foreach (self::ORDER as $type) {
            foreach (self::HINTS[$type] as $hint) {
                if (str_contains($needle, $hint) && strlen($hint) > $bestLength) {
                    $best = $type;
                    $bestLength = strlen($hint);
                }
            }
        }

        return $best;
    }

    /**
     * Classify a whole set.
     *
     * @param  array<int, string> $roles
     * @return array{admin: array, internal_user: array, customer: array, vendor: array, unclassified: array}
     */
    public function classifyAll(array $roles): array
    {
        $out = array_fill_keys(SireLoginType::ALL, []);
        $out['unclassified'] = [];

        foreach ($roles as $role) {
            $type = $this->classify((string) $role);

            // Unclassified is not a failure mode to hide. Those roles get NO
            // SIRE access, and the installer must say so out loud rather than
            // let someone discover it when a colleague cannot log in.
            $out[$type ?? 'unclassified'][] = $role;
        }

        return $out;
    }

    /** Case- and separator-insensitive: 'Super Admin', 'super-admin', 'super_admin' are one role. */
    private static function normalise(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($value)) ?? '';
    }
}
