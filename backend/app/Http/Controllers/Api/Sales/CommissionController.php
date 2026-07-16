<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreCommissionRuleRequest;
use App\Models\Sales\CommissionEntry;
use App\Models\Sales\CommissionRule;
use App\Services\Sales\CommissionService;
use Illuminate\Http\Request;

class CommissionController extends Controller
{
    public function __construct(private CommissionService $service)
    {
    }

    /* ── Rules ── */
    public function rules(Request $request)
    {
        return response()->json($this->service->rules($request->user()->tenant_id));
    }

    public function storeRule(StoreCommissionRuleRequest $request)
    {
        $rule = $this->service->createRule($request->validated(), $request->user()->tenant_id, $request->user()->id);
        return response()->json($rule, 201);
    }

    public function updateRule(StoreCommissionRuleRequest $request, CommissionRule $commissionRule)
    {
        return response()->json($this->service->updateRule($commissionRule, $request->validated(), $request->user()->tenant_id));
    }

    public function deleteRule(Request $request, CommissionRule $commissionRule)
    {
        $this->service->deleteRule($commissionRule, $request->user()->tenant_id);
        return response()->json(['message' => 'Rule deleted']);
    }

    /* ── Entries ── */
    public function entries(Request $request)
    {
        return response()->json($this->service->entries($request->user()->tenant_id, $request->only(['status', 'payout_status', 'staff_id'])));
    }

    public function summary(Request $request)
    {
        return response()->json($this->service->summary($request->user()->tenant_id));
    }

    public function approve(Request $request, CommissionEntry $commission)
    {
        return response()->json($this->service->approve($commission, $request->user()->tenant_id, $request->user()->id));
    }

    public function reject(Request $request, CommissionEntry $commission)
    {
        return response()->json($this->service->reject($commission, $request->user()->tenant_id, $request->user()->id));
    }

    public function markPaid(Request $request, CommissionEntry $commission)
    {
        return response()->json($this->service->markPaid($commission, $request->user()->tenant_id));
    }
}
