<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Services\Sales\SalesActivityService;
use Illuminate\Http\Request;

class SalesActivityController extends Controller
{
    public function __construct(private SalesActivityService $activityService)
    {
    }

    /* ── Unified timeline for a subject ────────────────────────── */
    public function index(Request $request)
    {
        $data = $request->validate([
            'subject_type' => ['required', 'string'],
            'subject_id'   => ['required', 'integer'],
        ]);

        $events = $this->activityService->timeline(
            $request->user()->tenant_id,
            $data['subject_type'],
            (int) $data['subject_id'],
        );

        return response()->json($events);
    }
}
