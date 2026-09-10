<?php

namespace Sire\Support;

/**
 * SIRE — the test case vocabulary.
 *
 * CORE owns this, not the AI layer. Test categories are a QA concept that exists
 * whether or not anything generates them: a person typing a boundary test by hand
 * uses the same word.
 *
 * It lives here because the alternative was core reading a constant off
 * SireTestCaseGenerator — a core→AI dependency that would have made the whole
 * layering a fiction. The module-boundary check caught exactly that, and this file
 * is the fix.
 */
final class SireTestCategory
{
    public const HAPPY_PATH       = 'happy_path';
    public const FAILURE_PATH     = 'failure_path';
    public const BOUNDARY         = 'boundary';
    public const PERMISSION       = 'permission';
    public const REGRESSION       = 'regression';
    public const RELATED_WORKFLOW = 'related_workflow';

    /** In the order a checklist reads best: prove it works, prove it is fixed, then probe. */
    public const ALL = [
        self::HAPPY_PATH, self::FAILURE_PATH, self::BOUNDARY,
        self::PERMISSION, self::REGRESSION, self::RELATED_WORKFLOW,
    ];

    public const LABELS = [
        self::HAPPY_PATH       => 'Happy path',
        self::FAILURE_PATH     => 'Failure path',
        self::BOUNDARY         => 'Boundary case',
        self::PERMISSION       => 'Permission case',
        self::REGRESSION       => 'Regression case',
        self::RELATED_WORKFLOW => 'Related workflow',
    ];

    /** The two every defect needs, whatever else is true of it. */
    public const MANDATORY = [self::HAPPY_PATH, self::FAILURE_PATH];

    public static function isValid(?string $category): bool
    {
        return $category !== null && in_array($category, self::ALL, true);
    }

    public static function label(string $category): string
    {
        return self::LABELS[$category] ?? $category;
    }
}
