<?php

namespace App\Domains\Fleet\Services;

use App\Domains\Fleet\Events\EmergencyFuelIssued;
use App\Domains\Fleet\Models\FuelTransaction;
use App\Domains\Fleet\Models\Vehicle;
use App\Exceptions\BusinessException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * STOS-COST — recording a fill, and noticing when one does not add up.
 *
 * Consumption is computed HERE, at entry, from the odometer gap since the
 * previous fill, and stored on the row. It is not an accessor: correcting an
 * old fill would otherwise silently rewrite every efficiency figure after it,
 * and a variance report that changes when you look at it twice is worthless.
 */
class FuelService
{
    public function record(int $vehicleId, int $companyId, array $data, int $userId, ?UploadedFile $receipt = null): FuelTransaction
    {
        $vehicle = Vehicle::forCompany($companyId)->find($vehicleId);

        if (! $vehicle) {
            throw new BusinessException('That vehicle is not in your fleet.', 404);
        }

        $odometer = isset($data['odometer']) ? (float) $data['odometer'] : null;
        $previous = $this->previousFill($vehicleId, $companyId);

        $this->assertOdometerMovesForward($odometer, $previous);

        $consumption = $this->consumption($vehicle, $odometer, $previous, (float) $data['litres']);

        $isEmergency = (bool) ($data['is_emergency'] ?? false);
        $recoverable = array_key_exists('customer_recoverable', $data)
            ? (bool) $data['customer_recoverable'] : null;

        $row = DB::transaction(function () use ($vehicle, $companyId, $data, $consumption, $isEmergency, $recoverable, $receipt) {
            $path = $receipt ? $receipt->store("stos/fuel-receipts/{$companyId}", 'local') : null;

            return FuelTransaction::create([
                'company_id'     => $companyId,
                'vehicle_id'     => $vehicle->id,
                'trip_id'        => $data['trip_id'] ?? null,
                'litres'         => $data['litres'],
                'rate_per_litre' => $data['rate_per_litre'],
                'amount'         => $data['amount'],
                'odometer'       => $data['odometer'] ?? null,
                'station_vendor' => $data['station_vendor'] ?? null,

                'is_emergency'         => $isEmergency,
                'emergency_reason'     => $isEmergency ? ($data['emergency_reason'] ?? null) : null,
                'customer_recoverable' => $isEmergency ? $recoverable : null,
                'receipt_path'         => $path,

                // An emergency fill the customer carries goes to Developer 3's
                // billing engine as 'billable'; one we chase internally is
                // 'pending'. A normal fill is nobody's debt.
                'recovery_status' => $this->recoveryStatus($isEmergency, $recoverable),

                'km_driven'       => $consumption['km_driven'],
                'efficiency_kmpl' => $consumption['efficiency_kmpl'],
                'fuel_exception'  => $consumption['exception'],
                'variance_note'   => $consumption['note'],
            ]);
        });

        if ($isEmergency) {
            EmergencyFuelIssued::dispatch($vehicle, $row->id, (float) $row->amount, $recoverable, $row->emergency_reason);
        }

        if ($consumption['exception']) {
            Log::channel('stos')->warning('Fuel variance outside tolerance', [
                'company_id' => $companyId, 'user_id' => $userId,
                'vehicle_id' => $vehicle->id, 'fuel_transaction_id' => $row->id,
                'efficiency_kmpl' => $consumption['efficiency_kmpl'], 'note' => $consumption['note'],
            ]);
        }

        return $row;
    }

    /** The exception register: fills that did not add up. */
    public function exceptions(int $companyId, ?int $vehicleId = null): array
    {
        return FuelTransaction::forCompany($companyId)
            ->when($vehicleId, fn ($q) => $q->where('vehicle_id', $vehicleId))
            ->where(fn ($q) => $q->where('fuel_exception', true)->orWhere('is_emergency', true))
            ->with('vehicle:id,registration_number')
            ->orderByDesc('id')->limit(100)->get()->all();
    }

    /* ── rules ──────────────────────────────────────────────────── */

    /**
     * An odometer counts up. A reading at or below the last one is a typo or
     * the wrong vehicle, and accepting it corrupts every distance figure after
     * it — including the allocation ranking, which reads these numbers.
     */
    private function assertOdometerMovesForward(?float $odometer, ?FuelTransaction $previous): void
    {
        if ($odometer === null || ! $previous || $previous->odometer === null) {
            return;
        }

        $last = (float) $previous->odometer;

        if ($odometer <= $last) {
            throw new BusinessException(
                'The odometer reading ('.$odometer.' km) is not higher than the last recorded fill ('.$last.' km). Check the reading.'
            );
        }
    }

    /**
     * Distance and km/l for this fill, plus whether it is outside tolerance.
     *
     * The FIRST fill on a vehicle has nothing to measure against — no previous
     * odometer means no distance, which means no efficiency and no exception.
     * Flagging it would put an exception on every new truck on day one.
     */
    private function consumption(Vehicle $vehicle, ?float $odometer, ?FuelTransaction $previous, float $litres): array
    {
        $blank = ['km_driven' => null, 'efficiency_kmpl' => null, 'exception' => false, 'note' => null];

        if ($odometer === null || ! $previous || $previous->odometer === null || $litres <= 0) {
            return $blank;
        }

        $km = round($odometer - (float) $previous->odometer, 1);

        if ($km <= 0) {
            return $blank;
        }

        $kmpl = round($km / $litres, 2);
        $benchmark = (float) (config('stos.fuel.benchmark_kmpl')[$vehicle->vehicle_type] ?? 0);

        if ($benchmark <= 0) {
            return ['km_driven' => $km, 'efficiency_kmpl' => $kmpl, 'exception' => false, 'note' => null];
        }

        $tolerance = (float) config('stos.fuel.variance_tolerance', 0.15);
        $floor = $benchmark * (1 - $tolerance);

        if ($kmpl >= $floor) {
            return ['km_driven' => $km, 'efficiency_kmpl' => $kmpl, 'exception' => false, 'note' => null];
        }

        $shortfall = round((1 - $kmpl / $benchmark) * 100);

        return [
            'km_driven'       => $km,
            'efficiency_kmpl' => $kmpl,
            'exception'       => true,
            // The note says what was expected and what happened, so the
            // exception queue does not need a second query to be understood.
            'note' => "{$kmpl} km/l against a benchmark of {$benchmark} — {$shortfall}% below, over {$km} km.",
        ];
    }

    private function recoveryStatus(bool $isEmergency, ?bool $recoverable): string
    {
        if (! $isEmergency) {
            return 'not_applicable';
        }

        // Nobody has decided yet is its own state — do not default an
        // undecided emergency into the customer's invoice.
        return $recoverable === true ? 'billable' : ($recoverable === false ? 'pending' : 'pending');
    }

    private function previousFill(int $vehicleId, int $companyId): ?FuelTransaction
    {
        return FuelTransaction::forCompany($companyId)
            ->where('vehicle_id', $vehicleId)
            ->whereNotNull('odometer')
            ->orderByDesc('odometer')
            ->first();
    }

    /** The stored receipt, streamed from the private disk. */
    public function receipt(int $fuelId, int $companyId): array
    {
        $row = FuelTransaction::forCompany($companyId)->find($fuelId);

        if (! $row || ! $row->receipt_path || ! Storage::disk('local')->exists($row->receipt_path)) {
            throw new BusinessException('No receipt is stored against that fill.', 404);
        }

        return ['path' => $row->receipt_path, 'disk' => 'local'];
    }
}
