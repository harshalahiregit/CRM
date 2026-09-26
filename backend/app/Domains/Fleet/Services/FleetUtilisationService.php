<?php

namespace App\Domains\Fleet\Services;

use App\Domains\Fleet\Integration\TripHistoryReader;
use App\Domains\Fleet\Models\Vehicle;
use Illuminate\Support\Carbon;

/**
 * STOS-FLEET — idle vehicles and fleet utilisation for the executive tower (T-49).
 *
 * STOS-REP is owned by another tower; this is the data it feeds from, computed
 * from real records only. Two questions:
 *
 *   - **Idle now** — which trucks could be working and are not, and for how long.
 *   - **Utilisation over a window** — what share of the time each truck was
 *     actually on a trip.
 *
 * ── WHAT "WORKING" MEANS, AND WHY IT IS THE ASSIGNMENT, NOT THE STATUS ─────
 * A vehicle is working while a trip assignment holds it. Not while its `status`
 * says IN_TRANSIT: that flag is written by the dispatch gateway, which is
 * allowed to fail without stopping a departure (D-146), so it can lag reality.
 * The assignment is what actually commits the truck, so the assignment is what
 * this counts — read through `TripHistoryReader`, the one seam onto Ops' table.
 *
 * ── WHAT UTILISATION IS MEASURED AGAINST, STATED PLAINLY ───────────────────
 * `utilisation_pct = working time ÷ time in the fleet`, over the window. The
 * denominator is time the vehicle EXISTED in the fleet during the window, not
 * time it was roadworthy: Fleet keeps no history of when a truck was in the
 * workshop, so subtracting workshop days would be inventing data. The report
 * therefore returns `working_days` and `days_in_fleet` beside the percentage,
 * so the tower shows a figure whose basis is visible rather than a bare number
 * that quietly overstates how hard an often-broken-down truck was pushed.
 */
class FleetUtilisationService
{
    /** Statuses that explain a truck NOT working — not "idle", unavailable. */
    private const UNAVAILABLE = [
        Vehicle::STATUS_UNDER_MAINTENANCE,
        Vehicle::STATUS_BREAKDOWN,
        Vehicle::STATUS_COMPLIANCE_BLOCKED,
    ];

    public function __construct(private TripHistoryReader $history)
    {
    }

    /**
     * The idle-fleet snapshot: every truck that could take a trip and is not on
     * one, longest-idle first, with a summary the tower renders as tiles.
     */
    public function idleNow(int $companyId): array
    {
        $now = Carbon::now();
        $vehicles = Vehicle::forCompany($companyId)->get([
            'id', 'registration_number', 'vehicle_type', 'ownership_type', 'status', 'created_at',
        ]);

        $busy = array_flip($this->history->busyVehicleIds($companyId));
        $lastRelease = $this->history->lastReleaseByVehicle($companyId);

        $idle = [];
        $working = 0;
        $unavailable = 0;

        foreach ($vehicles as $v) {
            if (isset($busy[$v->id])) {
                $working++;
                continue;
            }

            if (in_array($v->status, self::UNAVAILABLE, true)) {
                $unavailable++;
                continue;
            }

            // Idle. "Since" is when it last came off a trip, or — if it has never
            // been on one — when it was onboarded: a truck bought and never used
            // is the most idle of all, and hiding that behind a null would lose
            // exactly the vehicle the report exists to surface.
            $since = $lastRelease[$v->id] ?? Carbon::parse($v->created_at);
            $daysIdle = (int) $since->copy()->startOfDay()->diffInDays($now->copy()->startOfDay());

            $idle[] = [
                'id'                  => $v->id,
                'registration_number' => $v->registration_number,
                'vehicle_type'        => $v->vehicle_type,
                'ownership_type'      => $v->ownership_type,
                'status'              => $v->status,
                'idle_since'          => $since->toIso8601String(),
                'days_idle'           => $daysIdle,
                'ever_used'           => isset($lastRelease[$v->id]),
            ];
        }

        usort($idle, fn ($a, $b) => $b['days_idle'] <=> $a['days_idle']);

        return [
            'as_of' => $now->toIso8601String(),
            'tiles' => [
                'total'       => $vehicles->count(),
                'working'     => $working,
                'idle'        => count($idle),
                'unavailable' => $unavailable,
                'longest_idle_days' => $idle[0]['days_idle'] ?? 0,
            ],
            'idle' => $idle,
        ];
    }

    /**
     * Per-vehicle utilisation over [$from, $to], worst-utilised first, with a
     * fleet-level summary. Defaults to the last 30 days.
     */
    public function utilisation(int $companyId, ?string $from = null, ?string $to = null): array
    {
        $now = Carbon::now();

        $to = $to ? Carbon::parse($to)->endOfDay() : $now->copy();
        $from = $from ? Carbon::parse($from)->startOfDay() : $to->copy()->subDays(30)->startOfDay();

        // Nothing can be measured past now. A window ending today (or in the
        // future) is measured through this moment, for the denominator as well
        // as the work: counting the rest of today as in-fleet-but-idle would
        // make a truck that is on a trip right now read as less than fully used.
        if ($to->greaterThan($now)) {
            $to = $now->copy();
        }

        // Open assignments (a trip still running) are worked up to that same end.
        $openCap = $to->copy();

        $vehicles = Vehicle::forCompany($companyId)->get([
            'id', 'registration_number', 'vehicle_type', 'ownership_type', 'status', 'created_at',
        ]);

        $intervals = $this->history->workingIntervals($companyId, $from, $to);

        $rows = [];
        $pctSum = 0.0;
        $measured = 0;
        $totalWorkingSeconds = 0;
        $idleThroughout = 0;

        foreach ($vehicles as $v) {
            $inFleetStart = Carbon::parse($v->created_at)->greaterThan($from)
                ? Carbon::parse($v->created_at) : $from->copy();
            $inFleetSeconds = max(0, $to->getTimestamp() - $inFleetStart->getTimestamp());

            // Onboarded after the window closed — it was not in the fleet then,
            // so it has no utilisation to report rather than a misleading 0%.
            if ($inFleetSeconds <= 0) {
                $rows[] = [
                    'id' => $v->id, 'registration_number' => $v->registration_number,
                    'vehicle_type' => $v->vehicle_type, 'ownership_type' => $v->ownership_type,
                    'working_days' => 0.0, 'days_in_fleet' => 0.0, 'utilisation_pct' => null,
                    'in_fleet_for_window' => false,
                ];
                continue;
            }

            $workingSeconds = $this->workedSeconds($intervals[$v->id] ?? [], $from, $to, $openCap);
            $pct = min(100.0, round($workingSeconds / $inFleetSeconds * 100, 1));

            $rows[] = [
                'id' => $v->id, 'registration_number' => $v->registration_number,
                'vehicle_type' => $v->vehicle_type, 'ownership_type' => $v->ownership_type,
                'working_days'    => round($workingSeconds / 86400, 1),
                'days_in_fleet'   => round($inFleetSeconds / 86400, 1),
                'utilisation_pct' => $pct,
                'in_fleet_for_window' => true,
            ];

            $pctSum += $pct;
            $measured++;
            $totalWorkingSeconds += $workingSeconds;
            if ($workingSeconds === 0) {
                $idleThroughout++;
            }
        }

        // Worst first: the point of the report is to find the trucks earning
        // least. Vehicles not in the fleet for the window sink to the bottom.
        usort($rows, function ($a, $b) {
            if ($a['utilisation_pct'] === null) {
                return $b['utilisation_pct'] === null ? 0 : 1;
            }
            if ($b['utilisation_pct'] === null) {
                return -1;
            }

            return $a['utilisation_pct'] <=> $b['utilisation_pct'];
        });

        return [
            'from' => $from->toIso8601String(),
            'to'   => $to->toIso8601String(),
            'summary' => [
                'vehicles_measured'     => $measured,
                'average_utilisation_pct' => $measured > 0 ? round($pctSum / $measured, 1) : null,
                'total_working_days'    => round($totalWorkingSeconds / 86400, 1),
                'idle_through_window'   => $idleThroughout,
            ],
            'vehicles' => $rows,
        ];
    }

    /**
     * Seconds a vehicle worked inside [$from, $to]. Each interval is clipped to
     * the window (and open intervals capped at $openCap), then the set is merged
     * before summing, so two overlapping assignments — which messy data can
     * produce even though the active-unique index forbids two live ones — are
     * not counted twice.
     *
     * @param  array<int, array{start: Carbon, end: ?Carbon}>  $intervals
     */
    private function workedSeconds(array $intervals, Carbon $from, Carbon $to, Carbon $openCap): int
    {
        $clipped = [];

        foreach ($intervals as $interval) {
            $start = $interval['start']->greaterThan($from) ? $interval['start'] : $from;
            $rawEnd = $interval['end'] ?? $openCap;
            $end = $rawEnd->lessThan($to) ? $rawEnd : $to;

            if ($end->greaterThan($start)) {
                $clipped[] = [$start->getTimestamp(), $end->getTimestamp()];
            }
        }

        if ($clipped === []) {
            return 0;
        }

        usort($clipped, fn ($a, $b) => $a[0] <=> $b[0]);

        $seconds = 0;
        [$curStart, $curEnd] = $clipped[0];

        foreach (array_slice($clipped, 1) as [$s, $e]) {
            if ($s <= $curEnd) {
                $curEnd = max($curEnd, $e);
            } else {
                $seconds += $curEnd - $curStart;
                [$curStart, $curEnd] = [$s, $e];
            }
        }

        return $seconds + ($curEnd - $curStart);
    }
}
