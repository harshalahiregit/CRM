<?php

namespace Sire\Http\Controllers;

use Sire\Http\Controllers\Concerns\ResolvesSireUser;
use Sire\Http\Controllers\Concerns\SireApiResponse;
use Sire\Models\Report;
use Sire\Support\SireStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "What is on my plate." Two narrow, indexed queries — the development and QA
 * boards are the screens people leave open all day, so they must not scan.
 *
 * Backed by sire_rep_dev_queue_idx (tenant_id, assignee_id, status) and
 * sire_rep_qa_queue_idx (tenant_id, qa_assignee_id, status).
 */
class SireQueueController extends SireController
{
    use SireApiResponse;
    use ResolvesSireUser;

    private const PRIORITY_ORDER =
        "CASE priority WHEN 'p1' THEN 1 WHEN 'p2' THEN 2 WHEN 'p3' THEN 3 WHEN 'p4' THEN 4 ELSE 5 END";

    public function development(Request $request): JsonResponse
    {
        return $this->success($this->queue($request, 'assignee_id', SireStatus::DEVELOPMENT));
    }

    public function qa(Request $request): JsonResponse
    {
        return $this->success($this->queue($request, 'qa_assignee_id', SireStatus::QA));
    }

    private function queue(Request $request, string $column, array $statuses)
    {
        $tenantId = (int) $this->sireUser()->tenantId;
        $mine = $request->boolean('mine', true);

        return Report::query()
            ->forTenant($tenantId)                       // opt-in scope: never omit
            ->whereIn('status', $statuses)
            ->when($mine, fn ($q) => $q->where($column, $this->sireUser()->id))
            // Unassigned QA work has to be visible to somebody, or it sits forever.
            ->when(! $mine && $column === 'qa_assignee_id', fn ($q) => $q->whereNull($column))
            ->with(['severity:id,name', 'assignee:id,name', 'qaAssignee:id,name'])
            // Portable priority ordering. MySQL's FIELD() would be shorter but the
            // whole test suite runs on SQLite, which has no FIELD() — that gap has
            // already broken a deploy in this codebase. CASE works on both.
            ->orderByRaw(self::PRIORITY_ORDER)
            ->orderBy('created_at')
            ->paginate((int) $request->integer('per_page', 25));
    }
}
