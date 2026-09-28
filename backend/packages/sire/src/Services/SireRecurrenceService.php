<?php

namespace Sire\Services;

use Sire\Exceptions\SireException;
use Sire\Models\RecurrenceGroup;
use Sire\Models\Report;
use Sire\Dto\SireUserIdentity;
use Sire\Contracts\SireNumberingProvider;
use Sire\Support\SireStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * SIRE — the recurring-bug engine.
 *
 * Deterministic by design. No fuzzy matching, no similarity model, no AI: a
 * recurrence group is created and populated by a human, and the engine's job is
 * to keep the ARITHMETIC honest and to SUGGEST candidates by exact match on facts
 * the register already holds.
 *
 * Risk scoring is a direct port of tests/reference/quality.mjs and is verified
 * against fixtures/recurrence-risk-cases.json by both sides.
 */
class SireRecurrenceService
{
    private const RECENT_DAYS = 30;

    private const THRESHOLD_CRITICAL = 7;
    private const THRESHOLD_HIGH     = 5;
    private const THRESHOLD_MEDIUM   = 3;

    private const FIX_SCORE = [
        'none' => 2, 'planned' => 1, 'in_progress' => 1, 'shipped' => 0, 'verified' => -1,
    ];

    public function __construct(
        private readonly SireDuplicateService $duplicates,
        private readonly SireNumberingProvider $numbers,
    ) {
    }

    // ------------------------------------------------------------- membership

    public function addOccurrence(RecurrenceGroup $group, Report $report, SireUserIdentity $actor): RecurrenceGroup
    {
        if ((int) $report->tenant_id !== (int) $group->tenant_id) {
            // Belt and braces: the controller already asserts ownership of both.
            throw new SireException('That issue belongs to a different workspace.');
        }

        // The rule that keeps the count meaningful.
        $this->duplicates->assertNotDuplicate($report);

        if ($report->recurrence_group_id && (int) $report->recurrence_group_id !== (int) $group->id) {
            throw new SireException(
                'That issue already belongs to another recurrence group. Remove it from that one first.',
            );
        }

        DB::transaction(function () use ($group, $report, $actor) {
            $report->recurrence_group_id = $group->id;
            $report->save();

            $report->recordAudit(
                "Added to recurrence group {$group->reference}",
                $actor,
                null,
                ['action' => 'recurrence_added', 'group_id' => $group->id, 'system' => true],
            );

            $this->recompute($group);
        });

        return $group->fresh();
    }

    public function removeOccurrence(RecurrenceGroup $group, Report $report, SireUserIdentity $actor): RecurrenceGroup
    {
        DB::transaction(function () use ($group, $report, $actor) {
            $report->recurrence_group_id = null;
            $report->save();

            $report->recordAudit(
                "Removed from recurrence group {$group->reference}",
                $actor,
                null,
                ['action' => 'recurrence_removed', 'group_id' => $group->id, 'system' => true],
            );

            $this->recompute($group);
        });

        return $group->fresh();
    }

    // -------------------------------------------------------------- statistics

    /**
     * Recompute every derived figure from the occurrences themselves. Called on
     * every membership change and by the scheduled sweep — never trusted from
     * input, because a count someone can type is a count that will be wrong.
     */
    public function recompute(RecurrenceGroup $group, ?CarbonInterface $now = null): RecurrenceGroup
    {
        $now = CarbonImmutable::instance($now ?? now());

        $occurredAt = Report::query()
            ->forTenant($group->tenant_id)
            ->where('recurrence_group_id', $group->id)
            ->orderBy('occurred_at')
            ->pluck('occurred_at', 'id')
            ->filter()
            ->values();

        // Fall back to created_at for rows with no occurred_at, rather than
        // dropping them and understating the count.
        if ($occurredAt->isEmpty()) {
            $occurredAt = Report::query()
                ->forTenant($group->tenant_id)
                ->where('recurrence_group_id', $group->id)
                ->orderBy('created_at')
                ->pluck('created_at')
                ->filter()
                ->values();
        }

        $count = Report::query()
            ->forTenant($group->tenant_id)
            ->where('recurrence_group_id', $group->id)
            ->count();

        $group->occurrence_count = $count;
        $group->first_occurrence_at = $occurredAt->first();
        $group->latest_occurrence_at = $occurredAt->last();
        $group->average_interval_days = $this->averageIntervalDays($occurredAt);

        $risk = $this->assessRisk($group, $now);
        $group->recurrence_risk = $risk['risk'];
        $group->risk_computed_at = $now;

        $group->save();

        return $group;
    }

    /** Mean gap in days. Null below two occurrences — one point has no interval. */
    public function averageIntervalDays(Collection $timestamps): ?float
    {
        if ($timestamps->count() < 2) {
            return null;
        }

        $sorted = $timestamps
            ->map(fn ($t) => CarbonImmutable::instance($t instanceof CarbonInterface ? $t : CarbonImmutable::parse($t)))
            ->sort()
            ->values();

        $total = 0.0;
        for ($i = 1; $i < $sorted->count(); $i++) {
            $total += $sorted[$i - 1]->diffInSeconds($sorted[$i], false) / 86400;
        }

        return round($total / ($sorted->count() - 1), 2);
    }

    /**
     * Deterministic risk score. Every input is a fact the register already knows,
     * so the result is explainable to whoever it pages.
     *
     * @return array{score:int, risk:string, forced_low:bool, not_recurring:bool}
     */
    public function assessRisk(RecurrenceGroup $group, ?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? now());
        $count = (int) $group->occurrence_count;

        // One occurrence is an issue, not a pattern.
        if ($count < 2) {
            return ['score' => 0, 'risk' => 'low', 'forced_low' => false, 'not_recurring' => true];
        }

        $recent = $group->latest_occurrence_at !== null
            && CarbonImmutable::instance($group->latest_occurrence_at)->diffInDays($now, false) <= self::RECENT_DAYS;

        $score = $this->occurrenceScore($count)
            + $this->intervalScore($group->average_interval_days)
            + (self::FIX_SCORE[$group->permanent_fix_status] ?? 0)
            + ($recent ? 1 : 0);

        // A verified permanent fix with nothing since it landed is closed
        // business. If it HAS happened again since, the fix did not work — and
        // that is exactly the case worth escalating, so the override must not fire.
        if ($group->permanent_fix_status === 'verified' && ! $recent) {
            return ['score' => $score, 'risk' => 'low', 'forced_low' => true, 'not_recurring' => false];
        }

        $risk = match (true) {
            $score >= self::THRESHOLD_CRITICAL => 'critical',
            $score >= self::THRESHOLD_HIGH     => 'high',
            $score >= self::THRESHOLD_MEDIUM   => 'medium',
            default                            => 'low',
        };

        return ['score' => $score, 'risk' => $risk, 'forced_low' => false, 'not_recurring' => false];
    }

    private function occurrenceScore(int $count): int
    {
        return match (true) {
            $count >= 10 => 4,
            $count >= 5  => 3,
            $count >= 3  => 2,
            $count >= 2  => 1,
            default      => 0,
        };
    }

    /**
     * An unknown interval scores 0, not worst-case. A group whose gap has not been
     * measured yet is not evidence of frequency, and guessing high would page
     * someone about a pattern that may not exist.
     */
    private function intervalScore(?float $days): int
    {
        if ($days === null) {
            return 0;
        }

        return match (true) {
            $days < 7  => 3,
            $days < 30 => 2,
            $days < 90 => 1,
            default    => 0,
        };
    }

    // -------------------------------------------------------------- suggestions

    /**
     * Candidate occurrences for a group: exact signature match, not already in a
     * group, not a duplicate, and closed — an open issue is current work, not a
     * past occurrence.
     *
     * Returned as SUGGESTIONS. A human decides; nothing is auto-assigned.
     */
    public function suggestOccurrences(RecurrenceGroup $group, int $limit = 10): Collection
    {
        if (! $group->signature) {
            return collect();
        }

        return Report::query()
            ->forTenant($group->tenant_id)
            ->whereNull('recurrence_group_id')
            ->whereNull('duplicate_of_id')
            ->where('status', '!=', SireStatus::DUPLICATE)
            ->whereIn('status', [SireStatus::CLOSED, SireStatus::PRODUCTION_VALIDATED, SireStatus::RELEASED])
            ->where(function ($q) use ($group) {
                foreach (explode('|', $group->signature) as $index => $part) {
                    $column = ['module', 'section', 'screen'][$index] ?? null;
                    if ($column) {
                        $q->where($column, $part);
                    }
                }
            })
            ->latest('occurred_at')
            ->limit($limit)
            ->get(['id', 'report_number', 'title', 'occurred_at', 'status']);
    }

    public function create(int $tenantId, array $data, SireUserIdentity $actor): RecurrenceGroup
    {
        return DB::transaction(function () use ($tenantId, $data, $actor) {
            $group = RecurrenceGroup::create([
                'tenant_id'   => $tenantId,   // explicit: this may run outside a request
                'reference'   => $this->numbers->next($tenantId, 'sire_recurrence'),
                'title'       => $data['title'],
                'description' => $data['description'] ?? null,
                'signature'   => $data['signature'] ?? null,
                'owner_id'    => $data['owner_id'] ?? $actor->id,
            ]);

            $group->recordAudit('Recurrence group created', $actor, null, ['system' => true]);

            return $group;
        });
    }
}
