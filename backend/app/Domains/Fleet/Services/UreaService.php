<?php

namespace App\Domains\Fleet\Services;

use App\Domains\Fleet\Integration\TripCostPublisher;
use App\Domains\Fleet\Models\UreaTransaction;
use App\Domains\Fleet\Models\Vehicle;
use App\Exceptions\BusinessException;
use Illuminate\Support\Facades\Log;

/**
 * STOS-COST — AdBlue / urea.
 *
 * Kept apart from diesel on purpose. Urea is its own tank at its own price, and
 * the number that matters is litres per 100 km: a modern truck uses roughly
 * 1.5 L/100 km, so 6 L/100 km means somebody is dumping it, and 0.1 means the
 * dosing system has been bypassed to save money — which is an emissions
 * offence, not a saving.
 */
class UreaService
{
    /** Typical band for a heavy vehicle, litres per 100 km. */
    public const EXPECTED_MIN = 0.8;
    public const EXPECTED_MAX = 4.0;

    public function record(int $vehicleId, int $companyId, array $data, int $userId): UreaTransaction
    {
        $vehicle = Vehicle::forCompany($companyId)->find($vehicleId);

        if (! $vehicle) {
            throw new BusinessException('That vehicle is not in your fleet.', 404);
        }

        $odometer = isset($data['odometer']) ? (float) $data['odometer'] : null;
        $previous = $this->previousTopUp($vehicleId, $companyId);

        if ($odometer !== null && $previous && $previous->odometer !== null && $odometer <= (float) $previous->odometer) {
            throw new BusinessException(
                'The odometer reading ('.$odometer.' km) is not higher than the last urea top-up ('.(float) $previous->odometer.' km). Check the reading.'
            );
        }

        $rate = $data['rate_per_litre'] ?? null;

        $row = UreaTransaction::create([
            'company_id'       => $companyId,
            'vehicle_id'       => $vehicle->id,
            'trip_id'          => $data['trip_id'] ?? null,
            'litres'           => $data['litres'],
            'rate_per_litre'   => $rate,
            'amount'           => $data['amount'],
            'odometer'         => $odometer,
            'station_vendor'   => $data['station_vendor'] ?? null,
            'litres_per_100km' => $this->consumption($odometer, $previous, (float) $data['litres']),
        ]);

        // C-06 — urea is a trip cost when it names a trip.
        app(TripCostPublisher::class)->publishUrea($companyId, $row);

        if ($row->litres_per_100km !== null && $this->isOutsideBand((float) $row->litres_per_100km)) {
            Log::channel('stos')->warning('Urea consumption outside the expected band', [
                'company_id' => $companyId, 'user_id' => $userId,
                'vehicle_id' => $vehicle->id, 'urea_transaction_id' => $row->id,
                'litres_per_100km' => $row->litres_per_100km,
            ]);
        }

        return $row;
    }

    /**
     * Litres per 100 km since the last top-up.
     *
     * The first top-up on a vehicle has no previous odometer, so there is no
     * distance and no rate — reporting one would be inventing it.
     */
    private function consumption(?float $odometer, ?UreaTransaction $previous, float $litres): ?float
    {
        if ($odometer === null || ! $previous || $previous->odometer === null || $litres <= 0) {
            return null;
        }

        $km = $odometer - (float) $previous->odometer;

        return $km > 0 ? round(($litres / $km) * 100, 2) : null;
    }

    public function isOutsideBand(float $per100km): bool
    {
        return $per100km < self::EXPECTED_MIN || $per100km > self::EXPECTED_MAX;
    }

    private function previousTopUp(int $vehicleId, int $companyId): ?UreaTransaction
    {
        return UreaTransaction::forCompany($companyId)
            ->where('vehicle_id', $vehicleId)
            ->whereNotNull('odometer')
            ->orderByDesc('odometer')
            ->first();
    }
}
