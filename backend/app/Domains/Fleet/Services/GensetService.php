<?php

namespace App\Domains\Fleet\Services;

use App\Domains\Fleet\Models\Genset;
use App\Domains\Fleet\Models\Vehicle;
use App\Exceptions\BusinessException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * STOS-FLEET — the reefer power units, and which truck each is bolted to (T-05).
 *
 * A genset is an asset in its own right, not a property of a vehicle. It has a
 * serial, a service life and a maintenance history that follow the UNIT, and it
 * gets swapped between trailers when one fails. Modelling it as a column on
 * `vehicles` would lose that history the first time it moved.
 *
 * The onboarding form has told people to "fit its genset from the vehicle's
 * passport once it is saved" since the form was written. Until now there was
 * nothing behind that sentence.
 */
class GensetService
{
    public function create(int $companyId, array $data, int $userId): Genset
    {
        $serial = $this->normaliseSerial($data['serial_number'] ?? '');

        if ($serial === '') {
            throw new BusinessException('A genset needs its serial number — it is how the unit is identified when it moves.');
        }

        if (Genset::forCompany($companyId)->where('serial_number', $serial)->exists()) {
            throw new BusinessException("Genset {$serial} is already in this fleet.");
        }

        $genset = Genset::create([
            'company_id'    => $companyId,
            'serial_number' => $serial,
            'status'        => $data['status'] ?? 'idle',
            'vehicle_id'    => null,
        ]);

        // Fitting is a separate, logged act even when it happens in the same
        // request, so the movement history reads the same either way.
        if (! empty($data['vehicle_id'])) {
            return $this->fit($genset->id, $companyId, (int) $data['vehicle_id'], $userId);
        }

        Log::channel('stos')->info('Genset registered', [
            'company_id' => $companyId, 'genset_id' => $genset->id,
            'serial' => $serial, 'user_id' => $userId,
        ]);

        return $genset->fresh();
    }

    public function update(int $gensetId, int $companyId, array $data, int $userId): Genset
    {
        $genset = $this->find($gensetId, $companyId);

        if (array_key_exists('serial_number', $data)) {
            $serial = $this->normaliseSerial($data['serial_number']);

            if ($serial !== '' && $serial !== $genset->serial_number) {
                if (Genset::forCompany($companyId)->where('serial_number', $serial)->where('id', '!=', $genset->id)->exists()) {
                    throw new BusinessException("Genset {$serial} is already in this fleet.");
                }

                $genset->serial_number = $serial;
            }
        }

        if (array_key_exists('status', $data) && $data['status'] !== null) {
            $this->guardStatus($genset, (string) $data['status']);
            $genset->status = $data['status'];
        }

        $genset->save();

        Log::channel('stos')->info('Genset updated', [
            'company_id' => $companyId, 'genset_id' => $genset->id,
            'changed' => array_keys($data), 'user_id' => $userId,
        ]);

        return $genset->fresh();
    }

    /**
     * Bolt this unit to a truck.
     *
     * A genset already fitted elsewhere is MOVED, not refused: that is what
     * physically happens when a unit fails and a spare goes on. The move is
     * logged with both vehicles so the unit's history stays readable.
     */
    public function fit(int $gensetId, int $companyId, int $vehicleId, int $userId): Genset
    {
        $genset = $this->find($gensetId, $companyId);
        $vehicle = $this->vehicle($vehicleId, $companyId);

        if ($genset->status === 'retired') {
            throw new BusinessException('That genset is retired. Reinstate it before fitting it to a vehicle.');
        }

        if ($vehicle->status === 'retired') {
            throw new BusinessException('That vehicle is retired — fitting a working genset to it would strand the unit.');
        }

        $previousVehicleId = $genset->vehicle_id;

        if ($previousVehicleId === $vehicle->id) {
            return $genset;   // already there; fitting twice is not an error
        }

        DB::transaction(function () use ($genset, $vehicle) {
            $genset->vehicle_id = $vehicle->id;

            // A unit that was sitting in the yard is working again. An explicit
            // `in_maintenance` is left alone — fitting does not repair it.
            if ($genset->status === 'idle') {
                $genset->status = 'active';
            }

            $genset->save();
        });

        Log::channel('stos')->info('Genset fitted', [
            'company_id' => $companyId, 'genset_id' => $genset->id,
            'serial' => $genset->serial_number,
            'vehicle_id' => $vehicle->id, 'moved_from_vehicle_id' => $previousVehicleId,
            'user_id' => $userId,
        ]);

        return $genset->fresh();
    }

    /**
     * Take the unit off, without retiring it.
     *
     * The status drops to `idle` rather than staying `active`, because "active"
     * on a unit sitting in the yard reads as working and in use, and the
     * register is what somebody checks before ordering another.
     */
    public function unfit(int $gensetId, int $companyId, int $userId): Genset
    {
        $genset = $this->find($gensetId, $companyId);

        if ($genset->vehicle_id === null) {
            throw new BusinessException('That genset is not fitted to anything.');
        }

        $was = $genset->vehicle_id;

        $genset->update([
            'vehicle_id' => null,
            'status'     => $genset->status === 'active' ? 'idle' : $genset->status,
        ]);

        Log::channel('stos')->info('Genset removed', [
            'company_id' => $companyId, 'genset_id' => $genset->id,
            'serial' => $genset->serial_number, 'was_on_vehicle_id' => $was,
            'user_id' => $userId,
        ]);

        return $genset->fresh();
    }

    /** The register: every unit, where it is, and what it is doing. */
    public function register(int $companyId, array $filters = []): array
    {
        $gensets = Genset::forCompany($companyId)
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['unfitted_only'] ?? null, fn ($q) => $q->whereNull('vehicle_id'))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where('serial_number', 'like', '%'.$term.'%'))
            ->with('vehicle:id,registration_number,vehicle_type,status')
            ->orderByDesc('id')->get();

        $counts = [];
        foreach (Genset::STATUSES as $status) {
            $counts[$status] = 0;
        }

        foreach (Genset::forCompany($companyId)->select('status')->get() as $row) {
            $counts[$row->status] = ($counts[$row->status] ?? 0) + 1;
        }

        return [
            'gensets' => $gensets->all(),
            'counts'  => $counts,
            // Spare capacity: what is available to fit when a unit fails on the
            // road, which is the question the register is usually opened for.
            'spare'   => Genset::forCompany($companyId)
                ->whereNull('vehicle_id')
                ->whereIn('status', ['idle', 'active'])->count(),
            // Reefers running without a power unit on record. Either the genset
            // was never registered, or it came off and nobody said so.
            'reefers_without_genset' => Vehicle::forCompany($companyId)
                ->where('vehicle_type', 'reefer')
                ->where('status', '!=', 'retired')
                ->whereNotIn('id', Genset::forCompany($companyId)->whereNotNull('vehicle_id')->pluck('vehicle_id'))
                ->get(['id', 'registration_number'])->all(),
        ];
    }

    /* ── helpers ────────────────────────────────────────────────── */

    /**
     * Retiring a fitted unit is refused rather than silently unfitting it.
     *
     * Retiring is a decision about an asset; taking it off a truck is a
     * physical act. Doing the second implicitly because somebody asked for the
     * first leaves a reefer that reports no genset and nobody knowing why.
     */
    private function guardStatus(Genset $genset, string $status): void
    {
        if (! in_array($status, Genset::STATUSES, true)) {
            throw new BusinessException('That is not a genset status.');
        }

        if ($status === 'retired' && $genset->vehicle_id !== null) {
            throw new BusinessException('Take the genset off the vehicle before retiring it.');
        }
    }

    /**
     * Serials are stamped on a plate and typed back by eye.
     *
     * Normalised to letters and digits, the same way registration numbers are:
     * "GS-0051" and "GS0051" are one physical unit, and letting both into the
     * register produces two entries for one genset — which is the duplicate
     * master-data problem this module exists to avoid, at the exact moment
     * somebody is trying to find a spare.
     */
    private function normaliseSerial($serial): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper(trim((string) $serial)));
    }

    private function find(int $id, int $companyId): Genset
    {
        $genset = Genset::forCompany($companyId)->find($id);

        if (! $genset) {
            throw new BusinessException('That genset is not in your fleet.', 404);
        }

        return $genset;
    }

    private function vehicle(int $id, int $companyId): Vehicle
    {
        $vehicle = Vehicle::forCompany($companyId)->find($id);

        if (! $vehicle) {
            throw new BusinessException('That vehicle is not in your fleet.', 404);
        }

        return $vehicle;
    }
}
