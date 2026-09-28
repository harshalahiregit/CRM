<?php

namespace App\Domains\Fleet\Services;

use App\Domains\Fleet\Models\Trailer;
use App\Domains\Fleet\Models\Vehicle;
use App\Domains\Fleet\Models\VehicleTrailerAssignment;
use App\Exceptions\BusinessException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * STOS-FLEET — the trailer register and the coupling between (T-54).
 *
 * CLP §5 lets a client ask for a trailer type on an order, and until this
 * existed there was nowhere for that answer to land: `trailer` is one of seven
 * values of `vehicles.vehicle_type` and has no sub-type beneath it. Faking it
 * with a vehicle row was the alternative, and the migration says why that is
 * worse than it looks.
 *
 * ── WHAT THIS REFUSES, AND WHY EACH ONE ───────────────────────────────────
 * Coupling is a physical act somebody has already performed by the time they
 * reach this screen, so the refusals are about what must not be RECORDED, not
 * about stopping a yard. Each one names the remedy.
 */
class TrailerService
{
    public function __construct(private ComplianceService $compliance)
    {
    }

    /* ── The register ───────────────────────────────────────────────── */

    public function list(int $companyId, array $filters = []): array
    {
        $rows = Trailer::forCompany($companyId)
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['trailer_type'] ?? null, fn ($q, $t) => $q->where('trailer_type', $t))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(
                'registration_normalized', 'like', '%'.Trailer::normalise($term).'%'
            ))
            ->with('activeCoupling.vehicle')
            ->orderBy('trailer_number')
            ->get();

        return [
            'trailers' => $rows->map(fn (Trailer $t) => $this->present($t))->all(),
            'counts'   => [
                'total'     => $rows->count(),
                'available' => $rows->where('status', Trailer::STATUS_AVAILABLE)->count(),
                'coupled'   => $rows->where('status', Trailer::STATUS_COUPLED)->count(),
                'blocked'   => $rows->where('status', Trailer::STATUS_COMPLIANCE_BLOCKED)->count(),
                'off_road'  => $rows->whereIn('status', [
                    Trailer::STATUS_UNDER_MAINTENANCE, Trailer::STATUS_RETIRED,
                ])->count(),
            ],
        ];
    }

    public function register(int $companyId, array $data, ?int $userId = null): Trailer
    {
        $normalised = Trailer::normalise($data['trailer_number'] ?? '');

        if ($normalised === '') {
            throw new BusinessException('A trailer needs its registration number.', 422);
        }

        // Checked here as well as by the unique index, because "that trailer is
        // already on the register" is an answer and a constraint violation is
        // not. Withdrawn trailers are included: re-registering a scrapped one
        // under the same plate is almost always somebody restoring a record,
        // not a new asset.
        $clash = Trailer::withTrashed()->where('company_id', $companyId)
            ->where('registration_normalized', $normalised)->first();

        if ($clash) {
            throw new BusinessException(
                $clash->trashed()
                    ? 'That trailer is on the register but withdrawn. Restore it rather than adding it again.'
                    : 'That trailer is already on the register.',
                422
            );
        }

        $trailer = Trailer::create([
            ...$this->fields($data),
            'company_id' => $companyId,
            'trailer_number' => $data['trailer_number'],
            'status' => $data['status'] ?? Trailer::STATUS_AVAILABLE,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        $this->refreshCompliance($trailer);

        Log::channel('stos')->info('Trailer registered', [
            'company_id' => $companyId, 'trailer_id' => $trailer->id,
            'trailer_number' => $trailer->trailer_number, 'user_id' => $userId,
        ]);

        return $trailer->fresh();
    }

    public function update(int $trailerId, int $companyId, array $data, ?int $userId = null): Trailer
    {
        $trailer = $this->find($trailerId, $companyId);

        if (array_key_exists('status', $data) && $data['status'] !== null) {
            $this->assertSettable($trailer, (string) $data['status']);
        }

        $trailer->fill([...$this->fields($data), 'updated_by' => $userId]);

        if (array_key_exists('trailer_number', $data) && $data['trailer_number']) {
            $trailer->trailer_number = $data['trailer_number'];
        }

        if (array_key_exists('status', $data) && $data['status'] !== null) {
            $trailer->status = $data['status'];
        }

        $trailer->save();

        $this->refreshCompliance($trailer);

        return $trailer->fresh();
    }

    /* ── Coupling ───────────────────────────────────────────────────── */

    /**
     * Put this trailer under this tractor, and record when.
     *
     * Idempotent on the pairing that already exists: coupling the same two
     * again is somebody confirming, not an error.
     */
    public function couple(int $companyId, int $vehicleId, int $trailerId, ?int $userId = null, ?string $reason = null): VehicleTrailerAssignment
    {
        $vehicle = $this->vehicle($vehicleId, $companyId);
        $trailer = $this->find($trailerId, $companyId);

        $existing = VehicleTrailerAssignment::forCompany($companyId)->open()
            ->where('trailer_id', $trailer->id)->first();

        if ($existing && (int) $existing->vehicle_id === $vehicle->id) {
            return $existing;
        }

        if ($existing) {
            throw new BusinessException(
                $trailer->trailer_number.' is already under '
                .($existing->vehicle?->registration_number ?? 'another vehicle')
                .'. Uncouple it there first.',
                422
            );
        }

        $onVehicle = VehicleTrailerAssignment::forCompany($companyId)->open()
            ->where('vehicle_id', $vehicle->id)->first();

        if ($onVehicle) {
            throw new BusinessException(
                $vehicle->registration_number.' is already pulling '
                .($onVehicle->trailer?->trailer_number ?? 'a trailer')
                .'. Uncouple that first.',
                422
            );
        }

        $this->assertCouplable($vehicle, $trailer);

        $assignment = DB::transaction(function () use ($companyId, $vehicle, $trailer, $userId, $reason) {
            $row = VehicleTrailerAssignment::create([
                'company_id' => $companyId,
                'vehicle_id' => $vehicle->id,
                'trailer_id' => $trailer->id,
                'coupled_at' => now(),
                'coupled_by' => $userId,
                'reason'     => $reason,
            ]);

            // COUPLED is written by coupling and cleared by uncoupling. It is
            // not a compliance verdict, so it never overwrites one — a blocked
            // trailer cannot get here in the first place.
            $trailer->update(['status' => Trailer::STATUS_COUPLED, 'updated_by' => $userId]);

            return $row;
        });

        Log::channel('stos')->info('Trailer coupled', [
            'company_id' => $companyId, 'vehicle_id' => $vehicle->id,
            'trailer_id' => $trailer->id, 'assignment_id' => $assignment->id, 'user_id' => $userId,
        ]);

        return $assignment->fresh();
    }

    /** Take it off, and leave the row behind — the history is the point. */
    public function uncouple(int $companyId, int $trailerId, ?int $userId = null, ?string $reason = null): VehicleTrailerAssignment
    {
        $trailer = $this->find($trailerId, $companyId);

        $assignment = VehicleTrailerAssignment::forCompany($companyId)->open()
            ->where('trailer_id', $trailer->id)->first();

        if (! $assignment) {
            throw new BusinessException($trailer->trailer_number.' is not coupled to anything.', 422);
        }

        DB::transaction(function () use ($assignment, $trailer, $userId, $reason) {
            $assignment->update([
                'uncoupled_at' => now(),
                'uncoupled_by' => $userId,
                'reason'       => $reason ?? $assignment->reason,
            ]);

            // Back to AVAILABLE only if COUPLED is what it still says. A
            // trailer sent to the workshop while under a truck comes off into
            // UNDER_MAINTENANCE, not into the yard — uncoupling is not a
            // repair.
            if ($trailer->status === Trailer::STATUS_COUPLED) {
                $trailer->update(['status' => Trailer::STATUS_AVAILABLE, 'updated_by' => $userId]);
            }
        });

        // The papers may have lapsed while it was out. Re-derived on the way
        // back rather than waiting for the nightly sweep, so a blocked trailer
        // cannot be re-coupled in the same hour.
        $this->refreshCompliance($trailer->fresh());

        Log::channel('stos')->info('Trailer uncoupled', [
            'company_id' => $companyId, 'trailer_id' => $trailer->id,
            'assignment_id' => $assignment->id, 'user_id' => $userId,
        ]);

        return $assignment->fresh();
    }

    /**
     * Every pairing this trailer or vehicle has been in, newest first.
     *
     * The question this exists for is "which trailer was under that truck on
     * the 14th", asked when a load spoils or a claim is filed.
     */
    public function history(int $companyId, ?int $vehicleId = null, ?int $trailerId = null, int $limit = 50): array
    {
        $rows = VehicleTrailerAssignment::forCompany($companyId)
            ->when($vehicleId, fn ($q) => $q->where('vehicle_id', $vehicleId))
            ->when($trailerId, fn ($q) => $q->where('trailer_id', $trailerId))
            ->with(['vehicle:id,registration_number', 'trailer:id,trailer_number,trailer_type'])
            ->orderByDesc('coupled_at')->limit($limit)->get();

        return $rows->map(fn (VehicleTrailerAssignment $a) => [
            'id'             => $a->id,
            'vehicle_id'     => $a->vehicle_id,
            'registration_number' => $a->vehicle?->registration_number,
            'trailer_id'     => $a->trailer_id,
            'trailer_number' => $a->trailer?->trailer_number,
            'trailer_type'   => $a->trailer?->trailer_type,
            'coupled_at'     => $a->coupled_at?->toDateTimeString(),
            'uncoupled_at'   => $a->uncoupled_at?->toDateTimeString(),
            'hours_coupled'  => $a->hours_coupled,
            'open'           => $a->uncoupled_at === null,
            'reason'         => $a->reason,
        ])->all();
    }

    /** What is under this tractor right now, or null. */
    public function coupledTo(int $companyId, int $vehicleId): ?array
    {
        $assignment = VehicleTrailerAssignment::forCompany($companyId)->open()
            ->where('vehicle_id', $vehicleId)->with('trailer')->first();

        if (! $assignment || ! $assignment->trailer) {
            return null;
        }

        return [
            ...$this->present($assignment->trailer),
            'coupled_at'    => $assignment->coupled_at?->toDateTimeString(),
            'assignment_id' => $assignment->id,
        ];
    }

    /* ── Compliance ─────────────────────────────────────────────────── */

    /**
     * Re-derive the verdict from the four dates and write it back.
     *
     * Uses `ComplianceService::readExpiries()` — the SAME arithmetic a vehicle
     * is judged by. A trailer has four documents and no PUC, but "expired"
     * means the same thing on both, and two copies of that rule is how one of
     * them eventually decides a day differently.
     */
    public function refreshCompliance(Trailer $trailer): Trailer
    {
        $read = $this->compliance->readExpiries($trailer, Trailer::EXPIRY_DOCUMENTS);

        $status = $read['expired'] !== [] ? 'expired'
            : ($read['expiring'] !== [] ? 'expiring' : 'compliant');

        $updates = ['compliance_status' => $status];

        // A blocked trailer is taken out of the yard, but never out from under
        // a truck that is already on the road: yanking the state mid-journey
        // would strand a load rather than prevent a journey that has started.
        // Same ruling as vehicles.
        if ($status === 'expired' && $trailer->status === Trailer::STATUS_AVAILABLE) {
            $updates['status'] = Trailer::STATUS_COMPLIANCE_BLOCKED;
        }

        if ($status !== 'expired' && $trailer->status === Trailer::STATUS_COMPLIANCE_BLOCKED) {
            $updates['status'] = Trailer::STATUS_AVAILABLE;
        }

        $trailer->update($updates);

        return $trailer->fresh();
    }

    public function complianceFor(int $trailerId, int $companyId): array
    {
        $trailer = $this->find($trailerId, $companyId);

        return [
            ...$this->compliance->readExpiries($trailer, Trailer::EXPIRY_DOCUMENTS),
            'status' => $trailer->compliance_status,
        ];
    }

    /* ── Helpers ────────────────────────────────────────────────────── */

    private function assertCouplable(Vehicle $vehicle, Trailer $trailer): void
    {
        if ($vehicle->status === Vehicle::STATUS_RETIRED) {
            throw new BusinessException(
                $vehicle->registration_number.' is retired. Coupling a working trailer to it would strand the trailer.',
                422
            );
        }

        if ($trailer->status === Trailer::STATUS_RETIRED) {
            throw new BusinessException($trailer->trailer_number.' is retired.', 422);
        }

        if ($trailer->status === Trailer::STATUS_UNDER_MAINTENANCE) {
            throw new BusinessException(
                $trailer->trailer_number.' is in the workshop. Close its job card before putting it back on the road.',
                422
            );
        }

        if ($trailer->status === Trailer::STATUS_COMPLIANCE_BLOCKED) {
            throw new BusinessException(
                $trailer->trailer_number.' has a lapsed document, so the combination could not legally go out. '
                .'Renew and verify it first.',
                422
            );
        }

        if (! in_array($trailer->status, Trailer::COUPLABLE, true)) {
            throw new BusinessException($trailer->trailer_number.' is '.$trailer->status.' and cannot be coupled.', 422);
        }
    }

    private function assertSettable(Trailer $trailer, string $status): void
    {
        if (! in_array($status, Trailer::STATUSES, true)) {
            throw new BusinessException('That is not a trailer status.', 422);
        }

        if (in_array($status, Trailer::MANUALLY_SETTABLE, true)) {
            if ($status === Trailer::STATUS_RETIRED && $trailer->status === Trailer::STATUS_COUPLED) {
                throw new BusinessException(
                    $trailer->trailer_number.' is still under a tractor. Uncouple it before retiring it.',
                    422
                );
            }

            return;
        }

        throw new BusinessException(match ($status) {
            Trailer::STATUS_COUPLED => 'Coupling it to a vehicle is what sets that, not this screen.',
            Trailer::STATUS_COMPLIANCE_BLOCKED => 'That is derived from the document dates and cannot be set by hand.',
            default => 'That status is not set from here.',
        }, 422);
    }

    private function present(Trailer $trailer): array
    {
        $coupling = $trailer->relationLoaded('activeCoupling')
            ? $trailer->activeCoupling
            : $trailer->activeCoupling()->with('vehicle')->first();

        return [
            'id'                  => $trailer->id,
            'trailer_number'      => $trailer->trailer_number,
            'fleet_number'        => $trailer->fleet_number,
            'trailer_type'        => $trailer->trailer_type,
            'ownership_type'      => $trailer->ownership_type,
            'capacity_tonnes'     => $trailer->capacity_tonnes === null ? null : (float) $trailer->capacity_tonnes,
            'axles'               => $trailer->axles,
            'length_feet'         => $trailer->length_feet === null ? null : (float) $trailer->length_feet,
            'status'              => $trailer->status,
            'compliance_status'   => $trailer->compliance_status,
            'registration_expiry' => $trailer->registration_expiry?->toDateString(),
            'fitness_expiry'      => $trailer->fitness_expiry?->toDateString(),
            'insurance_expiry'    => $trailer->insurance_expiry?->toDateString(),
            'permit_expiry'       => $trailer->permit_expiry?->toDateString(),
            'coupled_to'          => $coupling?->vehicle?->registration_number,
            'coupled_to_id'       => $coupling?->vehicle_id,
            'coupled_at'          => $coupling?->coupled_at?->toDateTimeString(),
            'note'                => $trailer->note,
        ];
    }

    /** Only the columns a caller may set — never status, never the normalised plate. */
    private function fields(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'fleet_number', 'trailer_type', 'ownership_type', 'capacity_tonnes',
            'axles', 'length_feet', 'manufacturer', 'model', 'manufacturing_year',
            'purchase_date', 'chassis_number', 'note',
            'registration_expiry', 'fitness_expiry', 'insurance_expiry', 'permit_expiry',
        ]));
    }

    public function find(int $id, int $companyId): Trailer
    {
        $trailer = Trailer::forCompany($companyId)->find($id);

        if (! $trailer) {
            throw new BusinessException('That trailer is not on your register.', 404);
        }

        return $trailer;
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
