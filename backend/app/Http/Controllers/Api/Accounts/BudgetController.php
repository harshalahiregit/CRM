<?php

namespace App\Http\Controllers\Api\Accounts;

use App\Http\Controllers\Controller;
use App\Models\Accounts\Budget;
use App\Services\Accounts\BudgetService;
use Illuminate\Http\Request;

class BudgetController extends Controller
{
    public function __construct(private BudgetService $budgets)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->budgets->list($request->user()->tenant_id));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'              => 'required|string|max:255',
            'financial_year_id' => 'required|integer',
        ]);
        return response()->json($this->budgets->create($data, $request->user()->tenant_id), 201);
    }

    public function show(Budget $budget, Request $request)
    {
        return response()->json($this->budgets->show($budget, $request->user()->tenant_id));
    }

    public function saveLines(Budget $budget, Request $request)
    {
        $data = $request->validate([
            'lines'                 => 'required|array',
            'lines.*.ledger_id'     => 'required|integer',
            'lines.*.annual_amount' => 'required|numeric',
        ]);
        return response()->json($this->budgets->saveLines($budget, $data['lines'], $request->user()->tenant_id));
    }

    public function budgetVsActual(Budget $budget, Request $request)
    {
        return response()->json($this->budgets->budgetVsActual($budget, $request->user()->tenant_id));
    }

    public function destroy(Budget $budget, Request $request)
    {
        $this->budgets->delete($budget, $request->user()->tenant_id);
        return response()->json(['message' => 'Budget deleted']);
    }
}
