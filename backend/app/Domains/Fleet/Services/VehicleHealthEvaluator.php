<?php

namespace App\Domains\Fleet\Services;

use App\Domains\Fleet\Models\Vehicle;
use Illuminate\Support\Carbon;

/**
 * STOS-FLEET — the traffic light, and the sentence behind it.
 *
 * Exception-first colour coding lives HERE and nowhere else. A screen must
 * never re-derive "is this vehicle red" from raw columns, because the moment
 * two screens disagree about what red means, the colour stops meaning anything.
 *
 * Every issue answers the four questions a blocked user actually has:
 *   why      — what is wrong, in a sentence
 *   missing  — what specifically is absent or overdue
 *   owner    — whose desk fixes it
 *   next     — the one action that moves it forward
 *
 * Only facts this domain owns are used: compliance flag, live telemetry, job
 * cards. Nothing here guesses at trip or customer state — Dispatch owns that.
 */
class VehicleHealthEvaluator
{
    /** A device that has not reported in this long is considered offline. */
    public const STALE_PING_MINUTES = 30;

    /** Mirrors ComplianceService::WARNING_DAYS — the window used in messages. */
    public const EXPIRY_WARNING_DAYS = 30;

    public const TONE_GREEN = 'green';
    public const TONE_AMBER = 'amber';
    public const TONE_RED   = 'red';

    /**
     * @param  object|null  $live   the vehicle_live_status row, if any
     * @param  int  $openJobs       open workshop job cards
     */
    public function evaluate(Vehicle $vehicle, ?object $live, int $openJobs = 0): array
    {
        $issues = array_values(array_filter([
            $this->complianceIssue($vehicle),
            $this->excursionIssue($vehicle, $live),
            $this->workshopIssue($vehicle, $openJobs),
            $this->telemetryIssue($vehicle, $live),
        ]));

        // Worst tone wins the headline; the rest stay listed so a screen can
        // show "and 2 more" rather than hiding them.
        $tone = self::TONE_GREEN;
        foreach ($issues as $issue) {
            if ($issue['tone'] === self::TONE_RED) {
                $tone = self::TONE_RED;
                break;
            }
            $tone = self::TONE_AMBER;
        }

        usort($issues, fn ($a, $b) => $this->weight($b['tone']) <=> $this->weight($a['tone']));

        return [
            'tone'   => $tone,
            'state'  => $this->operationalState($vehicle, $live, $openJobs),
            'issues' => $issues,
            'headline' => $issues[0]['why'] ?? 'Healthy — compliant, reporting, no open jobs.',
            // The Next-Action Engine: never a dead end. A healthy vehicle still
            // offers the one thing worth doing with it.
            'next' => $issues[0]['next'] ?? ['label' => 'Open passport', 'action' => 'passport'],
        ];
    }

    /**
     * ONE state per vehicle, in priority order, so the dashboard tiles add up
     * to the fleet total instead of double-counting the same truck.
     *
     * Note what is NOT here: "allocated" and "in transit" are trip facts and
     * Dispatch owns trips. Inventing them from telemetry would put a truck "in
     * transit" because the driver moved it across the yard.
     */
    public function operationalState(Vehicle $vehicle, ?object $live, int $openJobs = 0): string
    {
        if ($vehicle->status === Vehicle::STATUS_RETIRED) {
            return 'retired';
        }

        if (in_array($vehicle->compliance_status, ['expired', 'blocked'], true)) {
            return 'compliance_blocked';
        }

        if (in_array($vehicle->status, [Vehicle::STATUS_UNDER_MAINTENANCE, Vehicle::STATUS_BREAKDOWN], true) || $openJobs > 0) {
            return 'maintenance';
        }

        if (! $vehicle->gps_device_id) {
            return 'unmonitored';
        }

        if (! $live || ! $live->last_ping_at || $this->isStale($live->last_ping_at)) {
            return 'offline';
        }

        $moving = ((float) ($live->speed ?? 0)) > 0 || (bool) ($live->ignition ?? false);

        return $moving ? 'moving' : 'idle';
    }

    /* ── individual checks ──────────────────────────────────────── */

    private function complianceIssue(Vehicle $vehicle): ?array
    {
        if (in_array($vehicle->compliance_status, ['expired', 'blocked'], true)) {
            // Name the actual document. "Papers not valid" sends someone
            // hunting through five certificates to find the one that lapsed.
            $lapsed = $this->documentsInState($vehicle, 'expired');

            return [
                'tone'    => self::TONE_RED,
                'code'    => 'compliance_blocked',
                'why'     => 'This vehicle cannot be dispatched — its papers are not valid.',
                'missing' => $vehicle->compliance_hold
                    ? ($vehicle->compliance_hold_reason ?: 'A compliance hold is in force.')
                    : ($lapsed !== []
                        ? 'Expired: '.implode(', ', $lapsed).'.'
                        : 'One or more statutory documents have expired.'),
                'owner'   => 'Fleet compliance desk',
                'next'    => ['label' => 'Review compliance', 'action' => 'passport#compliance'],
            ];
        }

        if ($vehicle->compliance_status === 'expiring') {
            $due = $this->documentsInState($vehicle, 'expiring');

            return [
                'tone'    => self::TONE_AMBER,
                'code'    => 'compliance_expiring',
                'why'     => 'Paperwork is close to expiry — it will block dispatch when it lapses.',
                'missing' => $due !== []
                    ? 'Due within '.self::EXPIRY_WARNING_DAYS.' days: '.implode(', ', $due).'.'
                    : 'A renewal has not been recorded yet.',
                'owner'   => 'Fleet compliance desk',
                'next'    => ['label' => 'Renew papers', 'action' => 'passport#compliance'],
            ];
        }

        return null;
    }

    /** Genset off while the body is warmer than the limit — the load is warming. */
    private function excursionIssue(Vehicle $vehicle, ?object $live): ?array
    {
        if (! $live || $live->temperature === null || $live->generator_status === null) {
            return null;
        }

        $threshold = (float) config('stos.telemetry.excursion_temperature', -18.0);
        $offState  = (string) config('stos.telemetry.excursion_generator_off', 'off');

        if ($live->generator_status !== $offState || (float) $live->temperature <= $threshold) {
            return null;
        }

        $over = round((float) $live->temperature - $threshold, 1);

        return [
            'tone'    => self::TONE_RED,
            'code'    => 'temperature_excursion',
            'why'     => "Cold chain breaking: genset is off and the body is {$over}°C above the limit.",
            'missing' => 'The genset is not running while the load is above '.$threshold.'°C.',
            'owner'   => 'Driver, then control tower',
            'next'    => ['label' => 'View telemetry', 'action' => 'passport#telemetry'],
        ];
    }

    private function workshopIssue(Vehicle $vehicle, int $openJobs): ?array
    {
        if ($openJobs < 1 && ! in_array($vehicle->status, [Vehicle::STATUS_UNDER_MAINTENANCE, Vehicle::STATUS_BREAKDOWN], true)) {
            return null;
        }

        return [
            'tone'    => self::TONE_AMBER,
            'code'    => 'in_workshop',
            'why'     => $openJobs > 0
                ? 'In the workshop — '.$openJobs.' open job '.($openJobs === 1 ? 'card' : 'cards').'.'
                : 'Marked as under maintenance.',
            'missing' => $openJobs > 0
                ? 'The job card has not been closed.'
                : 'No open job card explains why it is off the road.',
            'owner'   => 'Workshop supervisor',
            'next'    => ['label' => 'Open job cards', 'action' => 'passport#workshop'],
        ];
    }

    private function telemetryIssue(Vehicle $vehicle, ?object $live): ?array
    {
        if (! $vehicle->gps_device_id) {
            return [
                'tone'    => self::TONE_AMBER,
                'code'    => 'no_device',
                'why'     => 'Nothing is tracking this vehicle.',
                'missing' => 'No GPS device id is registered against it.',
                'owner'   => 'Telemetry lead',
                'next'    => ['label' => 'Fit a device', 'action' => 'passport#telemetry'],
            ];
        }

        if (! $live || ! $live->last_ping_at) {
            return [
                'tone'    => self::TONE_AMBER,
                'code'    => 'never_reported',
                'why'     => 'The fitted device has never reported.',
                'missing' => 'No telemetry has ever arrived from '.$vehicle->gps_device_id.'.',
                'owner'   => 'Telemetry lead / device vendor',
                'next'    => ['label' => 'Check device', 'action' => 'passport#telemetry'],
            ];
        }

        if (! $this->isStale($live->last_ping_at)) {
            return null;
        }

        $since = Carbon::parse($live->last_ping_at)->diffForHumans();

        return [
            'tone'    => self::TONE_AMBER,
            'code'    => 'telemetry_stale',
            'why'     => "Position is stale — last report {$since}.",
            'missing' => 'No ping in the last '.self::STALE_PING_MINUTES.' minutes.',
            'owner'   => 'Telemetry lead / device vendor',
            'next'    => ['label' => 'Check device', 'action' => 'passport#telemetry'],
        ];
    }

    private function isStale($lastPingAt): bool
    {
        return Carbon::parse($lastPingAt)->lt(now()->subMinutes(self::STALE_PING_MINUTES));
    }

    /** The document labels currently in a given state, for the message. */
    private function documentsInState(Vehicle $vehicle, string $state): array
    {
        $today = now()->startOfDay();
        $labels = [];

        foreach (Vehicle::EXPIRY_DOCUMENTS as $field => $label) {
            $date = $vehicle->{$field};

            if (! $date) {
                continue;
            }

            $daysLeft = (int) $today->diffInDays($date->copy()->startOfDay(), false);
            $actual = $daysLeft < 0 ? 'expired' : ($daysLeft <= self::EXPIRY_WARNING_DAYS ? 'expiring' : 'valid');

            if ($actual === $state) {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    private function weight(string $tone): int
    {
        return match ($tone) {
            self::TONE_RED   => 2,
            self::TONE_AMBER => 1,
            default          => 0,
        };
    }
}
