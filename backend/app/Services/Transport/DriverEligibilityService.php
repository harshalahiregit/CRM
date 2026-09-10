<?php

namespace App\Services\Transport;

use App\Models\Transport\TransportDocument;
use App\Models\Transport\TransportDriver;
use App\Models\Transport\TransportTrip;
use App\Models\Transport\TripAssignment;
use App\Support\Transport\DriverAvailability;
use App\Support\Transport\DriverComplianceStatus;
use App\Support\Transport\DriverStatus;
use App\Support\Transport\EligibilityVerdict;
use App\Support\Transport\TransportDocumentType;
use Illuminate\Support\Collection;

/**
 * "Which drivers are eligible for this trip?" — SNG-TRN-009 step 5.
 *
 * Requirements served, all P0:
 *   PLN-003  Identify available drivers — "Only eligible drivers suggested"
 *   PLN-004  Validate driver compliance before assignment — "Invalid driver blocked"
 *   PLN-006  Prevent double allocation
 *   BR-P0-004  "Driver unavailable or critical document expired blocks
 *               assignment." Hard. Critical.
 *   BRW-028  "Only drivers with AVAILABLE status may be automatically recommended."
 *   BRW-029  "Driver must have valid required documents. If not: BLOCK ALLOCATION."
 *   CMP §22 (licence, class, expiry, training, medical), §23 (compliance status)
 *
 * ── FIVE CHECKS, EACH WITH ONE ACTIONABLE MESSAGE ─────────────────────────
 * Lifecycle and availability are separate because a driver who has left the
 * company and one who is on leave need different responses. Licence is separate
 * from documents because BR-P0-004 and CMP §22 both name it individually, and
 * "renew the licence" is a different instruction from "obtain a medical".
 * BRWM §70 is explicit about the tone required: "Action: Upload valid licence or
 * assign another eligible driver."
 *
 * ── OPS §19 LISTS NINE FACTORS. THREE ARE BUILT. ──────────────────────────
 * §19: availability, compliance, training, customer requirements, route,
 * workload, performance, proximity, leave.
 *
 *   BUILT     availability (incl. leave — DriverAvailability::ON_LEAVE)
 *             compliance   (licence + documents + CMP §23)
 *             training     (as a required document type, via policy)
 *
 *   EXCLUDED, ruled 2026-09-07, recorded in AllocationScope::EXCLUDED:
 *             workload, performance, proximity — inputs to the recommendation
 *               SCORE (BRW-031), which is PLN-008 and P1.
 *             route, customer requirements — no route or customer-requirement
 *               model exists on either side.
 *             branch (STOS-TEST §32's "wrong branch") — drivers carry no branch,
 *               trips carry nothing to match against, and no document states the
 *               constraint as a rule.
 */
class DriverEligibilityService
{
    public function __construct(private TransportPolicyService $policies)
    {
    }

    /** @param array<string,mixed>|null $policy pre-loaded, to avoid a read per row */
    public function evaluate(TransportDriver $driver, ?TransportTrip $trip, int $tenantId, ?array $policy = null): array
    {
        $policy ??= $this->policies->all($tenantId);
        $window = (int) $policy['compliance.expiring_window_days'];
        $checks = [];

        /* 1 — Lifecycle. BO-009: does this person drive for us at all. */
        $lifecycleOk = in_array($driver->status, DriverStatus::ALLOCATABLE, true);
        $checks[] = EligibilityVerdict::check(
            'lifecycle', 'Driver record active',
            (bool) $policy['driver.check.lifecycle.required'],
            $lifecycleOk,
            $lifecycleOk
                ? 'Active'
                : 'Driver is '.$driver->statusLabel().' — only an active driver can be allocated.',
        );

        /* 2 — Availability. BRW-028, verbatim: "Only drivers with AVAILABLE
              status may be automatically recommended for allocation." */
        $availableOk = in_array($driver->availability, DriverAvailability::ALLOCATABLE, true);
        $checks[] = EligibilityVerdict::check(
            'availability', 'Availability',
            (bool) $policy['driver.check.availability.required'],
            $availableOk,
            $availableOk
                ? 'Available'
                : 'Driver is '.$driver->availabilityLabel().'.',
        );

        /* 3 — Not already spoken for. STOS-DB §199, PLN-006. Ruled a hard block
              on 2026-09-07, matching the vehicle: a person cannot be in two
              places at once, whatever "incompatible" was meant to mean. */
        $clash = TripAssignment::forTenant($tenantId)
            ->forDriver($driver->id)
            ->active()
            ->when($trip, fn ($q) => $q->where('trip_id', '!=', $trip->id))
            ->first();
        $checks[] = EligibilityVerdict::check(
            'assignment', 'Not already assigned',
            (bool) $policy['driver.check.assignment.required'],
            $clash === null,
            $clash === null
                ? 'Free'
                : 'Already assigned to trip #'.$clash->trip_id.' — release that assignment first.',
        );

        /* 4 — Licence. CMP §22, BR-048, BR-P0-004. */
        [$licOk, $licDetail] = $this->licenceVerdict($driver, $window);
        $checks[] = EligibilityVerdict::check(
            'licence', 'Driving licence',
            (bool) $policy['driver.check.licence.required'],
            $licOk,
            $licDetail,
        );

        /* 5 — Documents. BRW-029, CMP §24. */
        [$docsOk, $docsDetail] = $this->documentVerdict($driver->id, $tenantId, $policy);
        $checks[] = EligibilityVerdict::check(
            'documents', 'Compliance documents',
            (bool) $policy['driver.check.documents.required'],
            $docsOk,
            $docsDetail,
        );

        return EligibilityVerdict::make(
            [
                'id' => $driver->id,
                'name' => $driver->name,
                'driver_code' => $driver->driver_code,
                'licence_class' => $driver->licence_class,
                'status' => $driver->status,
                'availability' => $driver->availability,
            ],
            $checks,
            [
                // CMP §23's derived status, reported alongside rather than as a
                // sixth check — it summarises the same facts checks 4 and 5 test,
                // and a UI wants the single word.
                'compliance_status' => $driver->complianceStatus($window),
                'expiring_soon'     => $this->expiringSoon($driver, $tenantId, $window),
            ],
        );
    }

    /**
     * PLN-003 — "Only eligible drivers suggested".
     *
     * Tenant-scoped explicitly at every step, for the same reason as the vehicle
     * listing: this starts from a whole table.
     *
     * @return Collection<int,array<string,mixed>>
     */
    public function candidatesFor(?TransportTrip $trip, int $tenantId, bool $includeIneligible = false): Collection
    {
        if ($trip !== null && (int) $trip->tenant_id !== $tenantId) {
            throw new \App\Exceptions\ResourceNotFoundException('Trip');
        }

        $policy = $this->policies->all($tenantId);

        $drivers = TransportDriver::forTenant($tenantId)
            ->when(! $includeIneligible, fn ($q) => $q->allocatable())
            ->with('documents')
            ->orderBy('name')
            ->get();

        return $drivers
            ->map(fn (TransportDriver $d) => $this->evaluate($d, $trip, $tenantId, $policy))
            ->when(! $includeIneligible, fn (Collection $c) => $c->filter(fn ($d) => $d['eligible']))
            ->values();
    }

    /* ── Individual rules ───────────────────────────────────────────── */

    /** @return array{0:bool,1:string} */
    private function licenceVerdict(TransportDriver $driver, int $window): array
    {
        if (! $driver->hasLicence()) {
            return [false, 'No licence number recorded. Upload a valid licence before allocating.'];
        }
        if ($driver->licenceIsNotYetValid()) {
            return [false, 'Licence is not valid until '.$driver->licence_valid_from->format('d M Y').'.'];
        }
        if ($driver->licenceIsExpired()) {
            return [false, 'Licence expired on '.$driver->licence_valid_until->format('d M Y')
                .'. Upload a valid licence or assign another eligible driver.'];
        }

        $days = $driver->daysUntilLicenceExpiry();
        $class = $driver->licence_class ? ' ('.$driver->licence_class.')' : '';

        if ($days !== null && $days <= $window) {
            return [true, 'Valid'.$class.', but expires in '.$this->days($days).'.'];
        }

        return [true, 'Valid'.$class.($days === null ? '' : ' for another '.$this->days($days)).'.'];
    }

    /** "1 day" / "731 days" — never "day(s)", which is a developer writing. */
    private function days(int $n): string
    {
        return $n.' '.($n === 1 ? 'day' : 'days');
    }

    private function documentCount(int $n): string
    {
        return $n.' '.($n === 1 ? 'document' : 'documents');
    }

    /** @return array{0:bool,1:string} */
    private function documentVerdict(int $driverId, int $tenantId, array $policy): array
    {
        $documents = TransportDocument::forTenant($tenantId)
            ->forDriver($driverId)
            ->active()
            ->get();

        $expired = $documents->filter(fn (TransportDocument $d) => ! $d->isCurrentlyValid());
        if ($expired->isNotEmpty()) {
            return [false, 'Expired or not-yet-valid: '
                .$expired->map(fn ($d) => $d->typeLabel().($d->valid_until ? ' (expired '.$d->valid_until->format('d M Y').')' : ''))
                    ->implode(', ').'. Renew before allocating.'];
        }

        $required = $policy['driver.required_documents'] ?? [];
        $required = is_array($required) ? array_values($required) : [];

        if ($required === []) {
            // "None on file and none required" is two facts a reader has to
            // combine into "nothing is wrong". Say that instead.
            return [true, $documents->isEmpty()
                ? 'No documents are required for this driver.'
                : $this->documentCount($documents->count()).' on file, all valid.'];
        }

        $missing = array_values(array_diff($required, $documents->pluck('document_type')->unique()->all()));

        if ($missing !== []) {
            return [false, 'Missing required: '
                .implode(', ', array_map(fn ($t) => TransportDocumentType::label($t), $missing)).'.'];
        }

        return [true, 'All required documents are on file and valid.'];
    }

    /** FLEET §13 / CMP §18 warning window — advisory, includes the licence. */
    private function expiringSoon(TransportDriver $driver, int $tenantId, int $window): array
    {
        $out = [];

        $licenceDays = $driver->daysUntilLicenceExpiry();
        if ($licenceDays !== null && $licenceDays >= 0 && $licenceDays <= $window) {
            $out[] = ['document_type' => 'licence', 'label' => 'Driving licence', 'days_remaining' => $licenceDays];
        }

        foreach (TransportDocument::forTenant($tenantId)->forDriver($driver->id)->active()->get() as $d) {
            $days = $d->daysUntilExpiry();
            if ($days !== null && $days >= 0 && $days <= $window) {
                $out[] = ['document_type' => $d->document_type, 'label' => $d->typeLabel(), 'days_remaining' => $days];
            }
        }

        return $out;
    }
}
