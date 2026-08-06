<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Services\Hr\OrgChartService;
use Illuminate\Http\Request;

/**
 * #29 — the organisation chart, derived on read from the employee table.
 *
 * No HR-queue gate: an org chart is a directory, and everyone in the company is
 * expected to be able to see who reports to whom. It exposes no salary, no
 * contact detail and no status beyond active/on-leave.
 */
class OrgChartController extends Controller
{
    public function __construct(private OrgChartService $chart)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->chart->tree(
            (int) $request->user()->tenant_id,
            $request->only(['department', 'worker_type', 'include_inactive'])
        ));
    }
}
