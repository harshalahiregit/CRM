<?php

namespace App\Services\Transport;

use App\Models\Transport\TransportDocument;
use App\Domains\Fleet\Models\DriverProfile;
use App\Domains\Fleet\Services\DriverService;
use App\Models\Transport\TransportTrip;
use App\Models\Transport\TripAssignment;
use App\Support\Transport\DriverAvailability;
use App\Support\Transport\DriverComplianceStatus;
use App\Support\Transport\DriverStatus;
use App\Support\Transport\EligibilityVerdict;
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
    public function __construct(
        private TransportPolicyService $policies,
        private DriverService $drivers,
    ) {
    }

    /**
     * Fleet's verdict on every driver, read once per company per request.
     *
     * `DriverService::eligible()` reads the whole directory — TPV workforce,
     * purchase workforce, vendor contacts, customer contacts — and then the
     * profiles. `candidatesFor()` needs it once, and `evaluate()` needs it per
     * driver, so without this a fleet of 200 read that directory 201 times to
     * answer one screen. Two drivers hid it completely in dev.
     *
     * Per instance, not static: the service is resolved per request, so this
     * cannot serve one company's directory to the next request.
     *
     * @var array<int,array<string,mixed>>
     */
    private array $fleetCache = [];

    /**
     * ── REPOINTED ONTO FLEET, 2026-09-23 (D-100 / D-109) ─────────────────
     *
     * This read `transport_drivers` and asked two questions of it directly:
     * `status` against `DriverStatus::ALLOCATABLE`, and `availability` against
     * `DriverAvailability::ALLOCATABLE`.
     *
     * Neither survives the repoint, and lower-casing does not save them the way
     * it saves the vehicle half: **`driver_profiles` has no `availability`
     * column at all.** `$driver->availability` is simply null on a Fleet
     * profile, so the check would read "Driver is ." and pass or fail on an
     * absence — and the licence and medical rules live in Fleet now anyway.
     *
     * So the driver-record questions are asked of `DriverService::eligible()`,
     * which is where Fleet keeps them: licence expired, licence unrecorded,
     * medical expired, not onboarded, not AVAILABLE. One rule set, one place,
     * and each blocker already names the desk that can clear it.
     *
     * **The assignment clash stays here**, because Fleet does not know about
     * trips. A driver can be perfectly fit to drive and still be on another
     * load, and that is Transport's question to ask.
     *
     * @param  array<string,mixed>|null  $policy  pre-loaded, to avoid a read per row
     */
    public function evaluate(DriverProfile $driver, ?TransportTrip $trip, int $tenantId, ?array $policy = null): array
    {
        $policy ??= $this->policies->all($tenantId);

        $row = $this->fleetRow($driver->id, $tenantId);
        $checks = [];

        /* 1 — Fit to drive at all. Fleet's rules, verbatim: licence, medical
              and the profile's own status. Reported as ONE check carrying
              Fleet's own words, rather than re-derived into ours. */
        $blockers = $row['blockers'] ?? [];
        $fit = $blockers === [];

        // D-150 — Fleet's OWNER is carried through as a field, not flattened
        // into the sentence. It used to be appended in brackets, which made
        // this check's detail a string while its warnings were objects: one
        // response, two shapes, and the screen rendered the string as blank.
        //
        // ── EVERY GROUND, NOT THE FIRST ──────────────────────────────────
        // A collapsed check can only report one failure unless something stops
        // it. Fleet's eligible() returns ALL blockers for a driver — verified,
        // a driver with no licence and no medical comes back with both — so
        // they are all joined here. A dispatcher who fixes one and is then
        // refused for a second nobody mentioned is the failure this avoids.
        $checks[] = EligibilityVerdict::check(
            'fleet', 'Fit to drive',
            (bool) $policy['driver.check.lifecycle.required'],
            $fit,
            $fit
                ? 'Cleared by Fleet'
                : implode(' ', array_column($blockers, 'why')),
            owner: $fit ? null : (collect($blockers)->pluck('owner')->filter()->unique()->implode(', ') ?: null),
        );

        /* 2 — Not already spoken for. STOS-DB §199, PLN-006. Ruled a hard block
              on 2026-09-07, matching the vehicle: a person cannot be in two
              places at once, whatever "incompatible" was meant to mean.
              Transport's question, and the only one Fleet cannot answer. */
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

        return EligibilityVerdict::make(
            [
                'id' => $driver->id,
                'name' => $row['name'] ?? null,
                'licence_class' => $driver->licence_class,
                'status' => $driver->status,
            ],
            $checks,
            [
                // Fleet's warnings pass straight through — a licence about to
                // expire does not block, but a planner should see it before
                // choosing. Re-wording them here would be a second opinion.
                'warnings' => $row['warnings'] ?? [],
            ],
        );
    }

    /**
     * PLN-003 — "Only eligible drivers suggested".
     *
     * Fleet decides who may drive; we then remove whoever is already on a load.
     * Company-scoped by DriverService and trip-scoped here.
     *
     * @return Collection<int,array<string,mixed>>
     */
    public function candidatesFor(?TransportTrip $trip, int $tenantId, bool $includeIneligible = false): Collection
    {
        if ($trip !== null && (int) $trip->tenant_id !== $tenantId) {
            throw new \App\Exceptions\ResourceNotFoundException('Trip');
        }

        $policy = $this->policies->all($tenantId);
        $fleet = $this->fleetVerdicts($tenantId);

        $rows = $includeIneligible
            ? array_merge($fleet['eligible'], $fleet['excluded'])
            : $fleet['eligible'];

        // A person in the directory with no driver record has no profile id and
        // cannot be allocated to anything. Dropped rather than evaluated, so we
        // never present a candidate the allocation could not accept.
        $ids = array_values(array_filter(array_map(
            fn (array $r) => $r['profile']['id'] ?? null,
            $rows,
        )));

        if ($ids === []) {
            return collect();
        }

        return DriverProfile::forCompany($tenantId)
            ->whereIn('id', $ids)
            ->get()
            ->map(fn (DriverProfile $d) => $this->evaluate($d, $trip, $tenantId, $policy))
            ->when(! $includeIneligible, fn (Collection $c) => $c->filter(fn ($d) => $d['eligible']))
            ->values();
    }

    /**
     * Fleet's verdict on one profile.
     *
     * `DriverService` is organised by PERSON, not by profile, so the row is
     * found by the profile id it carries. A profile Fleet does not offer at all
     * is reported as a blocker rather than as "fine" — an absence is not a pass.
     *
     * @return array<string,mixed>
     */
    private function fleetRow(int $profileId, int $tenantId): array
    {
        $fleet = $this->fleetVerdicts($tenantId);

        foreach (array_merge($fleet['eligible'], $fleet['excluded']) as $row) {
            if ((int) ($row['profile']['id'] ?? 0) === $profileId) {
                return $row;
            }
        }

        return ['blockers' => [[
            'code'  => 'driver_not_in_fleet',
            'why'   => 'This driver is not in the fleet directory.',
            'owner' => 'Fleet office',
        ]]];
    }

    /**
     * Fleet's row for one driver, read NOW — for pre-trip (D-151).
     *
     * Deliberately not through `$fleetCache`. Pre-trip and dispatch
     * revalidation exist to catch what changed since allocation — a licence
     * that lapsed in the yard — and a directory cached earlier in the same
     * process is exactly what must not answer that. See D-152 for the cache.
     *
     * @return array<string,mixed>|null  null when Fleet does not offer this profile at all
     */
    public function fleetRecordNow(int $profileId, int $tenantId): ?array
    {
        $fleet = $this->drivers->eligible($tenantId);

        foreach (array_merge($fleet['eligible'], $fleet['excluded']) as $row) {
            if ((int) ($row['profile']['id'] ?? 0) === $profileId) {
                return $row;
            }
        }

        return null;
    }

    /* ── Individual rules ───────────────────────────────────────────── */

    /** "1 day" / "731 days" — never "day(s)", which is a developer writing. */
    private function days(int $n): string
    {
        return $n.' '.($n === 1 ? 'day' : 'days');
    }


    /** @return array<string,mixed> */
    private function fleetVerdicts(int $tenantId): array
    {
        return $this->fleetCache[$tenantId] ??= $this->drivers->eligible($tenantId);
    }
}
