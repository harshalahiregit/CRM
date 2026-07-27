<?php

namespace App\Support\Tpv;

/**
 * Personal protective equipment issued at step 4. The MANDATORY set must be
 * issued before a worker can be badged — head and foot protection are
 * non-negotiable for site entry.
 */
final class TpvPpeItem
{
    public const HELMET         = 'helmet';
    public const SAFETY_SHOES   = 'safety_shoes';
    public const HI_VIS_VEST    = 'hi_vis_vest';
    public const GLOVES         = 'gloves';
    public const SAFETY_GOGGLES = 'safety_goggles';
    public const EAR_PLUGS      = 'ear_plugs';
    public const FULL_HARNESS   = 'full_harness';
    public const FACE_SHIELD    = 'face_shield';
    public const RESPIRATOR     = 'respirator';

    public const ALL = [
        self::HELMET, self::SAFETY_SHOES, self::HI_VIS_VEST, self::GLOVES,
        self::SAFETY_GOGGLES, self::EAR_PLUGS, self::FULL_HARNESS,
        self::FACE_SHIELD, self::RESPIRATOR,
    ];

    /** Required before a badge may be issued. */
    public const MANDATORY = [
        self::HELMET, self::SAFETY_SHOES,
    ];

    public const LABELS = [
        self::HELMET         => 'Safety Helmet',
        self::SAFETY_SHOES   => 'Safety Shoes',
        self::HI_VIS_VEST    => 'Hi-Vis Vest',
        self::GLOVES         => 'Hand Gloves',
        self::SAFETY_GOGGLES => 'Safety Goggles',
        self::EAR_PLUGS      => 'Ear Plugs',
        self::FULL_HARNESS   => 'Full-Body Harness',
        self::FACE_SHIELD    => 'Face Shield',
        self::RESPIRATOR     => 'Respirator / Mask',
    ];

    public static function label(?string $item): string
    {
        return self::LABELS[$item] ?? ucwords(str_replace('_', ' ', (string) $item));
    }

    public static function isValid(?string $item): bool
    {
        return in_array($item, self::ALL, true);
    }
}
