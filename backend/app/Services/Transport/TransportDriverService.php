<?php

namespace App\Services\Transport;

use App\Exceptions\BusinessException;
use App\Exceptions\ResourceNotFoundException;
use App\Models\Transport\TransportDriver;
use App\Models\Transport\TripAssignment;
use App\Models\User;
use App\Support\Transport\DriverAvailability;
use App\Support\Transport\DriverStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Driver master business logic — SNG-TRN-004.
 *
 * ── PLACEHOLDER. THIS FILE IS SCHEDULED FOR DELETION. ────────────────────
 * Held by Person 1 only so the Trip and Order demo has something to allocate.
 * The domain is Person 2's under TM-001 §8; when their Fleet module merges,
 * this is REMOVED, not merged with. Do not add features or refactor it — see
 * TEAM-CONTRACTS.md §1a for the file list and the seam that survives.
 *
 * Closes gap G-1 for drivers: the model carried the audit trait and nothing
 * called it, leaving ticket 004's acceptance — "Required fields, validity dates,
 * AUDIT" — unmet. Same shape as TransportVehicleService and, before it,
 * TransportOrderService.
 *
 * A driver carries THREE state axes and this class treats them differently:
 *   status        lifecycle (BO-009)   — moves via transitionStatusTo()
 *   availability  operational (§44)    — moves via transitionAvailabilityTo()
 *   compliance    derived (CMP §23)    — never set, only computed on the model
 * Neither of the first two is mass-assignable, so an update() can never quietly
 * mark an unavailable driver available.
 */
class TransportDriverService
{
    private const EDITABLE = [
        'driver_code', 'name', 'mobile', 'alternate_mobile', 'hr_employee_id',
        'supplier_id', 'licence_number', 'licence_class',
        'licence_valid_from', 'licence_valid_until',
    ];

    /** @param array<string,mixed> $filters */
    public function list(int $tenantId, array $filters = []): Builder
    {
        return TransportDriver::forTenant($tenantId)
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->withStatus($s))
            ->when($filters['availability'] ?? null, fn ($q, $a) => $q->withAvailability($a))
            ->when($filters['search'] ?? null, fn ($q, $t) => $q->search($t))
            ->when($filters['allocatable'] ?? null, fn ($q) => $q->allocatable())
            ->orderByDesc('id');
    }

    public function find(int $id, int $tenantId): TransportDriver
    {
        $driver = TransportDriver::forTenant($tenantId)->find($id);

        if (! $driver) {
            throw new ResourceNotFoundException('Driver');
        }

        return $driver;
    }

    /** @param array<string,mixed> $data */
    public function create(array $data, int $tenantId, ?User $actor = null): TransportDriver
    {
        return DB::transaction(function () use ($data, $tenantId, $actor) {
            /** @var TransportDriver $driver */
            $driver = TransportDriver::create(array_merge(
                array_intersect_key($data, array_flip(self::EDITABLE)),
                ['tenant_id' => $tenantId, 'created_by' => $actor?->id, 'updated_by' => $actor?->id],
            ));

            // Licence number is recorded, licence documents are not — a scan
            // lives in transport_documents and is audited when it is filed.
            $driver->audit('transport.driver.created', $actor, new: [
                'name'                => $driver->name,
                'driver_code'         => $driver->driver_code,
                'licence_number'      => $driver->licence_number,
                'licence_class'       => $driver->licence_class,
                'licence_valid_until' => $driver->licence_valid_until?->toDateString(),
                'hr_employee_id'      => $driver->hr_employee_id,
                'status'              => $driver->status,
                'availability'        => $driver->availability,
            ]);

            Log::channel('transport')->info('Driver created', [
                'driver_id' => $driver->id, 'tenant_id' => $tenantId, 'user_id' => $actor?->id,
            ]);

            return $driver;
        });
    }

    /** @param array<string,mixed> $data */
    public function update(TransportDriver $driver, array $data, int $tenantId, ?User $actor = null): TransportDriver
    {
        $this->assertTenant($driver, $tenantId);

        $before = $driver->only(self::EDITABLE);

        $driver->fill(array_merge(
            array_intersect_key($data, array_flip(self::EDITABLE)),
            ['updated_by' => $actor?->id],
        ))->save();

        $after = $driver->only(self::EDITABLE);

        $norm = fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : (is_scalar($v) || $v === null ? $v : json_encode($v));
        $changed = array_keys(array_diff_assoc(array_map($norm, $after), array_map($norm, $before)));

        if ($changed !== []) {
            $driver->audit(
                'transport.driver.updated',
                $actor,
                old: array_map($norm, array_intersect_key($before, array_flip($changed))),
                new: array_map($norm, array_intersect_key($after, array_flip($changed))),
            );
        }

        return $driver->fresh();
    }

    /** BO-009 lifecycle. */
    public function transitionStatusTo(TransportDriver $driver, string $status, int $tenantId, ?User $actor = null, ?string $reason = null): TransportDriver
    {
        $this->assertTenant($driver, $tenantId);
        $from = $driver->status;

        if ($from === $status) {
            throw new BusinessException('This driver is already '.DriverStatus::label($status).'.', 422);
        }
        if (! DriverStatus::canTransition($from, $status)) {
            throw new BusinessException(
                'A driver cannot move from '.DriverStatus::label($from).' to '.DriverStatus::label($status).'.',
                422
            );
        }

        $driver->forceFill(['status' => $status, 'updated_by' => $actor?->id])->save();
        $driver->auditTransition('transport.driver.status_changed', $from, $status, $actor, array_filter(['reason' => $reason]));

        Log::channel('transport')->info('Driver status changed', [
            'driver_id' => $driver->id, 'from' => $from, 'to' => $status,
            'tenant_id' => $tenantId, 'user_id' => $actor?->id,
        ]);

        return $driver->fresh();
    }

    /**
     * STOS-DB §44 availability.
     *
     * Only the master-admin moves are declared in DriverAvailability::TRANSITIONS
     * — available ↔ on_leave/absent/suspended/unavailable. `assigned` and
     * `on_trip` belong to SNG-TRN-009 and dispatch, so this method refuses them
     * rather than letting the master quietly reserve a driver.
     */
    public function transitionAvailabilityTo(TransportDriver $driver, string $availability, int $tenantId, ?User $actor = null, ?string $reason = null): TransportDriver
    {
        $this->assertTenant($driver, $tenantId);
        $from = $driver->availability;

        // Allocation owns `assigned` and dispatch owns `on_trip`. The enum
        // declares those moves because they genuinely happen; they just do not
        // happen HERE. Reserving a driver from the master screen would leave a
        // reservation with no assignment row, invisible to the double-booking
        // index that BR-P0-003 depends on.
        if (in_array($availability, DriverAvailability::ALLOCATION_OWNED, true)) {
            throw new BusinessException(
                DriverAvailability::label($availability).' is set by allocating the driver to a trip, not from the driver record.',
                422
            );
        }

        if ($from === $availability) {
            throw new BusinessException('This driver is already '.DriverAvailability::label($availability).'.', 422);
        }
        if (! DriverAvailability::canTransition($from, $availability)) {
            throw new BusinessException(
                'A driver cannot move from '.DriverAvailability::label($from).' to '.DriverAvailability::label($availability).'.',
                422
            );
        }

        $driver->forceFill(['availability' => $availability, 'updated_by' => $actor?->id])->save();
        $driver->auditTransition('transport.driver.availability_changed', $from, $availability, $actor, array_filter(['reason' => $reason]));

        Log::channel('transport')->info('Driver availability changed', [
            'driver_id' => $driver->id, 'from' => $from, 'to' => $availability,
            'tenant_id' => $tenantId, 'user_id' => $actor?->id,
        ]);

        return $driver->fresh();
    }

    /** Audit first, then delete — see TransportVehicleService::delete(). */
    public function delete(TransportDriver $driver, int $tenantId, ?User $actor = null, ?string $reason = null): void
    {
        $this->assertTenant($driver, $tenantId);
        $this->assertNotAssigned($driver, $tenantId);

        DB::transaction(function () use ($driver, $actor, $reason) {
            $driver->audit(
                'transport.driver.deleted',
                $actor,
                old: $driver->only(['name', 'driver_code', 'licence_number', 'status', 'availability']),
                context: array_filter(['reason' => $reason]),
            );

            $driver->delete();
        });

        Log::channel('transport')->warning('Driver deleted', [
            'driver_id' => $driver->id, 'tenant_id' => $tenantId, 'user_id' => $actor?->id,
        ]);
    }

    /**
     * D-14 — a master that is currently crewing a trip cannot be deleted.
     *
     * STOS-DB §156: "Critical relationships must not silently become orphaned."
     * §157 names this exact case among the orphans a system must detect:
     * "vehicle allocation without vehicle". Before this guard, deleting an
     * actively assigned driver left the assignment row pointing at a record no
     * ordinary query could find, while the trip went on reporting itself crewed.
     *
     * BLOCKED rather than CASCADED, deliberately. Releasing an assignment frees
     * a vehicle and a driver and moves a trip's state — performing all of that
     * silently inside a delete would hide one destructive act inside another,
     * and the person deleting a duplicate record is rarely the person who should
     * be deciding a live trip loses its driver.
     *
     * Only an ACTIVE assignment blocks. Released ones are history and must not
     * pin a record forever — that would make the master un-deletable for good.
     *
     * At the SERVICE layer, not the controller, so the rule holds for a console
     * command or a future second caller as much as for the API.
     */
    private function assertNotAssigned(TransportDriver $driver, int $tenantId): void
    {
        $clash = TripAssignment::forTenant($tenantId)
            ->forDriver($driver->id)
            ->active()
            ->with('trip:id,trip_number')
            ->first();

        if ($clash) {
            throw new BusinessException(
                'This driver is currently assigned to trip '
                .($clash->trip?->trip_number ?? '#'.$clash->trip_id)
                .'. Release the assignment first, or deactivate the driver instead.',
                422
            );
        }
    }

    private function assertTenant(TransportDriver $driver, int $tenantId): void
    {
        if ((int) $driver->tenant_id !== $tenantId) {
            Log::channel('transport')->warning('Driver tenant mismatch', [
                'driver_id' => $driver->id, 'tenant_id' => $tenantId,
            ]);

            throw new ResourceNotFoundException('Driver');
        }
    }
}
