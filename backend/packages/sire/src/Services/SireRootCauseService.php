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

        if ($this->requiresFiveWhys($report)) {
            $whys = array_filter($rca->five_whys ?? [], fn ($w) => trim((string) $w) !== '');
            if (count($whys) < self::REQUIRED_WHYS) {
                throw new SireException(sprintf(
                    'This issue is serious enough to need all %d "why" answers before sign-off (%d completed).',
                    self::REQUIRED_WHYS,
                    count($whys),
                ));
            }
        }

        $rca->fill(['confirmed_by' => $actor->id, 'confirmed_at' => now()])->save();

        $report->recordAudit('Root cause confirmed', $actor, null, ['action' => 'rca_confirmed', 'system' => true]);

        return $rca;
    }

    /**
     * Serious enough to warrant Five Whys — derived, never a checkbox:
     *   critical severity, or P1, or it has happened before.
     */
    public function requiresFiveWhys(Report $report): bool
    {
        return $report->severity?->code === 'critical'
            || $report->priority === 'p1'
            || $report->recurrence_group_id !== null
            || (int) $report->reopen_count > 0;
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
