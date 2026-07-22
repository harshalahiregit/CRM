<?php

namespace App\Services\Customer;

use App\Exceptions\BusinessException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Address → coordinates lookup backing the customer map picker.
 *
 * Uses OpenStreetMap Nominatim: no API key to provision, and the call is made
 * server-side so we can send the identifying User-Agent their usage policy
 * requires (a browser-side call would also expose each tenant's origin and be
 * rate-limited per user). Results are cached because the same address tends to
 * be searched repeatedly while a user fine-tunes the pin.
 */
class GeocodingService
{
    private const ENDPOINT      = 'https://nominatim.openstreetmap.org/search';
    private const TIMEOUT_SECS  = 8;
    private const MAX_RESULTS   = 6;
    private const CACHE_HOURS   = 24;

    /**
     * @return array<int, array{label: string, lat: float, lon: float}>
     *
     * @throws BusinessException when the upstream service is unreachable
     */
    public function search(string $query): array
    {
        $query = trim($query);

        return Cache::remember(
            'geocode:'.md5(mb_strtolower($query)),
            now()->addHours(self::CACHE_HOURS),
            fn () => $this->fetch($query),
        );
    }

    /** @return array<int, array{label: string, lat: float, lon: float}> */
    private function fetch(string $query): array
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => 'SangoeCRM/1.0 (customer map location picker)',
                'Accept'     => 'application/json',
            ])->timeout(self::TIMEOUT_SECS)->get(self::ENDPOINT, [
                'q'              => $query,
                'format'         => 'json',
                'limit'          => self::MAX_RESULTS,
                'addressdetails' => 0,
            ]);
        } catch (\Throwable $e) {
            Log::channel('customer')->warning('Geocode request failed', ['error' => $e->getMessage()]);
            throw new BusinessException('Address search is unavailable right now. You can still enter coordinates manually.', 502);
        }

        if (! $response->successful()) {
            Log::channel('customer')->warning('Geocode request rejected', ['status' => $response->status()]);
            throw new BusinessException('Address search is unavailable right now. You can still enter coordinates manually.', 502);
        }

        return collect($response->json())
            ->map(fn (array $row) => [
                'label' => (string) ($row['display_name'] ?? ''),
                'lat'   => (float) ($row['lat'] ?? 0),
                'lon'   => (float) ($row['lon'] ?? 0),
            ])
            ->filter(fn (array $row) => $row['label'] !== '' && ($row['lat'] || $row['lon']))
            ->values()
            ->all();
    }
}
