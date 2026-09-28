<?php

namespace App\Domains\Fleet\Services;

use App\Domains\Fleet\Models\TyreFitment;
use App\Domains\Fleet\Models\TyreMaster;
use App\Domains\Fleet\Models\Vehicle;
use App\Exceptions\BusinessException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * STOS-MAINT — the tyre lifecycle: stock → fitted → inspected → rotated →
 * retreaded → scrapped.
 *
 * A tyre is an asset that moves between vehicles and outlives several of them,
 * so fitments form a CHAIN: fitting opens a row, removing closes it, refitting
 * opens the next. That is what makes cost-per-kilometre answerable for a
 * casing rather than just for a truck.
 */
class TyreService
{
    /** Fit a tyre to a position, closing whatever was there. */
    public function fit(int $companyId, array $data, int $userId): TyreFitment
    {
        $vehicle = $this->vehicle((int) $data['vehicle_id'], $companyId);
        $tyreId = strtoupper(trim((string) $data['tyre_id']));

        // A tyre cannot be in two places. If this casing is fitted elsewhere,
        // it has physically been moved — close that fitment rather than letting
        // the register claim it is on two axles at once.
        $existing = TyreFitment::forCompany($companyId)
            ->where('tyre_id', $tyreId)
            ->whereIn('status', TyreFitment::ON_VEHICLE)
            ->first();

        return DB::transaction(function () use ($existing, $vehicle, $companyId, $data, $tyreId, $userId) {
            if ($existing) {
                $this->closeFitment($existing, $data['odometer_at_fitment'] ?? null, TyreFitment::REMOVED, 'Moved to '.$vehicle->registration_number);
            }

            // Whatever currently occupies the target position comes off too.
            $occupant = TyreFitment::forCompany($companyId)
                ->where('vehicle_id', $vehicle->id)
                ->where('position', $data['position'])
                ->whereIn('status', TyreFitment::ON_VEHICLE)
                ->first();

            if ($occupant && $occupant->tyre_id !== $tyreId) {
                $this->closeFitment($occupant, $data['odometer_at_fitment'] ?? null, TyreFitment::REMOVED, 'Replaced by '.$tyreId);
            }

            $fitment = TyreFitment::create([
                'company_id'          => $companyId,
                'tyre_id'             => $tyreId,
                // T-36 — the casing is an asset now, and a fitment that does
                // not point at one leaves a hole in cost-per-km. Found or
                // created from the serial, because the fitment IS the evidence
                // the casing exists: refusing an unregistered tyre would stop a
                // yard fitting a spare at six in the morning.
                'tyre_master_id'      => $this->master($companyId, $tyreId)->id,
                'vehicle_id'          => $vehicle->id,
                'position'            => $data['position'],
                'status'              => TyreFitment::FITTED,
                'tread_depth'         => $data['tread_depth'] ?? null,
                'odometer_at_fitment' => $data['odometer_at_fitment'] ?? null,
                'fitted_on'           => $data['fitted_on'] ?? now()->toDateString(),
                'note'                => $data['note'] ?? null,
            ]);

            TyreMaster::forCompany($companyId)->whereKey($fitment->tyre_master_id)
                ->update(['status' => TyreMaster::FITTED]);

            Log::channel('stos')->info('Tyre fitted', [
                'company_id' => $companyId, 'user_id' => $userId,
                'vehicle_id' => $vehicle->id, 'tyre_id' => $tyreId, 'position' => $fitment->position,
            ]);

            return $fitment;
        });
    }

    /** Record a tread-depth inspection against the current fitment. */
    public function inspect(int $fitmentId, int $companyId, array $data, int $userId): TyreFitment
    {
        $fitment = $this->fitment($fitmentId, $companyId);

        $depth = (float) $data['tread_depth'];

        // Tread only ever goes down. A deeper reading than last time is a
        // mis-keyed measurement or the wrong tyre, and accepting it makes the
        // wear rate — and every replacement forecast built on it — nonsense.
        if ($fitment->tread_depth !== null && $depth > (float) $fitment->tread_depth) {
            throw new BusinessException(
                'Tread depth cannot increase: last reading was '.(float) $fitment->tread_depth.' mm. Check the measurement.'
            );
        }

        $fitment->fill([
            'tread_depth'  => $depth,
            'inspected_on' => $data['inspected_on'] ?? now()->toDateString(),
            'note'         => $data['note'] ?? $fitment->note,
        ])->save();

        if ($fitment->isWornOut()) {
            Log::channel('stos')->warning('Tyre at or below the legal limit', [
                'company_id' => $companyId, 'user_id' => $userId,
                'vehicle_id' => $fitment->vehicle_id, 'tyre_id' => $fitment->tyre_id,
                'tread_depth' => $depth, 'limit' => TyreFitment::MIN_TREAD_MM,
            ]);
        }

        return $fitment->fresh();
    }

    /** Take a tyre off: to stock, to a retreader, or to scrap. */
    public function remove(int $fitmentId, int $companyId, array $data, int $userId): TyreFitment
    {
        $fitment = $this->fitment($fitmentId, $companyId);

        if (! in_array($fitment->status, TyreFitment::ON_VEHICLE, true)) {
            throw new BusinessException('That tyre is not currently fitted.');
        }

        $outcome = $data['status'] ?? TyreFitment::REMOVED;

        if (! in_array($outcome, TyreFitment::OUTCOMES, true)) {
            throw new BusinessException('A removed tyre goes to stock, to a retread, or to scrap.');
        }

        $this->closeFitment($fitment, $data['odometer_at_removal'] ?? null, $outcome, $data['note'] ?? null);

        Log::channel('stos')->info('Tyre removed', [
            'company_id' => $companyId, 'user_id' => $userId,
            'tyre_id' => $fitment->tyre_id, 'outcome' => $outcome,
            'km_run' => $fitment->fresh()->kilometresRun(),
        ]);

        return $fitment->fresh();
    }

    /** What is on this vehicle now, plus what has come off it. */
    public function forVehicle(int $vehicleId, int $companyId): array
    {
        $rows = TyreFitment::forCompany($companyId)
            ->where('vehicle_id', $vehicleId)
            ->orderByDesc('id')->limit(100)->get();

        $fitted = $rows->whereIn('status', TyreFitment::ON_VEHICLE)->values();
        $closed = $rows->whereNotIn('status', TyreFitment::ON_VEHICLE)->values();

        // T-37 — a closed fitment whose casing is STILL on this vehicle is a
        // change of position, not a tyre that came off. Rotation closes both
        // fitments and reopens them, and before this split the panel showed
        // those two closed rows as "2 off the vehicle" under "Removed casings":
        // nothing had been removed, two tyres had swapped places, and the
        // screen said the opposite. Found by rotating two tyres in the browser.
        //
        // Decided from the data, not by reading "Rotated to" out of a note: the
        // casing identity is the fact, the note is only a description of it.
        $stillOn = $fitted->map(fn (TyreFitment $t) => $this->casingKey($t))->flip();

        [$moved, $gone] = $closed->partition(fn (TyreFitment $t) => $stillOn->has($this->casingKey($t)));

        return [
            'fitted'  => $fitted->map(fn (TyreFitment $t) => $this->present($t))->all(),
            // Casings that have left this vehicle — stock, retreader or scrap.
            'history' => $gone->values()->map(fn (TyreFitment $t) => $this->present($t))->all(),
            // Earlier positions of casings still fitted here: the rotation trail.
            'moves'   => $moved->values()->map(fn (TyreFitment $t) => $this->present($t))->all(),
            'due_replacement' => $fitted->filter(fn (TyreFitment $t) => $t->isWornOut())->count(),
            'min_tread_mm'    => TyreFitment::MIN_TREAD_MM,
            'positions'       => TyreFitment::POSITIONS,
        ];
    }

    /* ── helpers ────────────────────────────────────────────────── */

    /**
     * One casing's identity across its fitments.
     *
     * The master id where there is one; the stamped serial for rows older
     * than T-36, so a rotation recorded before the casing register existed
     * still reads as a move rather than a removal.
     */
    private function casingKey(TyreFitment $t): string
    {
        return $t->tyre_master_id ? 'm:'.$t->tyre_master_id : 's:'.strtoupper((string) $t->tyre_id);
    }

    private function present(TyreFitment $t): array
    {
        return [
            ...$t->toArray(),
            'km_run'    => $t->kilometresRun(),
            'worn_out'  => $t->isWornOut(),
        ];
    }

    /**
     * The casing behind this serial — T-36.
     *
     * A minimal row when nobody has registered it: no cost, no size, which is
     * exactly the "not measured" state and is visible on the register as
     * something to complete. Better an incomplete asset than a fitment
     * pointing at nothing.
     */
    private function master(int $companyId, string $serial): TyreMaster
    {
        return TyreMaster::firstOrCreate(
            ['company_id' => $companyId, 'serial_number' => $serial],
            ['status' => TyreMaster::IN_STOCK]
        );
    }

    private function closeFitment(TyreFitment $fitment, $odometer, string $status, ?string $note): void
    {
        $fitment->fill([
            'status'              => $status,
            'odometer_at_removal' => $odometer ?? $fitment->odometer_at_removal,
            'removed_on'          => now()->toDateString(),
            'note'                => $note ?? $fitment->note,
        ])->save();

        // T-36 — the casing follows its fitment off the truck. The outcome the
        // fitment records IS the casing's new state: a tyre removed to stock is
        // IN_STOCK, one sent to the retreader is RETREADED, a scrapped one is
        // scrapped. Only a casing that is not on something else, because the
        // same serial can legitimately be refitted in the same breath.
        if (! $fitment->tyre_master_id) {
            return;
        }

        $stillOn = TyreFitment::forCompany((int) $fitment->company_id)
            ->where('tyre_master_id', $fitment->tyre_master_id)
            ->whereIn('status', TyreFitment::ON_VEHICLE)
            ->where('id', '!=', $fitment->id)
            ->exists();

        if ($stillOn) {
            return;
        }

        TyreMaster::forCompany((int) $fitment->company_id)
            ->whereKey($fitment->tyre_master_id)
            ->update(['status' => match ($status) {
                TyreFitment::RETREADED => TyreMaster::RETREADED,
                TyreFitment::SCRAPPED  => TyreMaster::SCRAPPED,
                default                => TyreMaster::IN_STOCK,
            }]);
    }

    private function vehicle(int $id, int $companyId): Vehicle
    {
        $vehicle = Vehicle::forCompany($companyId)->find($id);

        if (! $vehicle) {
            throw new BusinessException('That vehicle is not in your fleet.', 404);
        }

        return $vehicle;
    }

    private function fitment(int $id, int $companyId): TyreFitment
    {
        $fitment = TyreFitment::forCompany($companyId)->find($id);

        if (! $fitment) {
            throw new BusinessException('That tyre record does not exist.', 404);
        }

        return $fitment;
    }
}
