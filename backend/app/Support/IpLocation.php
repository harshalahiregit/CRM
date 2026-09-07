<?php

namespace App\Support;

use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Roughly where an IP address is, when the tenant has asked for that.
 *
 * The device, the browser and the address itself are all in the request — free,
 * exact, and involving nobody else. A LOCATION is not: turning an address into
 * a town means sending that address to a third party, which is a decision about
 * other people's data and not one to make on an administrator's behalf.
 *
 * So this is OFF by default and returns null, and the audit line still carries
 * the address, the device and the browser — which is what most questions
 * actually need. An administrator who wants places as well turns it on in
 * Settings → Security, and the lookup service is named there so it is a choice
 * rather than a hidden dependency.
 *
 * Also worth knowing when reading the result: IP geolocation is approximate. It
 * usually resolves to the ISP's exchange, not the person — good enough for "that
 * download did not come from our office", not for anything finer.
 */
final class IpLocation
{
    /** Looked up once per address per day; the answer does not move. */
    private const TTL_SECONDS = 86400;

    private const TIMEOUT_SECONDS = 2;

    public static function for(?string $ip, ?int $tenantId): ?string
    {
        $ip = trim((string) $ip);
        if ($ip === '' || self::isLocal($ip)) {
            return null;
        }

        $endpoint = self::endpoint($tenantId);
        if (! $endpoint) {
            return null;   // not switched on — the normal state
        }

        return Cache::remember('ip-location:'.$ip, self::TTL_SECONDS, function () use ($endpoint, $ip) {
            try {
                // Short timeout on purpose: this runs while somebody waits for a
                // download, and a slow lookup must not hold the file up.
                $res = Http::timeout(self::TIMEOUT_SECONDS)->get(str_replace('{ip}', urlencode($ip), $endpoint));
                if (! $res->ok()) {
                    return null;
                }

                $b = $res->json();
                $parts = array_filter([
                    $b['city'] ?? null,
                    $b['regionName'] ?? $b['region'] ?? null,
                    $b['countryCode'] ?? $b['country'] ?? null,
                ]);

                return $parts ? mb_substr(implode(', ', $parts), 0, 120) : null;
            } catch (\Throwable) {
                return null;   // never break a download over a lookup
            }
        });
    }

    /**
     * The configured lookup, or null.
     *
     * A URL with {ip} in it, so the tenant chooses the service rather than
     * inheriting one nobody agreed to.
     */
    private static function endpoint(?int $tenantId): ?string
    {
        if (! $tenantId) {
            return null;
        }

        try {
            $url = app(SettingsService::class)->get($tenantId, 'security', 'ip_location_endpoint');
        } catch (\Throwable) {
            return null;
        }

        return is_string($url) && str_contains($url, '{ip}') && str_starts_with($url, 'https://')
            ? $url
            : null;
    }

    /** A private or loopback address has no public location to find. */
    private static function isLocal(string $ip): bool
    {
        return ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
}
