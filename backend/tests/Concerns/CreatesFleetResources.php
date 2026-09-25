<?php

namespace Tests\Concerns;

use App\Domains\Fleet\Models\DriverProfile;
use App\Domains\Fleet\Models\Vehicle;
use App\Domains\Fleet\Services\VehicleService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fixtures that create the resources allocation actually meets.
 *
 * Transport's tests built their vehicles and drivers through
 * `TransportVehicleService` / `TransportDriverService`, which write the LEGACY
 * masters. After the repoint allocation reads Fleet, so those fixtures created
 * a vehicle that could never be allocated — and the suite was right to go red
 * about it. Repointing the fixtures without moving creation would have made the
 * suite green while leaving that true in the product, which is the worst of the
 * available outcomes.
 *
 * Creation moved to Fleet (the read-only ruling, D-143). These helpers follow.
 *
 * ── NO VOCABULARY TRANSLATION HERE, DELIBERATELY ─────────────────────────
 * Fleet's statuses are UPPERCASE and Transport's are lowercase. It would be
 * easy to accept `VehicleStatus::AVAILABLE` here and quietly upcase it, and
 * that is precisely the accommodation that hid D-136-class bugs: a test helper
 * that smooths over a mismatch stops the mismatch being findable. Callers pass
 * Fleet's own constants, because Fleet's row is what they are making.
 */
trait CreatesFleetResources
{
    /** A Fleet vehicle, through Fleet's own service so its observer fires. */
    protected function fleetVehicle(array $attrs = [], ?int $companyId = null, User|int|null $actor = null): Vehicle
    {
        $companyId ??= $this->fleetCompanyId();
        $userId = $actor instanceof User ? $actor->id : ($actor ?? ($this->actor->id ?? 1));

        return app(VehicleService::class)->create(array_merge([
            // D-54 — monotonic, never random. A random unique identifier makes
            // a collision rare rather than impossible, and a rare failure gets
            // re-run rather than fixed.
            'registration_number' => 'MHFL'.Str::upper(Str::random(2)).TestCase::uniqueSeq(6),
            'vehicle_type' => 'truck',
            'ownership_type' => 'owned',
            'capacity_tonnes' => 25,
        ], $attrs), $companyId, $userId);
    }

    /**
     * A Fleet driver: a person in the local register, plus the profile that
     * carries the licence. Two rows, because Fleet stores no names — a driver
     * is a reference into a directory plus a licence, and the composite
     * (D-134) is what reads both.
     */
    protected function fleetDriver(array $attrs = [], ?int $companyId = null, User|int|null $actor = null): DriverProfile
    {
        $companyId ??= $this->fleetCompanyId();

        $personId = DB::table('stos_drivers')->insertGetId([
            'company_id' => $companyId,
            'name' => $attrs['name'] ?? 'Ramesh '.Str::random(4),
            'phone' => $attrs['phone'] ?? '98'.TestCase::uniqueSeq(8),
            'designation' => 'Driver',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        unset($attrs['name'], $attrs['phone']);

        return DriverProfile::create(array_merge([
            'company_id' => $companyId,
            'source' => 'stos',
            'source_id' => $personId,
            'licence_number' => 'RJ14'.TestCase::uniqueSeq(6),
            'licence_class' => 'HMV',
            'licence_expiry' => now()->addYears(2)->toDateString(),
            'status' => DriverProfile::AVAILABLE,
        ], $attrs));
    }

    /** Move a Fleet vehicle, through the model so the observer fires. */
    protected function moveFleetVehicle(Vehicle $vehicle, string $to): Vehicle
    {
        $vehicle->update(['status' => $to]);

        return $vehicle->fresh();
    }

    /** Move a Fleet driver profile, for the tests that suspend or free one. */
    protected function moveFleetDriver(DriverProfile $driver, string $to): DriverProfile
    {
        $driver->update(['status' => $to]);

        return $driver->fresh();
    }

    /** Whatever the test calls its tenant; every one of them names it differently. */
    private function fleetCompanyId(): int
    {
        foreach (['TENANT_A', 'A', 'COMPANY', 'TENANT'] as $name) {
            if (defined(static::class.'::'.$name)) {
                return constant(static::class.'::'.$name);
            }
        }

        return 1;
    }
}
