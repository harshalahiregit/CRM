<?php

namespace App\Support\Hr;

/**
 * Section × scope × ability capability matrix — the ESS-ready permission model,
 * mirroring the Company Portal's CompanyRole::MATRIX. Scopes: hr | manager |
 * employee | system.
 *
 * Sprint 1 evaluates this with the scope pinned to 'hr' (full access). The
 * 'employee' / 'manager' entries already declare what a future Employee Self
 * Service actor will be permitted — enabling ESS is a code switch (pass the real
 * scope), never a schema or matrix change.
 */
class OnboardingAbility
{
    public const HR       = 'hr';
    public const MANAGER  = 'manager';
    public const EMPLOYEE = 'employee';
    public const SYSTEM   = 'system';

    public const VIEW   = 'view';
    public const EDIT   = 'edit';
    public const VERIFY = 'verify';

    /** section => [ ability => [allowed scopes] ] */
    public const MATRIX = [
        'overview'    => ['view' => [self::HR, self::MANAGER, self::EMPLOYEE], 'edit' => [self::HR],                  'verify' => [self::HR]],
        'personal'    => ['view' => [self::HR, self::MANAGER, self::EMPLOYEE], 'edit' => [self::HR, self::EMPLOYEE], 'verify' => [self::HR]],
        'education'   => ['view' => [self::HR, self::MANAGER, self::EMPLOYEE], 'edit' => [self::HR, self::EMPLOYEE], 'verify' => [self::HR]],
        'experience'  => ['view' => [self::HR, self::MANAGER, self::EMPLOYEE], 'edit' => [self::HR, self::EMPLOYEE], 'verify' => [self::HR]],
        'documents'   => ['view' => [self::HR, self::MANAGER, self::EMPLOYEE], 'edit' => [self::HR, self::EMPLOYEE], 'verify' => [self::HR]],
        'bank'        => ['view' => [self::HR, self::EMPLOYEE],                'edit' => [self::HR, self::EMPLOYEE], 'verify' => [self::HR]],
        'compliance'  => ['view' => [self::HR],                               'edit' => [self::HR],                'verify' => [self::HR]],
        'assets'      => ['view' => [self::HR, self::MANAGER, self::EMPLOYEE], 'edit' => [self::HR, self::SYSTEM],  'verify' => [self::HR]],
        'orientation' => ['view' => [self::HR, self::MANAGER, self::EMPLOYEE], 'edit' => [self::HR, self::MANAGER], 'verify' => [self::HR]],
        'training'    => ['view' => [self::HR, self::MANAGER, self::EMPLOYEE], 'edit' => [self::HR, self::MANAGER], 'verify' => [self::HR]],
        'checklist'   => ['view' => [self::HR, self::MANAGER],                'edit' => [self::HR, self::MANAGER], 'verify' => [self::HR]],
        'approvals'   => ['view' => [self::HR, self::MANAGER],                'edit' => [self::HR, self::MANAGER], 'verify' => [self::HR, self::MANAGER]],
    ];

    public const SECTIONS = [
        'overview', 'personal', 'education', 'experience', 'documents', 'bank',
        'compliance', 'assets', 'orientation', 'training', 'checklist', 'approvals',
    ];

    public static function can(string $scope, string $section, string $ability = self::EDIT): bool
    {
        return in_array($scope, self::MATRIX[$section][$ability] ?? [], true);
    }

    /**
     * Full ability map for a scope, sent to the frontend so the workspace renders
     * read / edit / verify per section without hard-coding any role.
     * Returns [ section => ['view'=>bool, 'edit'=>bool, 'verify'=>bool] ].
     */
    public static function mapFor(string $scope): array
    {
        $map = [];
        foreach (self::MATRIX as $section => $abilities) {
            $map[$section] = [
                'view'   => self::can($scope, $section, self::VIEW),
                'edit'   => self::can($scope, $section, self::EDIT),
                'verify' => self::can($scope, $section, self::VERIFY),
            ];
        }

        return $map;
    }
}
