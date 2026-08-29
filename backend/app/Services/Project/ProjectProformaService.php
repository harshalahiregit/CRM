<?php

namespace App\Services\Project;

use App\Exceptions\BusinessException;
use App\Models\Project\Project;
use App\Models\Task\Task;
use App\Services\Sales\EstimateService;

/**
 * PR2 — turn a project (or one of its milestones / a set of its tasks) into a
 * Sales Proforma Invoice. Each billable task becomes a line (its effective
 * billable amount — fixed if set, else rate × logged hours). The PI is created
 * through the Sales EstimateService (estimate_type = proforma) so it flows into
 * the normal Proforma-Invoice list, numbering and conversion path.
 */
class ProjectProformaService
{
    public function __construct(private EstimateService $estimates) {}

    /**
     * @param  array{scope?:string, milestone_id?:int, task_ids?:array<int>}  $opts
     */
    public function fromProject(Project $project, int $tenantId, int $userId, array $opts = []): \App\Models\Sales\Estimate
    {
        $scope = $opts['scope'] ?? 'project';

        $query = Task::query()
            ->where('tenant_id', $tenantId)
            ->where('rel_type', 'project')
            ->where('rel_id', $project->id)
            ->where('billable', true)
            ->with('timers');

        if ($scope === 'milestone') {
            if (empty($opts['milestone_id'])) {
                throw new BusinessException('A milestone is required for milestone scope.');
            }
            $query->where('milestone_id', $opts['milestone_id']);
        } elseif ($scope === 'tasks') {
            $ids = array_filter((array) ($opts['task_ids'] ?? []));
            if ($ids === []) {
                throw new BusinessException('Select at least one task to convert.');
            }
            $query->whereIn('id', $ids);
        }

        $lineItems = [];
        foreach ($query->get() as $task) {
            $amount = $task->effectiveBillableAmount();
            if ($amount <= 0) {
                continue;   // nothing to bill for this task yet
            }
            $lineItems[] = [
                'item_name' => $task->name,
                'qty'       => 1,
                'unit'      => 'job',
                'rate'      => $amount,
                'tax'       => 0,
            ];
        }

        if ($lineItems === []) {
            throw new BusinessException('Nothing billable to convert — no billable task carries an amount yet.');
        }

        $label = match ($scope) {
            'milestone' => 'Proforma (milestone) for '.$project->name,
            'tasks'     => 'Proforma (selected tasks) for '.$project->name,
            default     => 'Proforma for '.$project->name,
        };

        return $this->estimates->create([
            'subject'       => $label,
            'client_id'     => $project->customer_id,
            'project_id'    => $project->id,
            'date'          => now()->toDateString(),
            'estimate_type' => 'proforma',
            'currency'      => 'INR',
            'status'        => 'Draft',
        ], $lineItems, $tenantId, $userId);
    }
}
