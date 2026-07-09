<?php

namespace App\Http\Controllers\Api\Helpdesk;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Services\Helpdesk\HelpdeskService;
use Illuminate\Http\Request;

class HelpdeskDashboardController extends Controller
{
    use ApiResponse;

    public function __construct(private HelpdeskService $helpdesk)
    {
    }

    /* ── Manager analytics ─────────────────────────────────────── */
    public function analytics(Request $request)
    {
        $data = $this->helpdesk->analytics($request->user()->tenant_id);

        return $this->success($data, 'Analytics retrieved');
    }
}
