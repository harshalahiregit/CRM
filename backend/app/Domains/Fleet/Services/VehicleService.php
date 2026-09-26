<?php

namespace App\Domains\Fleet\Services;

use App\Domains\Fleet\Integration\TripCommitmentReader;
use App\Domains\Fleet\Integration\TripCommitmentUnavailable;
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
    public function __construct(
        private ComplianceService $compliance,
        private TripCommitmentReader $trips,
    ) {
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
        // workshop through its job cards (MaintenanceService), onto a trip
        // through dispatch, and into COMPLIANCE_BLOCKED through the compliance
        // sweep. Letting this screen set AVAILABLE directly would put a truck
        // back on the road with its brakes still in pieces.
        //
        // The deliberate, narrow exception is `transition()` below, which is
        // the only hand-driven edge and accepts only MANUALLY_SETTABLE states.
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
    /**
     * The one hand-driven edge of the vehicle asset state machine (T-56).
     *
     * Fleet is the sole authority for this machine, but almost none of it is a
     * human decision. A truck enters and leaves the workshop through its job
     * cards, goes on and off a trip through dispatch, and is blocked and
     * cleared by the compliance sweep. What is left for a person is the small
     * honest set: parking a truck (IDLE), bringing it back (AVAILABLE), and
     * retiring it.
     *
     * ── EVERY REFUSAL NAMES WHO CAN CLEAR IT ──────────────────────────────
     * "You cannot do that" sends somebody hunting. Each branch below says which
     * desk owns the state and what actually releases it, because the person
     * hitting this button is usually trying to fix something and needs pointing
     * rather than stopping.
     */
    public function transition(int $id, int $companyId, string $to, int $userId): Vehicle
    {
        $vehicle = $this->find($id, $companyId);
        $to = strtoupper(trim($to));

        if (! in_array($to, Vehicle::MANUALLY_SETTABLE, true)) {
            throw new BusinessException(
                $this->whyNotSettable($to)
            );
        }

        if ($vehicle->status === $to) {
            return $vehicle;    // idempotent; setting a state it already holds is not an error
        }

        // Leaving some states is not a person's decision, whatever they are
        // moving to.
        if ($reason = $this->whatHoldsIt($vehicle)) {
            throw new BusinessException($reason);
        }

        if ($to === Vehicle::STATUS_RETIRED) {
            // Retiring has its own guards — open job cards, the genset that
            // outlives the truck, the live-status row. Routed through the one
            // implementation rather than duplicated here.
            $this->retire($id, $companyId, $userId);

            return $vehicle->fresh();
        }

        $from = $vehicle->status;

        // Through the model, so the observer fires and Developers 1 and 3 hear
        // `fleet.vehicle.status_changed`.
        $vehicle->update(['status' => $to]);

        Log::channel('stos')->info('Vehicle status changed by hand', [
            'company_id' => $companyId, 'user_id' => $userId,
            'vehicle_id' => $vehicle->id, 'from' => $from, 'to' => $to,
        ]);

        return $vehicle->fresh();
    }

    /** Why this target is not something a person may set. */
    private function whyNotSettable(string $to): string
    {
        return match ($to) {
            Vehicle::STATUS_UNDER_MAINTENANCE =>
                'Open a job card instead — that is what takes a vehicle into the workshop, and it records why.',
            Vehicle::STATUS_BREAKDOWN =>
                'Open a job card against the trip instead. A breakdown is a job card raised on the road, not a status somebody types.',
            Vehicle::STATUS_ALLOCATED, Vehicle::STATUS_IN_TRANSIT =>
                'Dispatch puts a vehicle on a trip. Setting this by hand would tell Operations a truck is committed to a trip that does not exist.',
            Vehicle::STATUS_COMPLIANCE_BLOCKED =>
                'Compliance is derived from the document expiry dates. To block a vehicle deliberately, place a compliance hold on it.',
            default =>
                'That is not a vehicle status. A person may set AVAILABLE, IDLE or RETIRED.',
        };
    }

    /**
     * What stops this vehicle being moved by hand at all — and who releases it.
     */
    private function whatHoldsIt(Vehicle $vehicle): ?string
    {
        return match ($vehicle->status) {
            Vehicle::STATUS_UNDER_MAINTENANCE =>
                'This vehicle is in the workshop. Closing its job card is what releases it, and that checks QC and its papers first — which is the point.',
            Vehicle::STATUS_BREAKDOWN =>
                'This vehicle is broken down on the road. Closing the breakdown job card releases it.',
            Vehicle::STATUS_ALLOCATED =>
                'This vehicle is allocated to a trip. Operations has to release it from that trip first.',
            Vehicle::STATUS_IN_TRANSIT =>
                'This vehicle is out on a trip. It comes back when the trip closes.',
            Vehicle::STATUS_COMPLIANCE_BLOCKED =>
                'This vehicle is compliance blocked. Renewing the expired document clears it — the compliance sweep applies and removes this state, so setting it by hand would be overwritten.',
            Vehicle::STATUS_RETIRED =>
                'This vehicle is retired. Reinstating a retired asset is not done from here.',
            default => null,
        };
    }

    public function retire(int $id, int $companyId, int $userId): void
    {
        $vehicle = $this->find($id, $companyId);

        // D-146 — a truck on a live trip is loaded and moving. Retiring it
        // soft-deletes the row every screen on that trip reads from, so the
        // journey in progress loses the asset it is about. The legacy master
        // refused this and the refusal did not come across with the move.
        //
        // D-204 — if the check itself cannot run, refuse: "could not tell" is
        // not "free". Better a retirement that must be retried than one that
        // strands a live trip because the lookup errored.
        try {
            $commitment = $this->trips->forVehicle($id, $companyId);
        } catch (TripCommitmentUnavailable $e) {
            throw new BusinessException(
                'Could not check whether this vehicle is on a trip right now, so it was not retired. Try again in a moment.'
            );
        }

        if ($commitment !== null) {
            throw new BusinessException(
                'This vehicle is on '.$this->trips->describe($commitment).'. '
                .'Release it from the trip first — retiring a vehicle mid-journey '
                .'would take it away from the trip that is using it.'
            );
        }

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

            $vehicle->update(['status' => Vehicle::STATUS_RETIRED]);
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
        // One definition of "the same plate", on the model, so the service's
        // uniqueness check and the derived `registration_normalized` (D-141)
        // can never disagree about what counts as a clash.
        return Vehicle::normaliseRegistration($plate);
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
