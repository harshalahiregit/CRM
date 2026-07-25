<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Models\Sales\Lead;
use App\Services\Sales\SalesInsightService;
use Illuminate\Http\Request;

class SalesInsightController extends Controller
{
    public function __construct(private SalesInsightService $insightService)
    {
    }

    /* ── Rule-based win probability for a lead ─────────────────── */
    public function winProbability(Lead $lead, Request $request)
    {
        return response()->json(
            $this->insightService->winProbability($lead, $request->user()->tenant_id)
        );
    }

    /* ── Heuristic next-best-action for a lead ─────────────────── */
    public function nextBestAction(Lead $lead, Request $request)
    {
        return response()->json(
            $this->insightService->nextBestAction($lead, $request->user()->tenant_id)
        );
    }

    /* ── Deterministic task prioritization ─────────────────────── */
    public function taskPriorities(Request $request)
    {
        $mine = $request->boolean('mine');

        return response()->json(
            $this->insightService->prioritizeTasks(
                $request->user()->tenant_id,
                $mine ? $request->user()->id : null,
            )
        );
    }
}
