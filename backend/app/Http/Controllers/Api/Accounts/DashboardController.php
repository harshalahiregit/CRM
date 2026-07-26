<?php

namespace App\Http\Controllers\Api\Accounts;

use App\Http\Controllers\Controller;
use App\Services\Accounts\DashboardService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private DashboardService $dashboard)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->dashboard->summary(
            $request->user()->tenant_id,
            $request->query('fy')   // optional financial year e.g. "2025-2026"
        ));
    }
}
