<?php

namespace App\Services\Sales;

use App\Exceptions\BusinessException;
use App\Models\Sales\Estimate;
use App\Services\Task\TaskService;

/**
 * PI10 — turn a Proforma Invoice / Estimate's line items into Tasks. Each line
 * becomes one task (its value carried as the task's fixed billable amount). When
 * the document is linked to a project the tasks are created under it, so they show
 * on the project board; otherwise they are standalone tasks. Task creation goes
 * through TaskService so every task invariant (numbering, tree, defaults) holds.
 */
class EstimateToTasksService
{
    public function __construct(private TaskService $tasks) {}

    /** @return array<int, \App\Models\Task\Task> */
    public function convert(Estimate $estimate, int $tenantId, int $userId): array
    {
        $estimate->loadMissing('lineItems');

        // Link the tasks to the document's project when there is one.
        $rel = $estimate->project_id ? ['rel_type' => 'project', 'rel_id' => (int) $estimate->project_id] : [];

        $created = [];
        foreach ($estimate->lineItems as $line) {
            $name = trim((string) $line->item_name);
            if ($name === '') {
                continue;
            }

            // The line's own value → the task's fixed billable amount.
            $amount = round((float) $line->total, 2);
            if ($amount <= 0) {
                $amount = round((float) $line->qty * (float) $line->rate, 2);
            }

            $created[] = $this->tasks->create(array_merge([
                'name'            => $name,
                'description'     => $line->description,
                'status'          => 'not_started',
                'priority'        => 'medium',
                'start_date'      => now()->toDateString(),
                'billable'        => true,
                'billable_amount' => $amount > 0 ? $amount : null,
            ], $rel), $tenantId, $userId);
        }

        if ($created === []) {
            throw new BusinessException('This document has no items to convert into tasks.');
        }

        return $created;
    }
}
