<?php

namespace App\Support\Purchase;

/**
 * The Purchase meeting-type catalogue — the Purchase-side mirror of the shared
 * MeetingTypeCatalog (parity rule), config-driven from config/purchase_meetings.php.
 *
 * Kickoff is ONE type here, not a separate module (Sangoe TPV §9 / §39). Static
 * (no per-tenant DB overrides yet) — a tenant-editable Settings layer is a later
 * slice; the API shape stays the same so wiring it in later is additive.
 */
class PurchaseMeetingTypeCatalog
{
    public const DEFAULT = 'kickoff';

    /** key => label map. */
    public static function types(): array
    {
        return config('purchase_meetings.types', ['kickoff' => 'Kickoff Meeting']);
    }

    /** Valid type keys — for the meeting_type validation rule. */
    public static function keys(): array
    {
        return array_keys(self::types());
    }

    /** Label for one key, falling back to a humanised key. */
    public static function label(?string $key): string
    {
        $key = $key ?: self::DEFAULT;

        return self::types()[$key] ?? ucfirst(str_replace('_', ' ', (string) $key));
    }

    /** key => agenda-template map. */
    public static function templates(): array
    {
        return config('purchase_meetings.templates', []);
    }
}
