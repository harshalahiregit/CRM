<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user     = $request->user();
        $tenantId = $user->tenant_id;

        $data = [
            'contacts_count'    => 0,
            'open_deals'        => 0,
            'tasks_due_today'   => 0,
            'overdue_invoices'  => 0,
            'pipeline_value'    => 0,
            'win_rate'          => 0,
            'revenue_this_month'=> 0,
        ];

        // As modules are built, add real queries here
        // Example: $data['contacts_count'] = Contact::where('tenant_id', $tenantId)->count();

        return response()->json([
            'status'  => 'success',
            'message' => 'Dashboard data',
            'data'    => $data,
        ]);
    }
}
