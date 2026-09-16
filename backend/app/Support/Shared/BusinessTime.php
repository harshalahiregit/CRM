<?php

namespace App\Support\Shared;

use App\Casts\BusinessDateTime;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Carbon;

/**
 * The timezone a *meeting* is expressed in, and "now" measured in it.
 *
 * The application stores machine timestamps (created_at, completed_at, every
 * `now()`) in UTC, which is right and must not change. But a meeting time is
 * not a machine timestamp — it is a wall-clock appointment a person typed, and
 * it has always been stored as that wall clock: type 2:30 PM, the row holds
 * 14:30:00.
 *
 * The bug that led here was the boundary between the two. Those wall-clock rows
 * were cast as plain `datetime`, so Laravel handed them out as UTC instants and
 * the browser localised them a second time — a meeting entered at 09:00 came
 * back as 14:30, and re-saving stored 14:30, so every edit walked the meeting
 * another +05:30 down the day. The invitation's calendar attachment marked the
 * same instant in UTC, so it disagreed with the screen by the same offset.
 *
 * The fix is to say out loud what the stored value means: a wall clock in the
 * business timezone. {@see BusinessDateTime} reads and writes it
 * under that rule; this class supplies the zone and a comparable `now()`.
 *
 * Machine timestamps are untouched — they were, and remain, correct in UTC.
 */
final class BusinessTime
{
    /**
     * Used when no tenant setting is readable — during migrations, in console
     * commands with no tenant in hand, and for rows written before the
     * localization settings existed. It matches the registry's own default, so
     * the fallback and the configured value agree out of the box.
     */
    public const FALLBACK = 'Asia/Kolkata';

    /** Per-request memo: this is read on every meeting attribute access. */
    private static array $zones = [];

    /**
     * The tenant's configured timezone, or the fallback.
     *
     * Never throws: a meeting must still render when the settings table is
     * absent (a fresh migration) or the stored value is not a real zone.
     */
    public static function zone(?int $tenantId = null): string
    {
        $key = $tenantId ?: 0;

        return self::$zones[$key] ??= self::resolve($tenantId);
    }

    /** `now()` in the business zone — the only "now" a stored meeting time may be compared against. */
    public static function now(?int $tenantId = null): Carbon
    {
        return Carbon::now(self::zone($tenantId));
    }

    /**
     * Read a stored (or incoming) value as an instant in the business zone.
     *
     * A value that carries its own offset — "…Z", "…+05:30" — already names an
     * instant, so it is honoured and merely converted for display. A bare value
     * is a wall clock, and a wall clock only means something once you say where:
     * here, the business zone.
     */
    public static function parse($value, ?int $tenantId = null): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        $zone = self::zone($tenantId);

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->setTimezone($zone);
        }

        $value = (string) $value;

        return self::carriesOffset($value)
            ? Carbon::parse($value)->setTimezone($zone)
            : Carbon::parse($value, $zone);
    }

    /** The storage form: the business-zone wall clock, exactly as the column has always held it. */
    public static function toStorage($value, ?int $tenantId = null): ?string
    {
        return self::parse($value, $tenantId)?->format('Y-m-d H:i:s');
    }

    /** Drop the memo — tests change the setting between cases. */
    public static function flush(): void
    {
        self::$zones = [];
    }

    /**
     * Does this string name an instant on its own?
     *
     * "2026-09-04T14:30:00Z" and "…+05:30" do. "2026-09-04 14:30:00" and
     * "2026-09-04T14:30" do not — those are wall clocks. The offset can only be
     * at the end, after the time, which is what anchors the pattern; a date's
     * own hyphens sit too early in the string to match.
     */
    private static function carriesOffset(string $value): bool
    {
        return (bool) preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/i', trim($value))
            && (bool) preg_match('/\d{2}:\d{2}/', $value);
    }

    private static function resolve(?int $tenantId): string
    {
        if (! $tenantId) {
            return self::FALLBACK;
        }

        try {
            $zone = app(SettingsService::class)->get($tenantId, 'localization', 'timezone');
        } catch (\Throwable) {
            return self::FALLBACK;
        }

        return is_string($zone) && $zone !== '' && in_array($zone, timezone_identifiers_list(), true)
            ? $zone
            : self::FALLBACK;
    }
}
