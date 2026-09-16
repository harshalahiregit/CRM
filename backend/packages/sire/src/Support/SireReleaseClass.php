<?php

namespace Sire\Support;

/**
 * SIRE — what an issue contributes to a release.
 *
 * Distinct from `workflow_track` (how it was worked) and from the tenant's issue
 * category (what kind of thing it is). This is the release-notes taxonomy, and it
 * is the one the brief names: bugs, changes, improvements, security fixes,
 * performance fixes.
 *
 * Resolution order, most specific first:
 *   1. sire_reports.release_class            per-issue override
 *   2. sire_report_categories.release_class  the tenant's mapping for that type
 *   3. derived from workflow_track           change → CHANGE, otherwise BUG
 *
 * SECURITY is deliberately its own class rather than a flavour of BUG, because it
 * is treated differently downstream: user-facing release notes summarise security
 * fixes without describing the vulnerability.
 */
final class SireReleaseClass
{
    public const BUG         = 'bug';
    public const CHANGE      = 'change';
    public const IMPROVEMENT = 'improvement';
    public const SECURITY    = 'security';
    public const PERFORMANCE = 'performance';

    public const ALL = [self::BUG, self::CHANGE, self::IMPROVEMENT, self::SECURITY, self::PERFORMANCE];

    public const LABELS = [
        self::BUG         => 'Bug fixes',
        self::CHANGE      => 'Changes',
        self::IMPROVEMENT => 'Improvements',
        self::SECURITY    => 'Security fixes',
        self::PERFORMANCE => 'Performance fixes',
    ];

    /** Order the classes appear in release notes. Security first: it is why people read them. */
    public const NOTE_ORDER = [self::SECURITY, self::BUG, self::CHANGE, self::IMPROVEMENT, self::PERFORMANCE];

    /**
     * Classes whose detail is withheld from USER-facing notes.
     *
     * A public note saying "fixed an unauthenticated file-read in the attachment
     * download endpoint" is a working exploit for anyone who has not patched yet.
     * The count is published; the description is not.
     */
    public const REDACTED_FOR_USERS = [self::SECURITY];

    public static function label(?string $class): string
    {
        return self::LABELS[$class] ?? self::LABELS[self::BUG];
    }

    public static function isValid(?string $class): bool
    {
        return $class !== null && in_array($class, self::ALL, true);
    }
}
