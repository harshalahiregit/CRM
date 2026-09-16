<?php

namespace App\Services\Transport;

use App\Models\Transport\TransportDocument;
use App\Models\Transport\TransportTrip;
use App\Models\Transport\TransportVehicle;
use App\Models\Transport\TripAssignment;
use App\Support\Transport\EligibilityVerdict;
use App\Support\Transport\TransportDocumentEntity;
use App\Support\Transport\TransportDocumentType;
use App\Support\Transport\VehicleStatus;
use Illuminate\Support\Collection;

/**
 * "Which vehicles are eligible for this trip?" — SNG-TRN-009 step 5.
 *
 * STOS-FLEET §4 draws the boundary this class sits on:
 *   OPS asks   "Which vehicle can execute this trip?"
 *   FLEET says "Which vehicles are eligible, available, compliant, maintained
 *               and operationally fit?"
 * This is FLEET's answer. It decides nothing — AllocationService (step 6) acts on
 * the verdict — and it writes nothing.
 *
 * Requirements served, all P0:
 *   PLN-002  Identify available vehicles   — "Only eligible vehicles suggested"
 *   PLN-005  Validate vehicle compliance before assignment — "Invalid vehicle blocked"
 *   PLN-006  Prevent double allocation     — "Concurrent assignment controlled"
 *   PLN-001  Plan vehicle requirement      — "Required capacity identified"
 *   BR-P0-003, QA-003, FLEET §8/§14, CMP §21
 *
 * ── FLEET §17 LISTS NINE INPUTS. FOUR ARE BUILT. ──────────────────────────
 * §17's Vehicle Eligibility Engine names: vehicle type, capacity, compliance,
 * maintenance, tyres, Genset, route, customer, service requirement.
 *
 *   BUILT     compliance   (documents + policy)
 *             maintenance  (via VehicleStatus — BRW-044's maintenance block)
 *             capacity     (PLN-001)
 *             availability (BR-P0-003 — §17 omits it, §15 and PLN-002 require it)
 *
 *   EXCLUDED, ruled 2026-09-07, each recorded in AllocationScope::EXCLUDED:
 *             vehicle type / service requirement — the order carries free text
 *               (service_type, special_requirements) and no document specifies a
 *               mapping to a vehicle's capabilities. Any rule would be invented.
 *             route        — no lane or route-capability model exists.
 *             customer     — no customer-requirement model exists.
 *             tyres, Genset — STOS-FLEET domains no P0 ticket owns.
 *
 * Nothing from BR-ALLOC-001 is here: no score, no weights, no ranking, no
 * location, no cost. Those are PLN-007/008, P1.
 */
class VehicleEligibilityService
{
    public function __construct(private TransportPolicyService $policies)
    {
    }

    /**
     * Evaluate one vehicle against one trip.
     *
     * @param  array<string,mixed>|null  $policy  pre-loaded policy, to avoid a
     *         per-row read when evaluating a whole fleet.
     */
    public function evaluate(TransportVehicle $vehicle, ?TransportTrip $trip, int $tenantId, ?array $policy = null): array
    {
        $policy ??= $this->policies->all($tenantId);
        $checks = [];

        /* 1 — Operational status. FLEET §7/§8; BRW-044 blocks a vehicle with
              overdue mandatory maintenance; CMP §21 blocks an expired-compliance
              vehicle. All of those surface here as a status the fleet set. */
        $statusOk = in_array($vehicle->status, VehicleStatus::ALLOCATABLE, true);
        $checks[] = EligibilityVerdict::check(
            'status', 'Operational status',
            (bool) $policy['vehicle.check.status.required'],
            $statusOk,
            $statusOk
                ? 'Vehicle is '.$vehicle->statusLabel()
                : 'Vehicle is '.$vehicle->statusLabel().' — only an Available or Idle vehicle can be allocated.',
        );

        /* 2 — Not already spoken for. BR-P0-003 (Hard/Critical), STOS-DB §198,
              PLN-006, STOS-TEST §33's "assigned elsewhere". */
        $clash = TripAssignment::forTenant($tenantId)
            ->forVehicle($vehicle->id)
            ->active()
            ->when($trip, fn ($q) => $q->where('trip_id', '!=', $trip->id))
            ->first();
        $checks[] = EligibilityVerdict::check(
            'assignment', 'Not already assigned',
            (bool) $policy['vehicle.check.assignment.required'],
            $clash === null,
            $clash === null
                ? 'Free'
                : 'Already assigned to trip #'.$clash->trip_id.' — release that assignment first.',
        );

        /* 3 — Documents. PLN-005, BR-P0-004, QA-003 ("Expired vehicle document →
              allocation blocked with actionable message", Critical), FLEET §14. */
        [$docsOk, $docsDetail] = $this->documentVerdict($vehicle->id, $tenantId, $policy);
        $checks[] = EligibilityVerdict::check(
            'documents', 'Compliance documents',
            (bool) $policy['vehicle.check.documents.required'],
            $docsOk,
            $docsDetail,
        );

        /* 4 — Capacity. PLN-001; TRP-P0-003 names "payload" as an input. */
        [$capOk, $capRequired, $capDetail] = $this->capacityVerdict($vehicle, $trip, $policy);
        $checks[] = EligibilityVerdict::check(
            'capacity', 'Capacity', $capRequired, $capOk, $capDetail,
        );

        return EligibilityVerdict::make(
            [
                'id' => $vehicle->id,
                'registration_number' => $vehicle->registration_number,
                'vehicle_type' => $vehicle->vehicle_type,
                'capacity_tonnes' => $vehicle->capacity_tonnes,
                'status' => $vehicle->status,
            ],
            $checks,
            ['expiring_soon' => $this->expiringSoon($vehicle->id, $tenantId, $policy)],
        );
    }

    /**
     * PLN-002 — "Only eligible vehicles suggested".
     *
     * Every query is tenant-scoped explicitly. This is the method most likely to
     * leak across tenants, because it is the only one that starts from a whole
     * table rather than from a record the caller already resolved.
     *
     * @return Collection<int,array<string,mixed>>
     */
    public function candidatesFor(?TransportTrip $trip, int $tenantId, bool $includeIneligible = false): Collection
    {
        if ($trip !== null && (int) $trip->tenant_id !== $tenantId) {
            // Never evaluate another tenant's trip against this tenant's fleet.
            throw new \App\Exceptions\ResourceNotFoundException('Trip');
        }

        $policy = $this->policies->all($tenantId);

        // Status is narrowed in SQL so a large fleet is not fully hydrated, but
        // the authoritative status check still runs in evaluate() — the query is
        // an optimisation, never the rule.
        $vehicles = TransportVehicle::forTenant($tenantId)
            ->when(! $includeIneligible, fn ($q) => $q->allocatable())
            ->orderBy('registration_number')
            ->get();

        return $vehicles
            ->map(fn (TransportVehicle $v) => $this->evaluate($v, $trip, $tenantId, $policy))
            ->when(! $includeIneligible, fn (Collection $c) => $c->filter(fn ($v) => $v['eligible']))
            ->values();
    }

    /* ── Individual rules ───────────────────────────────────────────── */

    /**
     * CMP §24 — "STOS-CMP determines what is required; STOS-DOC manages the
     * evidence lifecycle." Two separate failures, and they need different
     * messages: a document that is MISSING and one that has LAPSED are fixed by
     * different actions (obtain it vs renew it).
     *
     * @return array{0:bool,1:string}
     */
    private function documentVerdict(int $vehicleId, int $tenantId, array $policy): array
    {
        $documents = TransportDocument::forTenant($tenantId)
            ->forVehicle($vehicleId)
            ->active()
            ->get();

        // Anything on file that has lapsed blocks, required or not — FLEET §14
        // and QA-003 are about expiry, not about the required set.
        $expired = $documents->filter(fn (TransportDocument $d) => ! $d->isCurrentlyValid());
        if ($expired->isNotEmpty()) {
            return [false, 'Expired or not-yet-valid: '
                .$expired->map(fn ($d) => $d->typeLabel().($d->valid_until ? ' (expired '.$d->valid_until->format('d M Y').')' : ''))
                    ->implode(', ').'. Renew before allocating.'];
        }

        // The required set is per-tenant and empty by default (CMP §10).
        $required = $this->requiredDocuments($policy, 'vehicle');
        if ($required === []) {
            return [true, $documents->isEmpty()
                ? 'No documents are required for this vehicle.'
                : $documents->count().' '.($documents->count() === 1 ? 'document' : 'documents').' on file, all valid.'];
        }

        $held = $documents->pluck('document_type')->unique();
        $missing = array_values(array_diff($required, $held->all()));

        if ($missing !== []) {
            return [false, 'Missing required: '
                .implode(', ', array_map(fn ($t) => TransportDocumentType::label($t), $missing)).'.'];
        }

        return [true, 'All '.count($required).' required document(s) present and valid'];
    }

    /**
     * PLN-001 — capacity against the order's stated requirement.
     *
     * Returns its own `required` flag: when the order states no requirement the
     * check is not applicable and must not block. CMP §10's NO ASSUMPTION
     * PRINCIPLE by analogy — an unstated requirement is not a requirement of
     * zero, and a vehicle must not be refused for failing a test nobody set.
     *
     * @return array{0:bool,1:bool,2:string}
     */
    private function capacityVerdict(TransportVehicle $vehicle, ?TransportTrip $trip, array $policy): array
    {
        $needed = $trip?->order?->required_capacity_tonnes;

        if ($needed === null) {
            return [true, false, 'No capacity requirement recorded on this order'];
        }

        if ($vehicle->capacity_tonnes === null) {
            return [false, (bool) $policy['vehicle.check.capacity.required'],
                'Order needs '.rtrim(rtrim((string) $needed, '0'), '.').' t but this vehicle has no capacity recorded.'];
        }

        $ok = (float) $vehicle->capacity_tonnes >= (float) $needed;

        return [$ok, (bool) $policy['vehicle.check.capacity.required'],
            $ok
                ? 'Capacity '.$vehicle->capacity_tonnes.' t meets the '.$needed.' t required'
                : 'Capacity '.$vehicle->capacity_tonnes.' t is below the '.$needed.' t this order requires.'];
    }

    /** FLEET §13's warning window — advisory, never blocking. */
    private function expiringSoon(int $vehicleId, int $tenantId, array $policy): array
    {
        $window = (int) $policy['compliance.expiring_window_days'];

        return TransportDocument::forTenant($tenantId)
            ->forVehicle($vehicleId)
            ->active()
            ->get()
            ->filter(function (TransportDocument $d) use ($window) {
                $days = $d->daysUntilExpiry();

                return $days !== null && $days >= 0 && $days <= $window;
            })
            ->map(fn (TransportDocument $d) => [
                'document_type' => $d->document_type,
                'label' => $d->typeLabel(),
                'days_remaining' => $d->daysUntilExpiry(),
            ])->values()->all();
    }

    /** @return string[] */
    private function requiredDocuments(array $policy, string $entity): array
    {
        $key = $entity === TransportDocumentEntity::DRIVER ? 'driver.required_documents' : 'vehicle.required_documents';
        $value = $policy[$key] ?? [];

        return is_array($value) ? array_values($value) : [];
    }
}
