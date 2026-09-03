<?php

namespace App\Services\Medical;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Turns the coordinates captured at sign-time into a place a human can read.
 *
 * The certificate has to say WHERE it was signed. A pair of decimals proves it
 * to a machine but tells a reviewer nothing, so the raw lat/long is kept on the
 * record (it is the evidence) and this resolves the readable form alongside it —
 * "Andheri East, Mumbai, Maharashtra".
 *
 * Deliberately best-effort: the lookup is an outside service, and a certificate
 * must never fail to save because a map server was slow. On any failure the
 * caller keeps the coordinates and the place stays null.
 *
 * Results are cached by rounded coordinates (≈100 m), which is both a courtesy
 * to the free endpoint and enough precision for a place name — a whole day of
 * examinations at one clinic resolves once.
 */
class MedicalGeoResolver
{
    /**
     * @param  string|null  $geo  "lat,long" as captured by the browser
     */
    public function resolve(?string $geo): ?string
    {
        $coords = self::parse($geo);
        if ($coords === null || ! config('medical.geocoding.enabled', true)) {
            return null;
        }

        [$lat, $lon] = $coords;
        $key = sprintf('medical:geo:%.3f,%.3f', $lat, $lon);

        return Cache::remember($key, now()->addDays(30), function () use ($lat, $lon) {
            return $this->lookup($lat, $lon);
        });
    }

    /**
     * Split and sanity-check a "lat,long" string. Rejects anything off the
     * globe so a typo cannot be stored as a location.
     *
     * @return array{0: float, 1: float}|null
     */
    public static function parse(?string $geo): ?array
    {
        if (! $geo || ! str_contains($geo, ',')) {
            return null;
        }

        [$lat, $lon] = array_pad(array_map('trim', explode(',', $geo, 2)), 2, null);
        if (! is_numeric($lat) || ! is_numeric($lon)) {
            return null;
        }

        $lat = (float) $lat;
        $lon = (float) $lon;
        if (abs($lat) > 90 || abs($lon) > 180) {
            return null;
        }

        return [$lat, $lon];
    }

    /** A maps link for the certificate footer, so the place can be checked. */
    public static function mapUrl(?string $geo): ?string
    {
        $coords = self::parse($geo);

        return $coords === null ? null : 'https://www.openstreetmap.org/?mlat='.$coords[0].'&mlon='.$coords[1].'#map=17/'.$coords[0].'/'.$coords[1];
    }

    private function lookup(float $lat, float $lon): ?string
    {
        try {
            $response = Http::withHeaders([
                    // Nominatim's usage policy requires an identifying agent.
                    'User-Agent' => config('medical.geocoding.user_agent', 'SangoeCRM/1.0'),
                    'Accept'     => 'application/json',
                ])
                ->timeout((int) config('medical.geocoding.timeout', 4))
                ->get(config('medical.geocoding.endpoint', 'https://nominatim.openstreetmap.org/reverse'), [
                    'lat'    => $lat,
                    'lon'    => $lon,
                    'format' => 'jsonv2',
                    'zoom'   => 14,
                ]);

            if (! $response->successful()) {
                return null;
            }

            return $this->format($response->json('address') ?? []) ?: ($response->json('display_name') ?: null);
        } catch (\Throwable $e) {
            Log::warning('Medical geocoding failed', ['lat' => $lat, 'lon' => $lon, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Build "area, city, state" from whichever address parts came back —
     * Nominatim names the locality differently depending on the country, so the
     * first non-empty of each group wins.
     *
     * @param  array<string,string>  $address
     */
    private function format(array $address): ?string
    {
        $pick = function (array $keys) use ($address): ?string {
            foreach ($keys as $key) {
                if (! empty($address[$key])) {
                    return $address[$key];
                }
            }

            return null;
        };

        $parts = array_filter([
            $pick(['suburb', 'neighbourhood', 'city_district', 'village', 'town']),
            $pick(['city', 'town', 'municipality', 'county']),
            $pick(['state', 'region']),
        ]);

        // The suburb and the city can resolve to the same word; say it once.
        $parts = array_values(array_unique($parts));

        return $parts ? implode(', ', $parts) : null;
    }
}
