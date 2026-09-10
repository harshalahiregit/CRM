<?php

namespace Sire\Services;

use Sire\Exceptions\SireException;
use Sire\Models\Release;
use Sire\Models\Report;
use Sire\Models\ReportLink;
use Sire\Dto\SireUserIdentity;
use Illuminate\Support\Facades\DB;

/**
 * SIRE — regression tracking.
 *
 * A regression is a defect that a change RE-INTRODUCED. Recording one answers a
 * question no other field can: which release cost us this, and what did it break
 * that we had already fixed.
 *
 * Deliberately manual. Inferring regressions from text similarity would be the
 * kind of guess that quietly poisons the regression rate on the dashboard — and
 * the brief says no AI. A human marks it; the register keeps the arithmetic.
 */
class SireRegressionService
{
    public function __construct(private readonly SireAccessService $access)
    {
    }

    public function mark(Report $report, array $data, SireUserIdentity $actor): Report
    {
        $originalId = isset($data['regression_of_id']) ? (int) $data['regression_of_id'] : null;
        $causedById = isset($data['caused_by_release_id']) ? (int) $data['caused_by_release_id'] : null;

        if ($originalId === (int) $report->id) {
            throw new SireException('An issue cannot be a regression of itself.');
        }

        $original = null;
        if ($originalId !== null) {
            $original = Report::query()->forTenant($report->tenant_id)->find($originalId);
            if ($original === null) {
                throw new SireException('That original issue could not be found in your workspace.');
            }
        }

        if ($causedById !== null) {
            $exists = Release::query()->forTenant($report->tenant_id)->whereKey($causedById)->exists();
            if (! $exists) {
                throw new SireException('That release could not be found in your workspace.');
            }
        }

        return DB::transaction(function () use ($report, $original, $causedById, $data, $actor) {
            $report->fill([
                'is_regression'        => true,
                'regression_of_id'     => $original?->id,
                'caused_by_release_id' => $causedById,
                'regression_notes'     => $data['regression_notes'] ?? null,
            ])->save();

            // The many-to-many side, so the ORIGINAL issue also shows that it came
            // back. Without this the relationship is only visible from one end,
            // and the end that matters is usually the other one.
            if ($original) {
                ReportLink::firstOrCreate([
                    'tenant_id'      => $report->tenant_id,
                    'from_report_id' => $report->id,
                    'to_report_id'   => $original->id,
                    'link_type'      => ReportLink::TYPE_REGRESSION_OF,
                ], ['created_by' => $actor->id]);

                $original->recordAudit(
                    "Regressed — reopened as {$report->report_number}",
                    $actor,
                    null,
                    ['action' => 'regressed', 'regression_id' => $report->id, 'system' => true],
                );
            }

            $report->recordAudit(
                'Marked as a regression',
                $actor,
                $data['regression_notes'] ?? null,
                ['action' => 'marked_regression', 'caused_by_release_id' => $causedById, 'system' => true],
            );

            return $report->fresh();
        });
    }

    public function clear(Report $report, string $reason, SireUserIdentity $actor): Report
    {
        if (! $report->is_regression) {
            throw new SireException('That issue is not marked as a regression.');
        }

        return DB::transaction(function () use ($report, $reason, $actor) {
            ReportLink::query()
                ->forTenant($report->tenant_id)
                ->where('from_report_id', $report->id)
                ->where('link_type', ReportLink::TYPE_REGRESSION_OF)
                ->delete();

            $report->fill([
                'is_regression'        => false,
                'regression_of_id'     => null,
                'caused_by_release_id' => null,
            ])->save();

            $report->recordAudit('Regression flag cleared', $actor, $reason, ['system' => true]);

            return $report->fresh();
        });
    }
}
