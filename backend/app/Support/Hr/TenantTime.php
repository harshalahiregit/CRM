<?php

namespace App\Support\Hr;

use App\Services\Settings\SettingsService;
use Illuminate\Support\Carbon;

/**
 * Wall-clock time in the workspace's own timezone.
 *
 * Storage is UTC — that is deliberate and the rest of the CRM depends on it
 * (SettingsFormatter, the document numbering engine's period boundaries). What
 * was missing is the other half: converting back before anyone reads a time.
 *
 * Attendance is where that hurt. A 2:11pm clock-in is stored 08:41 UTC, and the
 * app was sent "08:41" to print verbatim — six hours adrift on the one screen
 * people check every morning. The same omission made "today" a UTC day, so a
 * clock-in after 5:30am IST was fine but one at 2am belonged to yesterday, and
 * the 09:00 shift start was compared against a UTC stamp, so lateness, overtime
 * and half-days were all judged five and a half hours out.
 *
 * The zone comes from the tenant's own `localization.timezone` setting, editable
 * under Settings → Localization. Nothing here hardcodes a country.
 */
class TenantTime
{
    /** Cached per tenant: this is called once per row when mapping a month. */
    private static array $zones = [];

    public static function zone(?int $tenantId): string
    {
        if (! $tenantId) {
            return config('app.timezone', 'UTC');
        }

        return self::$zones[$tenantId] ??= (string) (
            app(SettingsService::class)->get($tenantId, 'localization', 'timezone')
                ?: config('app.timezone', 'UTC')
        );
    }

    /** Now, as a wall clock in the workspace's timezone. */
    public static function now(?int $tenantId): Carbon
    {
        return Carbon::now(self::zone($tenantId));
    }

    /** The workspace's current date — the day an employee would call "today". */
    public static function today(?int $tenantId): string
    {
        return self::now($tenantId)->toDateString();
    }

    /**
     * A stored UTC timestamp as "HH:MM" on the workspace's clock.
     *
     * Returns '' rather than null: the app's models read String? and print a
     * blank as "--", where a null would crash the widget.
     */
    public static function hm($value, ?int $tenantId): string
    {
        if (! $value) {
            return '';
        }

        return Carbon::parse($value)->setTimezone(self::zone($tenantId))->format('H:i');
    }

    /** Only for tests, which switch tenants inside one process. */
    public static function flush(): void
    {
        self::$zones = [];
    }
}
