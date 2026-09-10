<?php

namespace Sire\Services;

use Sire\Models\Report;
use Sire\Contracts\SireSettingsProvider;
use Sire\Support\SireSlaState;
use Sire\Support\SireStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * SIRE — SLA. Computed, never stored.
 *
 * CONFIGURATION uses the existing settings architecture. Three keys in
 * tenant_settings, no new table, no new CRUD:
 *
 *   sire.sla.policies           ordered list of {match, ack_minutes, resolve_minutes}
 *   sire.sla.warning_threshold  fraction of the target at which WARNING starts (0.8)
 *   sire.sla.pause_states       statuses that stop the clock
 *
 * A policy's `match` may constrain any of type / severity / priority, and tenancy
 * is implicit because settings are per tenant — so the four dimensions the brief
 * asks for are covered by one key. The most SPECIFIC matching policy wins (most
 * match keys); an exact tie resolves to the earlier entry. Order is a tie-break,
 * not the primary rule, so a tenant reordering their list cannot silently change
 * which policy applies.
 *
 * Resolution order: an explicit policy → the severity row's own targets → no SLA.
 * A matched policy with a null target disables that clock DELIBERATELY; it does
 * not fall through to the severity default. "Enhancements have no resolution SLA"
 * is a real answer and must be expressible.
 *
 * This is a direct port of tests/reference/slaPolicy.mjs, and
 * tests/Unit/Sire/SireSlaPolicyTest.php runs both against the same fixture file.
 * If they disagree, fixtures/sla-cases.json says which one is wrong.
 */
class SireSlaService
{
    public const DEFAULT_WARNING_THRESHOLD = 0.8;

    public function __construct(private readonly SireSettingsProvider $settings)
    {
    }

    /** @return array{source:string,matched_index:?int,ack:array,resolve:array,state:?string} */
    public function for(Report $report, ?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? now());
        $tenantId = (int) $report->tenant_id;

        $policies = $this->policies($tenantId);
        $threshold = (float) $this->settings->get($tenantId, 'sire.sla.warning_threshold', self::DEFAULT_WARNING_THRESHOLD);
        $pauseStates = (array) $this->settings->get($tenantId, 'sire.sla.pause_states', SireStatus::SLA_PAUSED);

        $targets = $this->resolveTargets($policies, $report);

        $isTerminal = SireStatus::isTerminal((string) $report->status);
        $isPaused = ! $isTerminal && in_array($report->status, $pauseStates, true);

        $startedAt = CarbonImmutable::instance($report->sla_started_at ?? $report->created_at);

        $ack = $this->clock(
            $targets['ack'],
            $startedAt,
            $report->acknowledged_at ? CarbonImmutable::instance($report->acknowledged_at) : null,
            $now, $report, $isPaused, $threshold,
        );

        $resolve = $this->clock(
            $targets['resolve'],
            $startedAt,
            // Any terminal status stops the resolve clock, not only 'closed'.
            $isTerminal ? CarbonImmutable::instance($report->closed_at ?? $now) : null,
            $now, $report, $isPaused, $threshold,
        );

        return [
            'source'        => $targets['source'],
            'matched_index' => $targets['matched_index'],
            'ack'           => $ack,
            'resolve'       => $resolve,
            'state'         => SireSlaState::worst($ack['state'], $resolve['state']),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function policies(int $tenantId): array
    {
        $policies = $this->settings->get($tenantId, 'sire.sla.policies', []);

        return is_array($policies) ? array_values($policies) : [];
    }

    /**
     * The match subject is assembled here rather than read off the report: severity
     * lives in its own row, and a matcher reading $report->severity would silently
     * match nothing and fall through to the catch-all. That bug was caught by the
     * shared fixture, not by review.
     */
    public function resolveTargets(array $policies, Report $report): array
    {
        $subject = [
            'type'     => $report->category?->code,
            'priority' => $report->priority,
            'severity' => $report->severity?->code,
        ];

        $best = null;
        $bestScore = -1;

        foreach ($policies as $index => $policy) {
            $match = (array) ($policy['match'] ?? []);

            foreach ($match as $key => $value) {
                if (($subject[$key] ?? null) !== $value) {
                    continue 2;
                }
            }

            if (count($match) > $bestScore) {
                $bestScore = count($match);
                $best = ['policy' => $policy, 'index' => $index];
            }
        }

        if ($best !== null) {
            return [
                'source'        => 'policy',
                'matched_index' => $best['index'],
                'ack'           => $best['policy']['ack_minutes'] ?? null,
                'resolve'       => $best['policy']['resolve_minutes'] ?? null,
            ];
        }

        $ack = $report->severity?->ack_target_minutes;
        $resolve = $report->severity?->resolve_target_minutes;

        if ($ack === null && $resolve === null) {
            return ['source' => 'none', 'matched_index' => null, 'ack' => null, 'resolve' => null];
        }

        return ['source' => 'severity', 'matched_index' => null, 'ack' => $ack, 'resolve' => $resolve];
    }

    private function clock(
        ?int $targetMinutes,
        CarbonImmutable $startedAt,
        ?CarbonImmutable $stoppedAt,
        CarbonImmutable $now,
        Report $report,
        bool $isPaused,
        float $threshold,
    ): array {
        if ($targetMinutes === null) {
            return ['state' => null, 'target_minutes' => null, 'elapsed_minutes' => null,
                    'deadline' => null, 'stopped' => $stoppedAt !== null, 'met' => null];
        }

        $stopped = $stoppedAt !== null;
        $end = $stoppedAt ?? $now;

        // An open pause is bounded by the moment the clock stopped: discarding it
        // charges the team for time the issue was parked, and ignoring the bound
        // accrues pause forever after closure. Both are wrong.
        $pauseEnd = $stopped ? $stoppedAt : $now;
        $pausedSince = $report->sla_paused_since ? CarbonImmutable::instance($report->sla_paused_since) : null;
        $openPause = $pausedSince ? max(0, $pausedSince->diffInMinutes($pauseEnd, false)) : 0;
        $totalPaused = (int) ($report->sla_paused_minutes ?? 0) + $openPause;

        $elapsed = max(0, (int) $startedAt->diffInMinutes($end, false) - $totalPaused);
        $deadline = $startedAt->addMinutes($targetMinutes + $totalPaused);

        $base = [
            'target_minutes'  => $targetMinutes,
            'elapsed_minutes' => $elapsed,
            'deadline'        => $deadline->toIso8601String(),
            'stopped'         => $stopped,
        ];

        if ($stopped) {
            $met = $elapsed < $targetMinutes;

            return $base + ['state' => $met ? SireSlaState::ON_TRACK : SireSlaState::BREACHED, 'met' => $met];
        }

        // A breach that already happened stays breached: pausing afterwards does
        // not un-breach anything.
        if ($elapsed >= $targetMinutes) {
            return $base + ['state' => SireSlaState::BREACHED, 'met' => false];
        }
        if ($isPaused) {
            return $base + ['state' => SireSlaState::PAUSED, 'met' => null];
        }
        if ($elapsed >= $targetMinutes * $threshold) {
            return $base + ['state' => SireSlaState::WARNING, 'met' => null];
        }

        return $base + ['state' => SireSlaState::ON_TRACK, 'met' => null];
    }
}
