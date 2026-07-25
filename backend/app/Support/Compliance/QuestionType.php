<?php

namespace App\Support\Compliance;

/**
 * The question types a template may use, and how each one earns risk points.
 *
 * Deliberately small. Every type here has defined validation AND defined
 * scoring — a type with no scoring rule would silently contribute 0 to the
 * maximum and quietly deflate every band that uses it.
 */
final class QuestionType
{
    /** Yes/no. Risk when the answer equals the question's `risk_when`. */
    public const BOOLEAN = 'boolean';
    /** One of `options`, each carrying its own `risk` (and optional `critical`). */
    public const CHOICE = 'choice';
    /** Numeric. Risk is `value * risk_per_unit`, capped at `risk_cap`. */
    public const NUMBER = 'number';
    /** Free text — evidence only, never scored. */
    public const TEXT = 'text';
    /** A date — evidence only, never scored. */
    public const DATE = 'date';

    public const ALL = [self::BOOLEAN, self::CHOICE, self::NUMBER, self::TEXT, self::DATE];

    /** Types that contribute risk points. Others are evidence-only. */
    public const SCORABLE = [self::BOOLEAN, self::CHOICE, self::NUMBER];

    public const LABELS = [
        self::BOOLEAN => 'Yes / No',
        self::CHOICE  => 'Single choice',
        self::NUMBER  => 'Number',
        self::TEXT    => 'Text',
        self::DATE    => 'Date',
    ];

    public static function label(?string $t): string
    {
        return self::LABELS[$t] ?? (string) $t;
    }

    public static function isValid(?string $t): bool
    {
        return in_array($t, self::ALL, true);
    }

    public static function isScorable(?string $t): bool
    {
        return in_array($t, self::SCORABLE, true);
    }
}
