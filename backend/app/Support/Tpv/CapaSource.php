<?php

namespace App\Support\Tpv;

/**
 * CAPA origin kinds and their concrete model classes (Sangoe TPV §25).
 *
 * A CAPA can be raised standalone ('manual') or linked to the record that
 * spawned it. KINDS drives the register's filter/label; CLASSES resolves a
 * kind to the model the polymorphic source points at (when linked).
 */
class CapaSource
{
    public const KINDS = ['ncr', 'inspection', 'audit', 'meeting', 'violation', 'renewal', 'incident', 'manual'];

    public const TYPES = ['Corrective', 'Preventive'];

    public const PRIORITIES = ['Low', 'Medium', 'High', 'Critical'];

    // Open → In_Progress → Done → Verified. Verified is terminal and requires
    // evidence (Rule 12).
    public const STATUSES = ['Open', 'In_Progress', 'Done', 'Verified'];

    /** Which kinds resolve to a linkable model (the rest are label-only). */
    public const CLASSES = [
        'ncr'        => \App\Models\Tpv\TpvNcr::class,
        'inspection' => \App\Models\Tpv\TpvInspection::class,
        'audit'      => \App\Models\Tpv\TpvInspection::class,
        'meeting'    => \App\Models\Shared\KickoffMeeting::class,
        'violation'  => \App\Models\Tpv\TpvVendorViolation::class,
        'renewal'    => \App\Models\Tpv\TpvRenewal::class,
        'incident'   => \App\Models\Tpv\HsseIncident::class,
    ];

    public static function classFor(?string $kind): ?string
    {
        return self::CLASSES[$kind] ?? null;
    }

    public static function label(?string $v): string
    {
        return $v ? ucfirst(str_replace('_', ' ', $v)) : 'Manual';
    }
}
