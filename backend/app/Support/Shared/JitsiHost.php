<?php

namespace App\Support\Shared;

use App\Services\Settings\SettingsService;

/**
 * Which Jitsi server this tenant's meetings are held on.
 *
 * The default, meet.jit.si, is 8x8's free public instance. It needs no account
 * and no keys, which is why it was chosen — but 8x8 now require whoever STARTS
 * a room there to sign in with Google, GitHub or Facebook first. Everyone else
 * gets in once that person is through, so the symptom is that the organiser is
 * asked to log into Google to open their own meeting.
 *
 * That is the public server's policy, not something the application can switch
 * off: it is enforced on 8x8's side, and the only ways past it are a JWT from a
 * paid 8x8 JaaS account or a different server. So the fix is to make the server
 * a setting the tenant controls, and to say plainly in the UI what the default
 * costs them.
 *
 * Resolution order, most specific first:
 *   1. the tenant's own Settings → Meetings → Jitsi server
 *   2. JITSI_DOMAIN in .env (a deployment-wide default)
 *   3. meet.jit.si — works out of the box, asks the host to sign in
 *
 * Never throws: a meeting must still get a link when the settings table is
 * unreachable or holds nonsense.
 */
final class JitsiHost
{
    public const PUBLIC_HOST = 'meet.jit.si';

    /** Per-request memo — this is read on every link mint and every room load. */
    private static array $hosts = [];

    public static function for(?int $tenantId = null): string
    {
        return self::$hosts[$tenantId ?: 0] ??= self::resolve($tenantId);
    }

    /** Is this tenant still on the server that demands a social login to start? */
    public static function isPublic(?int $tenantId = null): bool
    {
        return self::for($tenantId) === self::PUBLIC_HOST;
    }

    /** Drop the memo — settings change between requests, and between tests. */
    public static function flush(): void
    {
        self::$hosts = [];
    }

    private static function resolve(?int $tenantId): string
    {
        $configured = null;

        if ($tenantId) {
            try {
                $configured = app(SettingsService::class)->get($tenantId, 'meetings', 'jitsi_domain');
            } catch (\Throwable) {
                $configured = null;
            }
        }

        $host = $configured ?: config('meeting.jitsi.domain') ?: self::PUBLIC_HOST;

        return self::clean($host) ?: self::PUBLIC_HOST;
    }

    /**
     * Accept what a person would actually type.
     *
     * Someone setting this will paste "https://meet.mycompany.com/" as often as
     * they will type the bare host, and a stored scheme would produce
     * "https://https://meet…/room". Reduced to a host (with an optional port),
     * or rejected so the fallback stands rather than minting a broken link.
     */
    public static function clean(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $value = preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $value);
        $value = rtrim(explode('/', $value, 2)[0], '.');

        return preg_match('/^[a-z0-9.-]+(:\d{1,5})?$/i', $value) ? strtolower($value) : null;
    }
}
