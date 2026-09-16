<?php

namespace App\Services\Transport;

use App\Exceptions\BusinessException;
use App\Exceptions\ResourceNotFoundException;
use App\Models\Transport\TransportVehicle;
use App\Models\Transport\TripAssignment;
use App\Models\User;
use App\Support\Transport\VehicleStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Vehicle master business logic — SNG-TRN-003.
 *
 * ── PLACEHOLDER. THIS FILE IS SCHEDULED FOR DELETION. ────────────────────
 * Held by Person 1 only so the Trip and Order demo has something to allocate.
 * The domain is Person 2's under TM-001 §8; when their Fleet module merges,
 * this is REMOVED, not merged with. Do not add features or refactor it — see
 * TEAM-CONTRACTS.md §1a for the file list and the seam that survives.
 *
 * ── WHY THIS CLASS EXISTS BEFORE ITS CONTROLLER ───────────────────────────
 * An audit on 2026-09-07 found gap G-1: TransportVehicle carried the
 * RecordsTransportAudit trait but nothing ever called it, so ticket 003's
 * acceptance criterion — "Unique vehicle identity, document dates, AUDIT" —
 * was unmet. The trait cannot fix that on its own: it deliberately does not hook
 * model events, because an automatic "updated" cannot record WHO or WHY (see
 * RecordsTransportAudit's docblock).
 *
 * So the gate is a service, written now rather than with the controller, so that
 * when the HTTP layer arrives it is already impossible to create a vehicle
 * without an audit row. SEC §110's minimum — timestamp, organization, user,
 * action, entity, entity ID, old value, new value, IP, user agent — is what
 * transport_audit_logs already stores; this class supplies the action and the
 * before/after.
 *
 * $tenantId is a parameter throughout, never read from auth(), matching
 * TransportOrderService.
 */
class TransportVehicleService
{
    /** Columns a caller may set. Mirrors the model's $fillable minus tenancy. */
    private const EDITABLE = [
        'registration_number', 'fleet_number', 'chassis_number', 'engine_number',
        'gps_device_id', 'vehicle_type', 'manufacturer', 'model', 'variant',
        'manufacturing_year', 'purchase_date', 'fuel_type', 'branch',
        'capacity_tonnes', 'ownership_type',
    ];

    /** @param array<string,mixed> $filters */
    public function list(int $tenantId, array $filters = []): Builder
    {
        return TransportVehicle::forTenant($tenantId)
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->withStatus($s))
            ->when($filters['search'] ?? null, fn ($q, $t) => $q->search($t))
            ->when($filters['allocatable'] ?? null, fn ($q) => $q->allocatable())
            ->orderByDesc('id');
    }

    public function find(int $id, int $tenantId): TransportVehicle
    {
        $vehicle = TransportVehicle::forTenant($tenantId)->find($id);

        if (! $vehicle) {
            // The same exception a genuinely missing row raises — never "not
            // yours", which would confirm the record exists elsewhere.
            throw new ResourceNotFoundException('Vehicle');
        }

        return $vehicle;
    }

    /** @param array<string,mixed> $data */
    public function create(array $data, int $tenantId, ?User $actor = null): TransportVehicle
    {
        return DB::transaction(function () use ($data, $tenantId, $actor) {
            /** @var TransportVehicle $vehicle */
            $vehicle = TransportVehicle::create(array_merge(
                array_intersect_key($data, array_flip(self::EDITABLE)),
                ['tenant_id' => $tenantId, 'created_by' => $actor?->id, 'updated_by' => $actor?->id],
            ));

            $vehicle->audit('transport.vehicle.created', $actor, new: [
                'registration_number' => $vehicle->registration_number,
                'vehicle_type'        => $vehicle->vehicle_type,
                'ownership_type'      => $vehicle->ownership_type,
                'capacity_tonnes'     => $vehicle->capacity_tonnes,
                'status'              => $vehicle->status,
            ]);

            Log::channel('transport')->info('Vehicle created', [
                'vehicle_id' => $vehicle->id, 'registration' => $vehicle->registration_number,
                'tenant_id' => $tenantId, 'user_id' => $actor?->id,
            ]);

            return $vehicle;
        });
    }

    /**
     * Amend a vehicle's descriptive fields.
     *
     * `status` is not among them — FLEET §8 requires status to move on business
     * events, so it goes through transitionTo() and is audited as a transition
     * rather than as a field diff.
     *
     * @param array<string,mixed> $data
     */
    public function update(TransportVehicle $vehicle, array $data, int $tenantId, ?User $actor = null): TransportVehicle
    {
        $this->assertTenant($vehicle, $tenantId);

        $before = $vehicle->only(self::EDITABLE);

        $vehicle->fill(array_merge(
            array_intersect_key($data, array_flip(self::EDITABLE)),
            ['updated_by' => $actor?->id],
        ))->save();

        $after = $vehicle->only(self::EDITABLE);

        // Only record what actually moved. An audit row saying "nothing changed"
        // is noise that makes the real changes harder to find.
        $changed = array_keys(array_diff_assoc(
            array_map(fn ($v) => is_scalar($v) || $v === null ? $v : json_encode($v), $after),
            array_map(fn ($v) => is_scalar($v) || $v === null ? $v : json_encode($v), $before),
        ));

        if ($changed !== []) {
            $vehicle->audit(
                'transport.vehicle.updated',
                $actor,
                old: array_intersect_key($before, array_flip($changed)),
                new: array_intersect_key($after, array_flip($changed)),
            );
        }

        return $vehicle->fresh();
    }

    /** FLEET §7/§8 — the only way `status` moves. */
    public function transitionTo(TransportVehicle $vehicle, string $status, int $tenantId, ?User $actor = null, ?string $reason = null): TransportVehicle
    {
        $this->assertTenant($vehicle, $tenantId);

        $from = $vehicle->status;

        // See TransportDriverService — allocation and dispatch own these.
        if (in_array($status, VehicleStatus::ALLOCATION_OWNED, true)) {
            throw new BusinessException(
                VehicleStatus::label($status).' is set by allocating the vehicle to a trip, not from the vehicle record.',
                422
            );
        }

        if ($from === $status) {
            throw new BusinessException('This vehicle is already '.VehicleStatus::label($status).'.', 422);
        }

        if (! VehicleStatus::canTransition($from, $status)) {
            throw new BusinessException(
                'A vehicle cannot move from '.VehicleStatus::label($from).' to '.VehicleStatus::label($status).'.',
                422
            );
        }

        $vehicle->forceFill(['status' => $status, 'updated_by' => $actor?->id])->save();

        $vehicle->auditTransition(
            'transport.vehicle.status_changed',
            $from,
            $status,
            $actor,
            array_filter(['reason' => $reason]),
        );

        Log::channel('transport')->info('Vehicle status changed', [
            'vehicle_id' => $vehicle->id, 'from' => $from, 'to' => $status,
            'tenant_id' => $tenantId, 'user_id' => $actor?->id,
        ]);

        return $vehicle->fresh();
    }

    /**
     * Soft-delete a vehicle.
     *
     * The audit row is written BEFORE the delete: transport_audit_logs is
     * append-only and survives the record it describes, so a deletion with no
     * preceding entry is a vehicle that vanishes without trace — the exact thing
     * SNG-TRN-027 exists to prevent.
     */
    public function delete(TransportVehicle $vehicle, int $tenantId, ?User $actor = null, ?string $reason = null): void
    {
        $this->assertTenant($vehicle, $tenantId);
        $this->assertNotAssigned($vehicle, $tenantId);

        DB::transaction(function () use ($vehicle, $actor, $reason) {
            $vehicle->audit(
                'transport.vehicle.deleted',
                $actor,
                old: $vehicle->only(['registration_number', 'vehicle_type', 'status']),
                context: array_filter(['reason' => $reason]),
            );

            $vehicle->delete();
        });

        Log::channel('transport')->warning('Vehicle deleted', [
            'vehicle_id' => $vehicle->id, 'tenant_id' => $tenantId, 'user_id' => $actor?->id,
        ]);
    }

    /**
     * D-14 — a master that is currently crewing a trip cannot be deleted.
     *
     * STOS-DB §156: "Critical relationships must not silently become orphaned."
     * §157 names this exact case among the orphans a system must detect:
     * "vehicle allocation without vehicle". Before this guard, deleting an
     * actively assigned vehicle left the assignment row pointing at a record no
     * ordinary query could find, while the trip went on reporting itself crewed.
     *
     * BLOCKED rather than CASCADED, deliberately. Releasing an assignment frees
     * a vehicle and a driver and moves a trip's state — performing all of that
     * silently inside a delete would hide one destructive act inside another,
     * and the person deleting a duplicate record is rarely the person who should
     * be deciding a live trip loses its vehicle.
     *
     * Only an ACTIVE assignment blocks. Released ones are history and must not
     * pin a record forever — that would make the master un-deletable for good.
     *
     * At the SERVICE layer, not the controller, so the rule holds for a console
     * command or a future second caller as much as for the API.
     */
    private function assertNotAssigned(TransportVehicle $vehicle, int $tenantId): void
    {
        $clash = TripAssignment::forTenant($tenantId)
            ->forVehicle($vehicle->id)
            ->active()
            ->with('trip:id,trip_number')
            ->first();

        if ($clash) {
            throw new BusinessException(
                'This vehicle is currently assigned to trip '
                .($clash->trip?->trip_number ?? '#'.$clash->trip_id)
                .'. Release the assignment first, or retire the vehicle instead.',
                422
            );
        }
    }

    private function assertTenant(TransportVehicle $vehicle, int $tenantId): void
    {
        if ((int) $vehicle->tenant_id !== $tenantId) {
            Log::channel('transport')->warning('Vehicle tenant mismatch', [
                'vehicle_id' => $vehicle->id, 'tenant_id' => $tenantId,
            ]);

            throw new ResourceNotFoundException('Vehicle');
        }
    }
}
