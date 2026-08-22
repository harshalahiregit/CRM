<?php

namespace App\Support\Purchase;

/**
 * Purchase CAPA origin kinds — the Purchase-side mirror of TPV's CapaSource
 * (parity rule). A CAPA can be raised standalone ('manual') or linked to the
 * record that spawned it. Only kinds whose Purchase models exist are mapped in
 * CLASSES; the rest are label-only until their mirrors land.
 */
class PurchaseCapaSource
{
    public const KINDS = ['ncr', 'inspection', 'audit', 'meeting', 'meeting_issue', 'violation', 'renewal', 'manual'];

    public const TYPES = ['Corrective', 'Preventive'];

    public const PRIORITIES = ['Low', 'Medium', 'High', 'Critical'];

    public const STATUSES = ['Open', 'In_Progress', 'Done', 'Verified'];

    /** Which kinds resolve to a linkable Purchase model (rest are label-only). */
    public const CLASSES = [
        'ncr'           => \App\Models\Purchase\PurchaseNcr::class,
        'meeting'       => \App\Models\Purchase\PurchaseKickoffMeeting::class,
        'meeting_issue' => \App\Models\Purchase\PurchaseMomIssue::class,
        'violation'     => \App\Models\Purchase\PurchaseVendorViolation::class,
    ];

    public static function classFor(?string $kind): ?string
    {
        return self::CLASSES[$kind] ?? null;
    }

    /**
     * Map a governance record's severity onto a CAPA priority, so an
     * auto-raised CAPA inherits the urgency of the NCR / violation it came from.
     */
    public static function priorityForSeverity(?string $severity): string
    {
        return match ($severity) {
            'Critical' => 'Critical',
            'Major'    => 'High',
            'Minor'    => 'Low',
            default    => 'Medium',
        };
    }

    public static function label(?string $v): string
    {
        return $v ? ucfirst(str_replace('_', ' ', $v)) : 'Manual';
    }
}
