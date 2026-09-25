<?php

namespace App\Domains\Fleet\Services;

use App\Domains\Fleet\Contracts\DriverDirectory;
use App\Domains\Fleet\Models\DriverProfile;
use App\Domains\Fleet\Models\Vehicle;
use App\Exceptions\BusinessException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * STOS-FLEET — drivers: the directory, plus what Transport knows about them.
 *
 * The list is the DIRECTORY's, every time. STOS never holds a roll of drivers
 * of its own, so 40 workers added under a vendor in the CRM are simply there
 * the next time somebody opens this screen — no import, no sync job, nothing to
 * go stale.
 *
 * What STOS does hold is a thin overlay per person: licence and availability.
 * Those are Transport facts; the customer directory has no business storing a
 * licence expiry, and Transport has no business storing a name.
 */
class DriverService
{
    /** How far ahead a licence counts as expiring — same window as vehicles. */
    public const LICENCE_WARNING_DAYS = 30;

    public function __construct(private DriverDirectory $directory)
    {
    }

    /** Everyone the directory offers, each with their STOS overlay attached. */
    public function list(int $companyId, array $filters = []): array
    {
        $people = $this->directory->people($companyId, $filters);

        $profiles = DriverProfile::forCompany($companyId)->get()
            ->keyBy(fn (DriverProfile $p) => $p->source.':'.$p->source_id);

        $rows = array_map(function (array $person) use ($profiles) {
            $profile = $profiles->get($person['ref']);

            return [
                ...$person,
                'profile' => $profile ? $this->presentProfile($profile) : null,
                'licence' => $this->licenceVerdict($profile),
                'medical' => $this->medicalVerdict($profile),
                'assigned_vehicle_id' => $profile?->assigned_vehicle_id,
            ];
        }, $people);

        if (! empty($filters['ready_only'])) {
            $rows = array_values(array_filter($rows, fn ($r) => $r['licence']['state'] === 'valid'
                && $r['medical']['state'] !== 'expired'
                && ($r['profile']['status'] ?? null) === DriverProfile::AVAILABLE));
        }

        return [
            'drivers' => $rows,
            // The screen says where these people came from, so nobody wonders
            // why a name they typed into the CRM is or is not here.
            'directory'  => $this->directory->describe(),
            'source'     => class_basename($this->directory),
            'counts'     => [
                'total'     => count($rows),
                'with_profile' => count(array_filter($rows, fn ($r) => $r['profile'] !== null)),
                'licence_expired' => count(array_filter($rows, fn ($r) => $r['licence']['state'] === 'expired')),
                'licence_expiring' => count(array_filter($rows, fn ($r) => $r['licence']['state'] === 'expiring')),
                'unlicensed' => count(array_filter($rows, fn ($r) => $r['licence']['state'] === 'unknown')),
                'medical_expired' => count(array_filter($rows, fn ($r) => $r['medical']['state'] === 'expired')),
                'medical_expiring' => count(array_filter($rows, fn ($r) => $r['medical']['state'] === 'expiring')),
                // Counted separately from `unlicensed` because it is a
                // different job: chasing a certificate nobody has captured yet,
                // not one that has run out.
                'medical_unrecorded' => count(array_filter($rows, fn ($r) => $r['medical']['state'] === 'unknown')),
            ],
        ];
    }

    /**
     * Who can legally take a load right now, and who cannot — and why.
     *
     * ── WHY THIS EXISTS, AND WHY IT IS NOT ON THE VEHICLE ─────────────────
     * An expired licence used to be reported against the VEHICLE, as a flag on
     * the allocation result that scored the truck down. Person 1 pointed out
     * that this is the wrong object: the truck is roadworthy and nothing about
     * it has expired. Standing it down over a driver's paperwork means a
     * dispatcher is offered a worse vehicle to solve a problem that swapping
     * drivers fixes in seconds.
     *
     * So the licence is a HARD BLOCK here, on the person it belongs to, in the
     * same `blockers[{code, why, owner}]` shape `getEligibleVehicles()` uses —
     * so one dispatch board can render both with one component, and `owner`
     * tells the dispatcher whose desk fixes it rather than only that it is
     * blocked.
     */
    public function eligible(int $companyId, array $filters = []): array
    {
        $rows = $this->list($companyId, $filters)['drivers'];

        $eligible = [];
        $excluded = [];

        foreach ($rows as $row) {
            $blockers = $this->blockersFor($row);

            if ($blockers === []) {
                $eligible[] = [
                    ...$row,
                    'warnings' => $this->warningsFor($row),
                ];

                continue;
            }

            $excluded[] = [...$row, 'blockers' => $blockers];
        }

        // A driver with a licence about to expire is still offered, but last:
        // a planner given the choice should take the one who will still be
        // legal when the truck comes back.
        usort($eligible, function ($a, $b) {
            return count($a['warnings']) <=> count($b['warnings']);
        });

        return [
            'eligible' => $eligible,
            'excluded' => $excluded,
            'counts'   => ['eligible' => count($eligible), 'excluded' => count($excluded)],
        ];
    }

    /**
     * What stops this person driving today.
     *
     * Every entry names the desk that can clear it. "Blocked" on its own sends
     * a dispatcher hunting; "the compliance desk holds this one" does not.
     */
    private function blockersFor(array $row): array
    {
        $blockers = [];
        $licence = $row['licence']['state'] ?? 'unknown';

        if ($licence === 'expired') {
            $blockers[] = [
                'code'  => 'driver_license_expired',
                'why'   => $row['licence']['message'] ?? 'Licence has expired.',
                'owner' => 'Fleet compliance desk',
            ];
        }

        if ($licence === 'unknown') {
            // Not the same as expired, and deliberately still a block: nobody
            // should be dispatched on a licence nobody has seen. It is cleared
            // by recording one, which is why the owner differs from a renewal.
            $blockers[] = [
                'code'  => 'driver_license_unrecorded',
                'why'   => 'No licence is on file for this driver.',
                'owner' => 'Fleet compliance desk',
            ];
        }

        // T-41 — an EXPIRED medical blocks. A driver whose certificate has
        // run out is a positive statement that they are not currently
        // certified fit, and that is the same kind of fact as a lapsed licence.
        if (($row['medical']['state'] ?? null) === 'expired') {
            $blockers[] = [
                'code'  => 'driver_medical_expired',
                'why'   => $row['medical']['message'] ?? 'Medical certificate has expired.',
                'owner' => 'Fleet compliance desk',
            ];
        }

        $status = $row['profile']['status'] ?? null;

        if ($status === null) {
            $blockers[] = [
                'code'  => 'driver_not_onboarded',
                'why'   => 'This person is in the directory but has no driver record yet.',
                'owner' => 'Fleet office',
            ];
        } elseif ($status !== DriverProfile::AVAILABLE) {
            $blockers[] = [
                'code'  => 'driver_unavailable',
                'why'   => 'This driver is '.str_replace('_', ' ', (string) $status).'.',
                'owner' => 'Fleet office',
            ];
        }

        return $blockers;
    }

    /**
     * Not blocking, but a planner should see it before choosing.
     *
     * ── WHY A MISSING MEDICAL WARNS AND A MISSING LICENCE BLOCKS ──────────
     * They look like the same case and they are not. `licence_expiry` has been
     * captured and enforced since Fleet's first day, so a blank one means
     * nobody has ever seen that person's licence. `medical_expiry` is being
     * introduced NOW, so every driver in the system has a blank one this
     * morning — blocking on it would ground the entire fleet the moment the
     * migration runs, which is a cliff and not a safety measure.
     *
     * It warns loudly instead, and it is counted separately so the gap is
     * visible rather than quietly tolerated. When the certificates are loaded,
     * making the unknown case a blocker is one line here — and that is the
     * owner's call, not a developer's.
     */
    private function warningsFor(array $row): array
    {
        $warnings = [];

        if (($row['licence']['state'] ?? null) === 'expiring') {
            $warnings[] = [
                'code'  => 'driver_license_expiring',
                'why'   => $row['licence']['message'] ?? 'Licence expires soon.',
                'owner' => 'Fleet compliance desk',
            ];
        }

        $medical = $row['medical']['state'] ?? null;

        if ($medical === 'expiring') {
            $warnings[] = [
                'code'  => 'driver_medical_expiring',
                'why'   => $row['medical']['message'] ?? 'Medical certificate expires soon.',
                'owner' => 'Fleet compliance desk',
            ];
        }

        if ($medical === 'unknown') {
            $warnings[] = [
                'code'  => 'driver_medical_unrecorded',
                'why'   => 'No medical certificate on file for this driver.',
                'owner' => 'Fleet compliance desk',
            ];
        }

        return $warnings;
    }

    /**
     * Record what Transport knows about a person from the directory.
     *
     * The person must EXIST in the directory first — an overlay pointing at
     * nobody is how orphaned master data starts.
     */
    public function saveProfile(int $companyId, string $source, int $sourceId, array $data, int $userId): array
    {
        $person = $this->directory->find($companyId, $source, $sourceId);

        if (! $person) {
            throw new BusinessException('That person is not in the directory for this workspace.', 404);
        }

        $profile = DriverProfile::updateOrCreate(
            ['company_id' => $companyId, 'source' => $source, 'source_id' => $sourceId],
            [
                'licence_number' => $data['licence_number'] ?? null,
                'licence_class'  => $data['licence_class'] ?? null,
                'licence_expiry' => $data['licence_expiry'] ?? null,
                'medical_expiry' => $data['medical_expiry'] ?? null,
                'status'         => $data['status'] ?? DriverProfile::AVAILABLE,
                'note'           => $data['note'] ?? null,
            ]
        );

        Log::channel('stos')->info('Driver profile saved', [
            'company_id' => $companyId, 'user_id' => $userId,
            'ref' => $source.':'.$sourceId, 'licence_expiry' => $profile->licence_expiry?->toDateString(),
        ]);

        return [
            ...$person,
            'profile' => $this->presentProfile($profile),
            'licence' => $this->licenceVerdict($profile),
        ];
    }


    /**
     * The driver who regularly takes this vehicle, resolved through the
     * directory so the caller gets a NAME and not just a reference.
     *
     * Returns null when nobody is assigned — which is a legitimate state, not
     * an error: plenty of vehicles are driven by whoever is free that morning.
     */
    public function forVehicle(int $vehicleId, int $companyId): ?array
    {
        $profile = DriverProfile::forCompany($companyId)
            ->where('assigned_vehicle_id', $vehicleId)
            ->first();

        if (! $profile) {
            return null;
        }

        $person = $this->directory->find($companyId, $profile->source, $profile->source_id);

        return [
            // The person may have been removed from the directory since they
            // were assigned. Say so plainly rather than rendering a blank name.
            ...($person ?? [
                'source' => $profile->source, 'source_id' => $profile->source_id,
                'ref' => $profile->ref, 'name' => 'No longer in the directory',
                'phone' => null, 'designation' => null, 'employer' => null,
                'employer_type' => null, 'directory' => 'missing',
            ]),
            'profile' => $this->presentProfile($profile),
            'licence' => $this->licenceVerdict($profile),
            'in_directory' => $person !== null,
        ];
    }

    /** @return array<int, array> the assigned driver keyed by vehicle id */
    public function forVehicles(array $vehicleIds, int $companyId): array
    {
        if ($vehicleIds === []) {
            return [];
        }

        $profiles = DriverProfile::forCompany($companyId)
            ->whereIn('assigned_vehicle_id', $vehicleIds)
            ->get();

        $people = collect($this->directory->people($companyId))->keyBy('ref');

        return $profiles->mapWithKeys(function (DriverProfile $profile) use ($people) {
            $person = $people->get($profile->ref);

            return [(int) $profile->assigned_vehicle_id => [
                'ref'     => $profile->ref,
                'name'    => $person['name'] ?? 'No longer in the directory',
                'phone'   => $person['phone'] ?? null,
                'profile' => $this->presentProfile($profile),
                'licence' => $this->licenceVerdict($profile),
            ]];
        })->all();
    }

    /**
     * Put a driver on a vehicle, or take them off it (pass null).
     *
     * One regular driver per vehicle: assigning somebody displaces whoever was
     * there. Two people both recorded as "the" driver of one truck is a
     * question nobody can answer, and the allocation engine would have to pick
     * one arbitrarily.
     */
    public function assignToVehicle(int $companyId, string $source, int $sourceId, ?int $vehicleId, int $userId): array
    {
        $person = $this->directory->find($companyId, $source, $sourceId);

        if (! $person) {
            throw new BusinessException('That person is not in the directory for this workspace.', 404);
        }

        if ($vehicleId !== null && ! Vehicle::forCompany($companyId)->whereKey($vehicleId)->exists()) {
            throw new BusinessException('That vehicle is not in your fleet.', 404);
        }

        $profile = DriverProfile::firstOrNew(
            ['company_id' => $companyId, 'source' => $source, 'source_id' => $sourceId]
        );

        if ($vehicleId !== null) {
            DriverProfile::forCompany($companyId)
                ->where('assigned_vehicle_id', $vehicleId)
                ->when($profile->exists, fn ($q) => $q->whereKeyNot($profile->id))
                ->update(['assigned_vehicle_id' => null]);
        }

        $profile->assigned_vehicle_id = $vehicleId;
        $profile->status = $profile->status ?: DriverProfile::AVAILABLE;
        $profile->save();

        Log::channel('stos')->info($vehicleId ? 'Driver assigned to vehicle' : 'Driver unassigned', [
            'company_id' => $companyId, 'user_id' => $userId,
            'ref' => $profile->ref, 'vehicle_id' => $vehicleId,
        ]);

        return [
            ...$person,
            'profile' => $this->presentProfile($profile->fresh()),
            'licence' => $this->licenceVerdict($profile->fresh()),
            'assigned_vehicle_id' => $vehicleId,
        ];
    }

    /**
     * Whether this driver may legally be put behind a wheel (Stage 4).
     *
     * Same shape as the vehicle compliance verdict, because a dispatcher asks
     * one question of both: can this go out today?
     */
    public function licenceVerdict(?DriverProfile $profile): array
    {
        return $this->dateVerdict(
            $profile?->licence_expiry,
            'Licence',
            'No licence recorded — add one before this driver is allocated.'
        );
    }

    /**
     * T-41 — the medical certificate, judged exactly like the licence.
     *
     * CMP §22 puts it beside the licence, T-43 already files and versions it,
     * and a VERIFIED certificate now projects onto `medical_expiry`. The same
     * date arithmetic answers both, so the two cannot drift into judging
     * "expired" differently.
     */
    public function medicalVerdict(?DriverProfile $profile): array
    {
        return $this->dateVerdict(
            $profile?->medical_expiry,
            'Medical',
            'No medical certificate recorded.'
        );
    }

    /**
     * Valid THROUGH the date, like every vehicle document (see ComplianceService).
     */
    private function dateVerdict($expiry, string $noun, string $unknownMessage): array
    {
        if (! $expiry) {
            return ['state' => 'unknown', 'days_left' => null, 'message' => $unknownMessage];
        }

        $daysLeft = (int) now()->startOfDay()->diffInDays(Carbon::parse($expiry)->startOfDay(), false);

        if ($daysLeft < 0) {
            return [
                'state' => 'expired', 'days_left' => $daysLeft,
                'message' => $noun.' expired '.abs($daysLeft).' day'.(abs($daysLeft) === 1 ? '' : 's')
                    .' ago — this driver cannot be dispatched.',
            ];
        }

        if ($daysLeft <= self::LICENCE_WARNING_DAYS) {
            return [
                'state' => 'expiring', 'days_left' => $daysLeft,
                'message' => $noun.' expires in '.$daysLeft.' day'.($daysLeft === 1 ? '' : 's').'.',
            ];
        }

        return ['state' => 'valid', 'days_left' => $daysLeft, 'message' => $noun.' valid.'];
    }

    private function presentProfile(DriverProfile $profile): array
    {
        return [
            'id'             => $profile->id,
            'licence_number' => $profile->licence_number,
            'licence_class'  => $profile->licence_class,
            'licence_expiry' => $profile->licence_expiry?->toDateString(),
            'medical_expiry' => $profile->medical_expiry?->toDateString(),
            'status'         => $profile->status,
            'note'           => $profile->note,
            'assigned_vehicle_id' => $profile->assigned_vehicle_id,
        ];
    }
}
