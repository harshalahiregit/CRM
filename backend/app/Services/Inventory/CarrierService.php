<?php

namespace App\Services\Inventory;

use Illuminate\Support\Facades\Log;

/**
 * The courier boundary.
 *
 * No carrier account exists yet, so this is built the way that makes adding one
 * a config change rather than a rewrite: every shipment goes through book(),
 * and when there are no credentials it returns a MANUAL booking — the tracking
 * number the person typed off the courier's own slip.
 *
 * That matters more than it sounds. The alternative — leaving shipping
 * unbuilt until an account exists — means the warehouse cannot record that a
 * parcel went out, which is the one fact everybody asks for. A manual tracking
 * number is a real answer; a missing feature is not.
 *
 * Adding a carrier later means implementing one method and filling in
 * config/inventory.php. Nothing above this class changes.
 */
class CarrierService
{
    /**
     * Book a shipment with the carrier.
     *
     * @param  array  $parcel  ['carrier','weight','parcels','reference','to_name','to_address']
     * @return array{mode:string,carrier:?string,tracking_number:?string,tracking_url:?string,message:string}
     */
    public function book(array $parcel, int $tenantId): array
    {
        $carrier = trim((string) ($parcel['carrier'] ?? '')) ?: null;
        $config = $this->configFor($carrier);

        // No account, or none for this carrier: the person types the number off
        // the courier's slip and we record it exactly as given.
        if (! $config || empty($config['api_key'])) {
            return [
                'mode'            => 'manual',
                'carrier'         => $carrier,
                'tracking_number' => trim((string) ($parcel['tracking_number'] ?? '')) ?: null,
                'tracking_url'    => $this->trackingUrl($carrier, $parcel['tracking_number'] ?? null),
                'message'         => 'Recorded manually — no courier account is connected.',
            ];
        }

        try {
            // When an account is added, the driver call goes here. It must return
            // the same shape, so nothing upstream learns that anything changed.
            return $this->callCarrier($config, $parcel) + ['mode' => 'api', 'carrier' => $carrier];
        } catch (\Throwable $e) {
            // A courier API outage must never block a parcel leaving the
            // building. Fall back to manual and say so, rather than failing the
            // dispatch and leaving a loaded van waiting on someone's HTTP 500.
            Log::warning("Carrier booking failed ({$carrier}): {$e->getMessage()}");

            return [
                'mode'            => 'manual',
                'carrier'         => $carrier,
                'tracking_number' => trim((string) ($parcel['tracking_number'] ?? '')) ?: null,
                'tracking_url'    => $this->trackingUrl($carrier, $parcel['tracking_number'] ?? null),
                'message'         => 'The courier could not be reached, so this was recorded manually.',
            ];
        }
    }

    /** Carriers a workspace can pick from, and whether each is actually wired up. */
    public function available(): array
    {
        $out = [];
        foreach ((array) config('inventory.carriers', []) as $key => $cfg) {
            $out[] = [
                'key'       => $key,
                'label'     => $cfg['label'] ?? ucfirst($key),
                // The UI shows this so nobody wonders why a tracking number had
                // to be typed in: the account simply isn't connected yet.
                'connected' => ! empty($cfg['api_key']),
            ];
        }

        return $out;
    }

    /* ── Internals ──────────────────────────────────────────────── */

    private function configFor(?string $carrier): ?array
    {
        if (! $carrier) {
            return null;
        }

        $all = (array) config('inventory.carriers', []);

        return $all[strtolower($carrier)] ?? null;
    }

    /**
     * A public tracking link, built from the carrier's own URL pattern. Works
     * with no account at all — it is just a URL — so a customer can be sent a
     * link the moment the number is typed in.
     */
    private function trackingUrl(?string $carrier, ?string $number): ?string
    {
        $number = trim((string) $number);
        if (! $carrier || $number === '') {
            return null;
        }

        $pattern = $this->configFor($carrier)['track_url'] ?? null;

        return $pattern ? str_replace('{number}', rawurlencode($number), $pattern) : null;
    }

    /**
     * Where a real carrier call will live. Deliberately unimplemented rather
     * than faked: returning invented tracking numbers would look like it worked
     * and lose parcels.
     */
    private function callCarrier(array $config, array $parcel): array
    {
        throw new \RuntimeException('No carrier driver is implemented yet.');
    }
}
