<?php

namespace Sire\Services;

use Sire\Exceptions\SireException;
use Sire\Models\Report;
use Sire\Models\RootCause;
use Sire\Dto\SireUserIdentity;
use Sire\Support\SireStatus;
use Illuminate\Support\Facades\DB;

/**
 * SIRE — root cause analysis.
 *
 * Five Whys are required for SERIOUS issues only. Requiring them everywhere is how
 * a form gets filled in with "because it was broken" five times; requiring them
 * where they matter is how they get used. "Serious" is defined by data the
 * register already holds — critical severity, P1, or a recurrence — never by a
 * separate flag someone has to remember to tick.
 */
class SireRootCauseService
{
    /** Below this many non-empty whys, a serious issue's RCA cannot be confirmed. */
    private const REQUIRED_WHYS = 5;

    public function __construct(private readonly SireAccessService $access)
    {
    }

    public function save(Report $report, array $data, SireUserIdentity $actor): RootCause
    {
        if (! in_array($data['category'] ?? null, RootCause::CATEGORIES, true)) {
            throw new SireException('Choose a root cause category.');
        }

        return DB::transaction(function () use ($report, $data, $actor) {
            $rca = RootCause::query()
                ->forTenant($report->tenant_id)
                ->where('report_id', $report->id)
                ->first();

            $attributes = [
                'category'             => $data['category'],
                'description'          => $data['description'] ?? '',
                'contributing_factors' => $this->cleanList($data['contributing_factors'] ?? []),
                'detection_gap'        => $data['detection_gap'] ?? null,
                'corrective_action'    => $data['corrective_action'] ?? null,
                'preventive_action'    => $data['preventive_action'] ?? null,
                'five_whys'            => $this->cleanList($data['five_whys'] ?? [], self::REQUIRED_WHYS),
                'method'               => $data['method'] ?? RootCause::METHOD_FIVE_WHYS,
                'analysis'             => $data['analysis'] ?? null,
            ];

            if ($rca) {
                // Editing a CONFIRMED analysis un-confirms it: the confirmation
                // attested to what it said at the time, and silently carrying that
                // signature onto different words would be a forged sign-off.
                if ($rca->isConfirmed()) {
                    $attributes['confirmed_by'] = null;
                    $attributes['confirmed_at'] = null;
                }
                $rca->fill($attributes)->save();
            } else {
                $rca = RootCause::create(array_merge($attributes, [
                    'tenant_id' => $report->tenant_id,   // explicit, never ambient
                    'report_id' => $report->id,
                ]));
            }

            $report->recordAudit(
                'Root cause analysis recorded',
                $actor,
                null,
                ['action' => 'rca_saved', 'category' => $data['category'], 'system' => true],
            );

            return $rca->fresh();
        });
    }

    /**
     * Confirming is a sign-off, not a save. It is what makes an RCA count in
     * defect-trend reporting, so it carries its own capability and its own checks.
     */
    public function confirm(Report $report, SireUserIdentity $actor): RootCause
    {
        $this->access->assert($actor, 'sire.rca.confirm', $report);

        $rca = RootCause::query()
            ->forTenant($report->tenant_id)
            ->where('report_id', $report->id)
            ->first();

        if ($rca === null) {
            throw new SireException('There is no root cause analysis to confirm.');
        }
        if (trim((string) $rca->description) === '') {
            throw new SireException('The analysis needs a description before it can be confirmed.');
        }

        // A serious issue needs its analysis actually WORKED, and what that means
        // depends on the technique. Demanding five whys from a team that ran a
        // fishbone would push them to flatten a branching cause map into a chain
        // to satisfy a validator, which is worse than not asking.
        if ($this->requiresStructuredAnalysis($report)) {
            $method = $rca->method ?: RootCause::METHOD_FIVE_WHYS;

            if ($method === RootCause::METHOD_FIVE_WHYS) {
                $whys = array_filter($rca->five_whys ?? [], fn ($w) => trim((string) $w) !== '');
                if (count($whys) < self::REQUIRED_WHYS) {
                    throw new SireException(sprintf(
                        'This issue is serious enough to need all %d "why" answers before sign-off (%d completed).',
                        self::REQUIRED_WHYS,
                        count($whys),
                    ));
                }
            } elseif (empty($rca->analysis)) {
                throw new SireException(sprintf(
                    'This issue is serious enough to need the %s worked through before sign-off.',
                    RootCause::METHOD_LABELS[$method] ?? $method,
                ));
            }
        }

        $rca->fill(['confirmed_by' => $actor->id, 'confirmed_at' => now()])->save();

        $report->recordAudit('Root cause confirmed', $actor, null, ['action' => 'rca_confirmed', 'system' => true]);

        return $rca;
    }

    /**
     * Serious enough to warrant a worked analysis — derived, never a checkbox:
     * the top severity band, or P1, or it has happened before.
     *
     * Severity is read by POSITION, not by code. This used to test for the
     * literal string 'critical' and no workspace here uses it -- the seeded bands
     * are s1..s4 -- so the severity arm of this test had never once fired, and a
     * critical issue could be signed off with one why answered. `level` is the
     * sort key everywhere else in SIRE for exactly this reason: a workspace may
     * call its top band Critical, Sev 1 or Blocker and may run three bands or six.
     */
    public function requiresStructuredAnalysis(Report $report): bool
    {
        return $this->isTopSeverity($report)
            || $report->priority === 'p1'
            || $report->recurrence_group_id !== null
            || (int) $report->reopen_count > 0;
    }

    /** Kept as the old name so existing callers and tests keep working. */
    public function requiresFiveWhys(Report $report): bool
    {
        return $this->requiresStructuredAnalysis($report);
    }

    private function isTopSeverity(Report $report): bool
    {
        $severity = $report->severity;

        if ($severity === null) {
            return false;
        }

        $top = \Sire\Models\ReportSeverity::query()
            ->forTenant($report->tenant_id)
            ->max('level');

        return $top !== null && (int) $severity->level === (int) $top;
    }

    /** Trim, drop blanks, cap length. Ordered lists stay ordered. */
    private function cleanList($input, ?int $max = null): array
    {
        if (! is_array($input)) {
            return [];
        }

        $clean = array_values(array_filter(
            array_map(fn ($v) => is_scalar($v) ? trim((string) $v) : '', $input),
            fn (string $v) => $v !== '',
        ));

        return $max ? array_slice($clean, 0, $max) : $clean;
    }
}
