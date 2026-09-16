<?php

namespace App\Domains\Fleet\Services;

use App\Domains\Fleet\Models\FuelTransaction;
use App\Domains\Fleet\Models\Genset;
use App\Domains\Fleet\Models\MaintenanceJob;
use App\Domains\Fleet\Models\Vehicle;
use App\Domains\Fleet\Models\VehicleLiveStatus;
use App\Exceptions\BusinessException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * STOS-FLEET — Step 1 of the workflow: a truck joins the fleet.
 *
 * This is the record everything else hangs off. Creating it does two things in
 * ONE transaction:
 *
 *   1. `vehicles`            — the static master identity, written once.
 *   2. `vehicle_live_status` — the dynamic row telemetry will overwrite every
 *                              few seconds, initialised empty.
 *
 * Why initialise an empty live row rather than let the first ping create it:
 * the two-tier split exists so high-frequency hardware writes never touch the
 * master table (golden rule 3). Seeding the row here makes ingestion a pure
 * UPDATE on a primary key for the life of the vehicle — no insert path, no
 * race between two pings arriving together, and "one row per vehicle" becomes
 * an invariant instead of something that happens to be true once a device
 * reports. The row carries no readings and no last_ping_at, so every screen
 * still correctly says the vehicle has never reported.
 */
class VehicleService
{
    public function __construct(private ComplianceService $compliance)
    {
    }

    public function create(array $data, int $companyId, int $userId): Vehicle
    {
        $data['registration_number'] = $this->normalisePlate($data['registration_number']);

        $this->assertPlateFree($data['registration_number'], $companyId);
        $this->assertDeviceFree($data['gps_device_id'] ?? null, $companyId);

        $vehicle = DB::transaction(function () use ($data, $companyId) {
            $vehicle = Vehicle::create([...$data, 'company_id' => $companyId]);

            // Step 2 — the tracker's target exists from the moment the truck does.
            VehicleLiveStatus::create([
                'vehicle_id'   => $vehicle->id,
                'company_id'   => $companyId,
                'last_ping_at' => null,
            ]);

            // fresh(): status and compliance_status come from DB defaults, and
            // the unrefreshed instance does not carry them — a create response
            // missing half the record is a trap for whatever reads it next.
            $vehicle = $vehicle->fresh();

            // The verdict is derived from the dates just saved, never typed.
            $this->compliance->refresh($vehicle);

            return $vehicle->fresh();
        });

        Log::channel('stos')->info('Vehicle onboarded', [
            'company_id' => $companyId, 'user_id' => $userId,
            'vehicle_id' => $vehicle->id,
            'registration_number' => $vehicle->registration_number,
            'device' => $vehicle->gps_device_id,
        ]);

        return $vehicle;
    }

    public function update(int $id, array $data, int $companyId, int $userId): Vehicle
    {
        $vehicle = $this->find($id, $companyId);

        if (array_key_exists('registration_number', $data)) {
            $data['registration_number'] = $this->normalisePlate($data['registration_number']);
            $this->assertPlateFree($data['registration_number'], $companyId, $id);
        }

        if (array_key_exists('gps_device_id', $data)) {
            $this->assertDeviceFree($data['gps_device_id'], $companyId, $id);
        }

        // Status is NOT editable here. A vehicle goes into and out of the
        // workshop through its job cards (MaintenanceService), and letting this
        // screen set 'active' directly would put a truck back on the road with
        // its brakes still in pieces.
        unset($data['status']);

        $vehicle->fill($data)->save();

        // Dates may have moved — re-derive the verdict rather than leaving a
        // renewed certificate showing as expired until the nightly sweep.
        $this->compliance->refresh($vehicle);

        Log::channel('stos')->info('Vehicle updated', [
            'company_id' => $companyId, 'user_id' => $userId,
            'vehicle_id' => $vehicle->id, 'changed' => array_keys($data),
        ]);

        return $vehicle->fresh();
    }

    /**
     * Retire a vehicle.
     *
     * Soft delete, never a hard one: fuel spend, job cards and a temperature
     * trail are financial and legal history, and they are meaningless attached
     * to a vehicle_id that no longer resolves to a number plate.
     *
     * The live row goes, because a retired truck has no live state — and that
     * frees the primary key if the same vehicle is ever re-onboarded.
     */
    public function retire(int $id, int $companyId, int $userId): void
    {
        $vehicle = $this->find($id, $companyId);

        $openJobs = MaintenanceJob::forCompany($companyId)
            ->where('vehicle_id', $id)
            ->whereIn('status', MaintenanceJob::OPEN_STATES)
            ->count();

        if ($openJobs > 0) {
            throw new BusinessException(
                'This vehicle still has '.$openJobs.' open job '.($openJobs === 1 ? 'card' : 'cards').'. Close or cancel them before retiring it.'
            );
        }

        DB::transaction(function () use ($vehicle, $id, $companyId) {
            // A genset outlives the truck it was bolted to.
            Genset::forCompany($companyId)->where('vehicle_id', $id)->update(['vehicle_id' => null]);

            VehicleLiveStatus::forCompany($companyId)->where('vehicle_id', $id)->delete();

            $vehicle->update(['status' => 'retired']);
            $vehicle->delete();
        });

        Log::channel('stos')->warning('Vehicle retired', [
            'company_id' => $companyId, 'user_id' => $userId,
            'vehicle_id' => $id, 'registration_number' => $vehicle->registration_number,
        ]);
    }

    /* ── helpers ────────────────────────────────────────────────── */

    private function find(int $id, int $companyId): Vehicle
    {
        $vehicle = Vehicle::forCompany($companyId)->find($id);

        if (! $vehicle) {
            throw new BusinessException('That vehicle is not in your fleet.', 404);
        }

        return $vehicle;
    }

    /**
     * Plates are typed every way a person can type them. Store ONE form, so the
     * unique index actually means something — otherwise "MH 12 AB 1234" and
     * "MH12AB1234" are two trucks with one number plate between them.
     */
    private function normalisePlate(string $plate): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($plate)));
    }

    private function assertPlateFree(string $plate, int $companyId, ?int $exceptId = null): void
    {
        $clash = Vehicle::withTrashed()->forCompany($companyId)
            ->where('registration_number', $plate)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->first(['id', 'deleted_at']);

        if (! $clash) {
            return;
        }

        // A retired vehicle still holds its plate. Say so plainly rather than
        // letting the unique index throw a 500 at the user.
        throw new BusinessException(
            $clash->deleted_at
                ? "{$plate} belongs to a vehicle that was retired. Restore that record instead of creating a second one."
                : "{$plate} is already in your fleet."
        );
    }

    /** One device reports for one vehicle, or ingestion writes the wrong truck. */
    private function assertDeviceFree(?string $deviceId, int $companyId, ?int $exceptId = null): void
    {
        if (! $deviceId) {
            return;
        }

        $taken = Vehicle::withTrashed()->forCompany($companyId)
            ->where('gps_device_id', $deviceId)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->value('registration_number');

        if ($taken) {
            throw new BusinessException("Device {$deviceId} is already fitted to {$taken}.");
        }
    }

    /** What onboarding a vehicle costs us to know: the option lists for the form. */
    public function formOptions(): array
    {
        return [
            'vehicle_types' => Vehicle::TYPES,
            'ownerships'    => Vehicle::OWNERSHIPS,
            'compliance'    => Vehicle::COMPLIANCE,
        ];
    }

    /** Whether anything financial is already attached — shown before retiring. */
    public function attachedActivity(int $id, int $companyId): array
    {
        return [
            'fuel_entries' => FuelTransaction::forCompany($companyId)->where('vehicle_id', $id)->count(),
            'job_cards'    => MaintenanceJob::forCompany($companyId)->where('vehicle_id', $id)->count(),
        ];
    }
}
