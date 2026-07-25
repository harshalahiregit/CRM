<?php

namespace App\Support\Compliance;

/**
 * A template's publication state.
 *
 * Draft → Active → Archived. Only Active templates may be issued; Archived ones
 * stay readable so historic checklists still render the questions they were
 * actually answered against.
 */
final class TemplateStatus
{
    public const DRAFT    = 'Draft';
    public const ACTIVE   = 'Active';
    public const ARCHIVED = 'Archived';

    public const ALL = [self::DRAFT, self::ACTIVE, self::ARCHIVED];

    public const LABELS = [
        self::DRAFT    => 'Draft',
        self::ACTIVE   => 'Active',
        self::ARCHIVED => 'Archived',
    ];

    /** Only these may have new checklists issued from them. */
    public const ISSUABLE = [self::ACTIVE];

    /** The definition may only change while nothing has been issued against it. */
    public const EDITABLE = [self::DRAFT];

    public static function label(?string $s): string
    {
        return self::LABELS[$s] ?? (string) $s;
    }

    public static function isValid(?string $s): bool
    {
        return in_array($s, self::ALL, true);
    }

    public static function isIssuable(?string $s): bool
    {
        return in_array($s, self::ISSUABLE, true);
    }

    public static function isEditable(?string $s): bool
    {
        return in_array($s, self::EDITABLE, true);
    }
}
