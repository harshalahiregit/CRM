<?php

namespace Sire\AI;

use Sire\Models\RecurrenceGroup;
use Sire\Models\Release;
use Sire\Models\Report;
use Illuminate\Database\Eloquent\Model;

/**
 * SIRE AI — turn a subject into raw context for the redactor.
 *
 * READ ONLY. This class touches core models and writes nothing; the redactor then
 * filters what it produces down to a per-capability allowlist. Two stages on
 * purpose: this one knows the shape of SIRE's data, the redactor knows what is
 * safe to send, and neither needs to know the other's rules.
 *
 * Note that it flattens relations into scalars — `severity_code` rather than a
 * Severity object. A provider gets facts, not an object graph it could walk.
 */
class SireAiContextBuilder
{
    public function build(Model $subject): array
    {
        return match (true) {
            $subject instanceof Report          => $this->fromReport($subject),
            $subject instanceof RecurrenceGroup => $this->fromRecurrenceGroup($subject),
            $subject instanceof Release         => $this->fromRelease($subject),
            default                             => [],
        };
    }

    private function fromReport(Report $report): array
    {
        return [
            'title'               => $report->title,
            'description'         => $report->description,
            'steps_to_reproduce'  => $report->steps_to_reproduce,
            'expected_result'     => $report->expected_result,
            'actual_result'       => $report->actual_result,
            'module'              => $report->module,
            'section'             => $report->section,
            'screen'              => $report->screen,
            'entity_type'         => $report->related_type,
            'category_code'       => $report->category?->code,
            'severity_code'       => $report->severity?->code,
            'is_regression'       => (bool) $report->is_regression,
            'reopen_count'        => (int) $report->reopen_count,
            'occurrence_count'    => (int) ($report->recurrenceGroup?->occurrence_count ?? 0),
            'fix_summary'         => $report->fix_summary,
            'investigation_notes' => $report->investigation_notes,
            'root_cause_category' => $report->rootCause?->category,
        ];
    }

    private function fromRecurrenceGroup(RecurrenceGroup $group): array
    {
        return [
            'title'                 => $group->title,
            'occurrence_count'      => (int) $group->occurrence_count,
            'average_interval_days' => $group->average_interval_days,
            'permanent_fix_status'  => $group->permanent_fix_status,
            'root_cause_category'   => $group->rootCause?->category,
            'module'                => $group->occurrences()->value('module'),
        ];
    }

    private function fromRelease(Release $release): array
    {
        $gate = $release->gate_state ?? [];
        $snapshot = $gate['snapshot'] ?? [];

        return [
            'version'          => $release->version,
            'release_type'     => $release->release_type,
            'total_issues'     => (int) ($snapshot['total_issues'] ?? 0),
            'open_critical'    => (int) ($snapshot['open_critical'] ?? 0),
            'qa_failed'        => (int) ($snapshot['qa_failed'] ?? 0),
            'regression_count' => (int) ($snapshot['regression_required'] ?? 0),
            'recurring_count'  => 0,
        ];
    }
}
