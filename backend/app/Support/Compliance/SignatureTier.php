<?php

namespace App\Support\Compliance;

/**
 * The three tiers of the signature approval chain: issuer → manager → head.
 *
 * The issuer's signature is recorded when the checklist is assigned, so the
 * chain always names who put the work into the field, not just who blessed it.
 */
final class SignatureTier
{
    public const ISSUER  = 'issuer';
    public const MANAGER = 'manager';
    public const HEAD    = 'head';

    public const ALL = [self::ISSUER, self::MANAGER, self::HEAD];

    public const LABELS = [
        self::ISSUER  => 'Issuer',
        self::MANAGER => 'Manager',
        self::HEAD    => 'Head',
    ];

    /** What a tier's approval moves the checklist to. */
    public const APPROVES_TO = [
        self::MANAGER => ComplianceStatus::MANAGER_APPROVED,
        self::HEAD    => ComplianceStatus::APPROVED,
    ];

    /** The status a checklist must be in for this tier to act. */
    public const ACTS_ON = [
        self::MANAGER => ComplianceStatus::SUBMITTED,
        self::HEAD    => ComplianceStatus::MANAGER_APPROVED,
    ];

    public const APPROVE = 'approve';
    public const REJECT  = 'reject';

    public static function label(?string $tier): string
    {
        return self::LABELS[$tier] ?? (string) $tier;
    }

    public static function isValid(?string $tier): bool
    {
        return in_array($tier, self::ALL, true);
    }
}
