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
                'assigned_vehicle_id' => $profile?->assigned_vehicle_id,
            ];
        }, $people);

        if (! empty($filters['ready_only'])) {
            $rows = array_values(array_filter($rows, fn ($r) => $r['licence']['state'] === 'valid'
                && ($r['profile']['status'] ?? null) === 'available'));
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
            ],
        ];
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
                'status'         => $data['status'] ?? 'available',
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
        $profile->status = $profile->status ?: 'available';
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
        if (! $profile || ! $profile->licence_expiry) {
            return [
                'state' => 'unknown', 'days_left' => null,
                // Not a pass and not a block: a fleet migrating in from
                // spreadsheets would have nobody able to drive on day one.
                'message' => 'No licence recorded — add one before this driver is allocated.',
            ];
        }

        // A licence is valid THROUGH its expiry date, like every vehicle
        // document (see ComplianceService).
        $daysLeft = (int) now()->startOfDay()->diffInDays(Carbon::parse($profile->licence_expiry)->startOfDay(), false);

        if ($daysLeft < 0) {
            return [
                'state' => 'expired', 'days_left' => $daysLeft,
                'message' => 'Licence expired '.abs($daysLeft).' day'.(abs($daysLeft) === 1 ? '' : 's').' ago — this driver cannot be dispatched.',
            ];
        }

        if ($daysLeft <= self::LICENCE_WARNING_DAYS) {
            return [
                'state' => 'expiring', 'days_left' => $daysLeft,
                'message' => 'Licence expires in '.$daysLeft.' day'.($daysLeft === 1 ? '' : 's').'.',
            ];
        }

        return ['state' => 'valid', 'days_left' => $daysLeft, 'message' => 'Licence valid.'];
    }

    private function presentProfile(DriverProfile $profile): array
    {
        return [
            'id'             => $profile->id,
            'licence_number' => $profile->licence_number,
            'licence_class'  => $profile->licence_class,
            'licence_expiry' => $profile->licence_expiry?->toDateString(),
            'status'         => $profile->status,
            'note'           => $profile->note,
            'assigned_vehicle_id' => $profile->assigned_vehicle_id,
        ];
    }
}
