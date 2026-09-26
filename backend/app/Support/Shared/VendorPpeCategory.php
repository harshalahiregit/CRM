<?php

namespace App\Support\Shared;

/**
 * The kinds of PPE a vendor can put on its own list.
 *
 * Shared by TPV and Purchase so the two portals offer the same choices — a
 * vendor that works for both sides should not find "Vest" on one and
 * "Hi-Vis Vest" on the other.
 */
final class VendorPpeCategory
{
    public const LABELS = [
        'helmet'         => 'Helmet',
        'gloves'         => 'Gloves',
        'safety_shoes'   => 'Safety Shoes',
        'vest'           => 'Hi-Vis Vest',
        'goggles'        => 'Goggles',
        'harness'        => 'Harness',
        'ear_protection' => 'Ear Protection',
        'respirator'     => 'Respirator / Mask',
        'face_shield'    => 'Face Shield',
        'coverall'       => 'Coverall',
        'other'          => 'Other',
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::LABELS);
    }

    public static function label(?string $key): string
    {
        return self::LABELS[$key] ?? ucwords(str_replace('_', ' ', (string) $key));
    }
}
