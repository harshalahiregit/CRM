<?php

namespace App\Services\Hr\Posh;

use App\Models\Hr\HrPoshCase;
use App\Support\Hr\Decision\Decision;
use App\Support\Hr\TenantTime;
use Illuminate\Support\Carbon;

/**
 * How many complaints, in what state, over what period. Nothing else.
 *
 * THREE DIMENSIONS, AND THEY ARE THE WHOLE LIST: period, status, outcome.
 * Department, branch, designation, respondent and complainant are absent by
 * decision, not by omission. On a workplace-harassment dataset a department
 * breakdown is an identification: in a team of six, "one upheld complaint in
 * Finance last quarter" names a person to everyone who works there. No case
 * id, reference, name or employee id appears anywhere in this output, and
 * there is no drill-through, because the moment a count links to a case the
 * aggregate has stopped being an aggregate.
 *
 * SUPPRESSION BELOW FIVE. A cell of one is a person. The threshold is a
 * product safeguard and is described that way in the response — it is NOT a
 * guarantee of anonymity, and nothing here should be read as a legal claim.
 *
 * SUPPRESSION ALONE DOES NOT WORK, which is the part that is easy to get
 * wrong. Publishing a total beside a single hidden cell hands the cell back by
 * subtraction, and because by_status and by_outcome count the same cases,
 * a fully visible by_status reveals the total even when the total is withheld.
 * So the rule enforced here is stronger than "hide small cells":
 *
 *   EVERY BREAKDOWN HAS ZERO SUPPRESSED CELLS, OR AT LEAST TWO.
 *
 * A lone suppressed cell always pulls a second one down with it. That costs
 * real detail — a visible 40 disappears to protect a hidden 3 — and it is
 * worth it, because a suppression that can be undone with a subtraction is
 * theatre.
 *
 * This inverts SurveyReportService, which withholds a group's answers while
 * still publishing its response count. Correct there; wrong here, because for
 * POSH the count IS the sensitive cell.
 */
class PoshAggregateReportService
{
    /**
     * Fewest cases a cell must contain before its count is published.
     *
     * Five is a judgement, not a standard. It is a constant so it can be
     * argued with in one place.
     */
    public const MIN_CELL = 5;

    public const BUCKET_MONTH = 'month';
    public const BUCKET_QUARTER = 'quarter';
    public const BUCKET_YEAR = 'year';
    public const BUCKETS = [self::BUCKET_MONTH, self::BUCKET_QUARTER, self::BUCKET_YEAR];

    private const NOTICE = 'Counts below 5 are withheld, and additional cells may be withheld so that a '
        .'hidden count cannot be recovered by subtraction. This reduces the risk of identifying '
        .'individuals; it is not a guarantee of anonymity.';

    /**
     * @param  array{from?:string, to?:string, bucket?:string}  $filters
     */
    public function summary(int $tenantId, array $filters = []): array
    {
        $bucket = in_array($filters['bucket'] ?? '', self::BUCKETS, true)
            ? $filters['bucket']
            : self::BUCKET_QUARTER;

        $zone = TenantTime::zone($tenantId);

        // Boundaries are the workspace's own, through the mechanism the rest
        // of HR already uses. A UTC quarter edge would file a complaint raised
        // on the evening of 31 March into the wrong quarter.
        [$from, $to] = $this->window($filters, $zone);

        $cases = HrPoshCase::where('tenant_id', $tenantId)
            ->whereBetween('complaint_received_at', [$from, $to])
            ->get(['id', 'status', 'outcome', 'complaint_received_at']);

        $periods = [];

        foreach ($cases->groupBy(fn ($c) => $this->bucketKey($c->complaint_received_at, $bucket, $zone)) as $key => $group) {
            $periods[] = $this->period((string) $key, $group);
        }

        usort($periods, fn ($a, $b) => strcmp($a['period'], $b['period']));

        return [
            'min_cell' => self::MIN_CELL,
            'bucket'   => $bucket,
            'from'     => $from->toIso8601String(),
            'to'       => $to->toIso8601String(),
            'periods'  => $periods,
            'notice'   => self::NOTICE,
        ];
    }

    /* ── one period ───────────────────────────────────────────────────── */

    private function period(string $key, $group): array
    {
        $total = $group->count();

        $byStatus = $this->tally($group, 'status', HrPoshCase::STATUSES);
        $byOutcome = $this->tally($group, 'outcome', Decision::OUTCOMES);

        // A period that is itself tiny cannot be published in any breakdown:
        // every cell within it is bounded by a total nobody may see.
        if ($total > 0 && $total < self::MIN_CELL) {
            return [
                'period'           => $key,
                'total'            => null,
                'total_suppressed' => true,
                'by_status'        => $this->suppressAll($byStatus, 'status'),
                'by_outcome'       => $this->suppressAll($byOutcome, 'outcome'),
            ];
        }

        $status = $this->suppress($byStatus, 'status');
        $outcome = $this->suppress($byOutcome, 'outcome');

        $anySuppressed = $this->anySuppressed($status) || $this->anySuppressed($outcome);

        return [
            'period' => $key,
            // Withheld whenever anything under it is withheld. Where a
            // breakdown is fully visible the total remains derivable by
            // addition, which is harmless: the rule above guarantees no single
            // hidden cell is ever the only unknown.
            'total'            => $anySuppressed ? null : $total,
            'total_suppressed' => $anySuppressed,
            'by_status'        => $status,
            'by_outcome'       => $outcome,
        ];
    }

    /**
     * Raw counts for one dimension, in the dimension's declared order.
     *
     * Declared order matters: it is what makes the secondary choice below
     * deterministic, so the same data always produces the same report.
     */
    private function tally($group, string $column, array $vocabulary): array
    {
        $counts = [];

        foreach ($vocabulary as $value) {
            $counts[] = ['key' => $value, 'count' => $group->where($column, $value)->count()];
        }

        // Anything the vocabulary does not name — a status added later, or a
        // null outcome — still has to be counted or the totals stop adding up.
        $named = array_column($counts, 'key');
        $other = $group->filter(fn ($c) => ! in_array($c->{$column}, $named, true))->count();

        if ($other > 0) {
            $counts[] = ['key' => 'unspecified', 'count' => $other];
        }

        return $counts;
    }

    /**
     * Apply the rule: zero suppressed cells, or at least two.
     *
     * 1. Hide every cell of 1..4.
     * 2. If exactly one is hidden, hide a second — the smallest non-zero cell
     *    still visible, ties broken by declared order.
     * 3. If there is no such cell to take, hide the rest of the breakdown.
     *
     * Zeros stay visible throughout. A zero describes nobody, and hiding it
     * would spend detail to protect nothing — but a zero is also useless as a
     * second suppression, because subtracting it changes nothing.
     */
    private function suppress(array $cells, string $label): array
    {
        $hidden = [];

        foreach ($cells as $i => $cell) {
            if ($cell['count'] > 0 && $cell['count'] < self::MIN_CELL) {
                $hidden[] = $i;
            }
        }

        if (count($hidden) === 1) {
            $candidate = null;

            foreach ($cells as $i => $cell) {
                if (in_array($i, $hidden, true) || $cell['count'] <= 0) {
                    continue;
                }

                if ($candidate === null || $cell['count'] < $cells[$candidate]['count']) {
                    $candidate = $i;
                }
            }

            if ($candidate !== null) {
                $hidden[] = $candidate;
            } else {
                // Nothing left worth taking: the lone hidden cell is the only
                // non-zero one, so the breakdown goes entirely.
                foreach ($cells as $i => $cell) {
                    if (! in_array($i, $hidden, true)) {
                        $hidden[] = $i;
                    }
                }
            }
        }

        return $this->present($cells, $hidden, $label);
    }

    private function suppressAll(array $cells, string $label): array
    {
        return $this->present($cells, array_keys($cells), $label);
    }

    private function present(array $cells, array $hidden, string $label): array
    {
        $out = [];

        foreach ($cells as $i => $cell) {
            $isHidden = in_array($i, $hidden, true);

            $out[] = [
                $label       => $cell['key'],
                'count'      => $isHidden ? null : $cell['count'],
                'suppressed' => $isHidden,
            ];
        }

        return $out;
    }

    private function anySuppressed(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($cell['suppressed']) {
                return true;
            }
        }

        return false;
    }

    /* ── periods ──────────────────────────────────────────────────────── */

    private function window(array $filters, string $zone): array
    {
        $to = ! empty($filters['to'])
            ? Carbon::parse($filters['to'], $zone)->endOfDay()
            : Carbon::now($zone)->endOfDay();

        $from = ! empty($filters['from'])
            ? Carbon::parse($filters['from'], $zone)->startOfDay()
            // A year back, so a report asked for with no dates still says
            // something rather than nothing.
            : (clone $to)->subYear()->startOfDay();

        return [$from, $to];
    }

    private function bucketKey($value, string $bucket, string $zone): string
    {
        $d = Carbon::parse($value)->setTimezone($zone);

        return match ($bucket) {
            self::BUCKET_MONTH => $d->format('Y-m'),
            self::BUCKET_YEAR  => $d->format('Y'),
            default            => $d->format('Y').'-Q'.$d->quarter,
        };
    }
}
