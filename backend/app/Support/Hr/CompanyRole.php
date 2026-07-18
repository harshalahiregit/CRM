<?php

namespace App\Support\Hr;

/**
 * Company portal sub-roles (stored in users.internal_role, role='company').
 * Prefixed to never collide with staff internal_role values (e.g. hiring_manager).
 */
class CompanyRole
{
    public const ADMIN          = 'company_admin';
    public const HR             = 'company_hr';
    public const HIRING_MANAGER = 'company_hiring_manager';
    public const INTERVIEWER    = 'company_interviewer';
    public const VIEWER         = 'company_viewer';

    public const ALL = [
        self::ADMIN, self::HR, self::HIRING_MANAGER, self::INTERVIEWER, self::VIEWER,
    ];

    /** Roles a non-admin may be assigned (admins can assign any). */
    public const ASSIGNABLE = [
        self::HR, self::HIRING_MANAGER, self::INTERVIEWER, self::VIEWER, self::ADMIN,
    ];

    public const LABELS = [
        self::ADMIN          => 'Company Admin',
        self::HR             => 'Company HR',
        self::HIRING_MANAGER => 'Hiring Manager',
        self::INTERVIEWER    => 'Interviewer',
        self::VIEWER         => 'Viewer',
    ];

    /**
     * Per-area capability matrix. 'view' = can see; 'manage' = can act/write.
     * The frontend gates UI from this; the backend enforces it server-side.
     */
    public const MATRIX = [
        'dashboard'       => ['view' => self::ALL, 'manage' => []],
        'hiring_requests' => ['view' => self::ALL, 'manage' => [self::ADMIN, self::HR, self::HIRING_MANAGER]],
        'candidates'      => ['view' => self::ALL, 'manage' => [self::ADMIN, self::HR, self::HIRING_MANAGER]],
        'interviews'      => ['view' => self::ALL, 'manage' => [self::ADMIN, self::HR, self::HIRING_MANAGER, self::INTERVIEWER]],
        'offers'          => ['view' => self::ALL, 'manage' => [self::ADMIN, self::HR, self::HIRING_MANAGER]],
        'documents'       => ['view' => self::ALL, 'manage' => [self::ADMIN, self::HR, self::HIRING_MANAGER]],
        'messages'        => ['view' => self::ALL, 'manage' => [self::ADMIN, self::HR, self::HIRING_MANAGER, self::INTERVIEWER]],
        'reports'         => ['view' => [self::ADMIN, self::HR, self::HIRING_MANAGER], 'manage' => []],
        'team'            => ['view' => [self::ADMIN], 'manage' => [self::ADMIN]],
        'settings'        => ['view' => [self::ADMIN], 'manage' => [self::ADMIN]],
    ];

    public static function can(?string $role, string $area, string $ability = 'manage'): bool
    {
        return in_array($role, self::MATRIX[$area][$ability] ?? [], true);
    }

    /** The full ability map for one role — sent to the frontend for UI gating. */
    public static function abilitiesFor(?string $role): array
    {
        $out = [];
        foreach (self::MATRIX as $area => $abilities) {
            $out[$area] = [
                'view'   => in_array($role, $abilities['view'] ?? [], true),
                'manage' => in_array($role, $abilities['manage'] ?? [], true),
            ];
        }

        return $out;
    }
}
