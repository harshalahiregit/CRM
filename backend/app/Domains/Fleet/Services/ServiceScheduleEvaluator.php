<?php

namespace App\Domains\Fleet\Services;

use App\Domains\Fleet\Models\FuelTransaction;
use App\Domains\Fleet\Models\Vehicle;
use Illuminate\Support\Carbon;

/**
 * STOS-FLEET — is this vehicle due a service? (T-04)
 *
 * The ONE place the answer is computed, for the same reason
 * `VehicleHealthEvaluator` is the one place the traffic light is: two screens
 * that each work it out will eventually disagree, and the one somebody happens
 * to be looking at becomes the truth.
 *
 * ── IT WARNS, IT DOES NOT BLOCK ───────────────────────────────────────────
 * A truck past its interval is still roadworthy. Blocking it would take a
 * working vehicle off the road over an oil change, and the person who could
 * clear it is the same person allocation is trying to help. So this produces a
 * verdict a planner can act on and a flag on the vehicle's row — never an
 * exclusion, and never a `status` value.
 *
 * ── DISTANCE COMES FROM FUEL, NOT TELEMETRY ───────────────────────────────
 * The odometer is read off a fuel receipt, which is a human-entered fact with a
 * date on it. GPS distance drifts, resets when a unit is replaced, and does not
 * survive a box being moved between trucks. For "how far since its service",
 * the receipt is the more trustworthy number even though it is coarser.
 */
class ServiceScheduleEvaluator
{
    /** Warn this far out, so a service can be booked rather than discovered. */
    public const KM_WARNING = 1000;
    public const DAYS_WARNING = 14;

    public const STATE_UNKNOWN  = 'unknown';
    public const STATE_OK       = 'ok';
    public const STATE_DUE_SOON = 'due_soon';
    public const STATE_OVERDUE  = 'overdue';

    /**
     * @param  float|null  $odometer  latest known reading; looked up when omitted
     */
    public function evaluate(Vehicle $vehicle, ?float $odometer = null): array
    {
        $hasKm   = $vehicle->service_interval_km !== null && $vehicle->last_service_odometer !== null;
        $hasDays = $vehicle->service_interval_days !== null && $vehicle->last_service_on !== null;

        if (! $hasKm && ! $hasDays) {
            // Not "ok". A fleet that has never recorded a schedule is not a
            // fleet whose trucks are all freshly serviced, and saying so would
            // be a reassurance nobody earned.
            return [
                'state'   => self::STATE_UNKNOWN,
                'message' => 'No service schedule recorded for this vehicle.',
                'by_km'   => null,
                'by_date' => null,
                'due'     => false,
            ];
        }

        $byKm = $hasKm ? $this->byDistance($vehicle, $odometer ?? $this->lastOdometer($vehicle)) : null;
        $byDate = $hasDays ? $this->byDate($vehicle) : null;

        // Whichever comes first governs — that is how a fleet reads a schedule
        // with both. Taking the kinder of the two would let a truck sit twelve
        // months out of service because it had not driven far.
        $state = $this->worst([$byKm['state'] ?? null, $byDate['state'] ?? null]);

        return [
            'state'   => $state,
            'message' => $this->message($state, $byKm, $byDate),
            'by_km'   => $byKm,
            'by_date' => $byDate,
            'due'     => $state === self::STATE_OVERDUE,
        ];
    }

    /** The flag allocation and the grid carry, or null when there is nothing to say. */
    public function flag(Vehicle $vehicle, ?float $odometer = null): ?string
    {
        return match ($this->evaluate($vehicle, $odometer)['state']) {
            self::STATE_OVERDUE  => 'SERVICE_OVERDUE',
            self::STATE_DUE_SOON => 'SERVICE_DUE_SOON',
            default              => null,
        };
    }

    /* ── the two clocks ─────────────────────────────────────────── */

    private function byDistance(Vehicle $vehicle, ?float $odometer): array
    {
        if ($odometer === null) {
            // An interval with nothing to measure against. Reported as its own
            // case rather than folded into "ok", because the fix is to record
            // an odometer, not to service the truck.
            return [
                'state' => self::STATE_UNKNOWN,
                'since_km' => null, 'remaining_km' => null,
                'why' => 'No odometer reading on record since the last service.',
            ];
        }

        $since = $odometer - (float) $vehicle->last_service_odometer;
        $remaining = (float) $vehicle->service_interval_km - $since;

        return [
            'state' => $remaining <= 0
                ? self::STATE_OVERDUE
                : ($remaining <= self::KM_WARNING ? self::STATE_DUE_SOON : self::STATE_OK),
            'since_km'     => round(max($since, 0), 1),
            'remaining_km' => round($remaining, 1),
            'why' => $remaining <= 0
                ? 'Overdue by '.number_format(abs($remaining), 0).' km.'
                : number_format($remaining, 0).' km until the next service.',
        ];
    }

    private function byDate(Vehicle $vehicle): array
    {
        $due = Carbon::parse($vehicle->last_service_on)->addDays((int) $vehicle->service_interval_days);
        $daysLeft = (int) now()->startOfDay()->diffInDays($due->startOfDay(), false);

        return [
            'state' => $daysLeft < 0
                ? self::STATE_OVERDUE
                : ($daysLeft <= self::DAYS_WARNING ? self::STATE_DUE_SOON : self::STATE_OK),
            'due_on'     => $due->toDateString(),
            'days_left'  => $daysLeft,
            'why' => $daysLeft < 0
                ? 'Overdue by '.abs($daysLeft).' day'.(abs($daysLeft) === 1 ? '' : 's').'.'
                : 'Due in '.$daysLeft.' day'.($daysLeft === 1 ? '' : 's').'.',
        ];
    }

    /**
     * The latest odometer this vehicle reported, from its fuel entries.
     *
     * Ordered by the reading itself rather than by id: entries are back-dated
     * after a trip often enough that the newest ROW is not always the highest
     * reading, and a service interval measured from a lower number would report
     * a truck as fresher than it is.
     */
    private function lastOdometer(Vehicle $vehicle): ?float
    {
        $reading = FuelTransaction::forCompany($vehicle->company_id)
            ->where('vehicle_id', $vehicle->id)
            ->whereNotNull('odometer')
            ->max('odometer');

        return $reading === null ? null : (float) $reading;
    }

    /* ── helpers ────────────────────────────────────────────────── */

    /** Whichever clock is further along governs. */
    private function worst(array $states): string
    {
        $rank = [
            self::STATE_OVERDUE  => 3,
            self::STATE_DUE_SOON => 2,
            self::STATE_OK       => 1,
            self::STATE_UNKNOWN  => 0,
        ];

        $worst = self::STATE_UNKNOWN;

        foreach (array_filter($states) as $state) {
            if (($rank[$state] ?? 0) > ($rank[$worst] ?? 0)) {
                $worst = $state;
            }
        }

        return $worst;
    }

    private function message(string $state, ?array $byKm, ?array $byDate): string
    {
        if ($state === self::STATE_UNKNOWN) {
            return $byKm['why'] ?? $byDate['why'] ?? 'Service schedule cannot be evaluated.';
        }

        // Name the clock that actually triggered it, so the workshop knows
        // whether it is a distance service or a time one.
        foreach ([$byKm, $byDate] as $clock) {
            if (($clock['state'] ?? null) === $state) {
                return $clock['why'];
            }
        }

        return 'Service schedule evaluated.';
    }
}
