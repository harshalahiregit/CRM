<?php

namespace App\Http\Controllers\Api\Tpv;

use App\Http\Controllers\Controller;
use App\Models\Tpv\TpvWorkerMedical;
use App\Support\Tpv\TpvMedicalFitness;
use Illuminate\Http\Request;

/**
 * §3/§16 — the Medical Fitness register: a dedicated cross-workforce view of
 * worker medical examinations (previously reachable only inside the worker
 * wizard). Read-only, tenant-scoped, with fitness/expiry filters.
 */
class TpvMedicalController extends Controller
{
    public function index(Request $request)
    {
        $tid = $request->user()->tenant_id;

        $rows = TpvWorkerMedical::where('tenant_id', $tid)
            ->with(['worker:id,name,worker_code,vendor_id,designation', 'worker.vendor:id,company_name'])
            ->when($request->query('fitness_status'), fn ($q, $s) => $q->where('fitness_status', $s))
            ->when($request->query('vendor_id'), fn ($q, $v) => $q->whereHas('worker', fn ($w) => $w->where('vendor_id', $v)))
            ->when($request->query('expiry') === 'expired', fn ($q) => $q->whereNotNull('valid_until')->whereDate('valid_until', '<', now()))
            ->when($request->query('expiry') === 'expiring', fn ($q) => $q->whereNotNull('valid_until')
                ->whereDate('valid_until', '>=', now())->whereDate('valid_until', '<=', now()->addDays(30)))
            ->latest('exam_date')
            ->limit(2000)
            ->get();

        $summary = [
            'total'    => $rows->count(),
            'fit'      => $rows->where('fitness_status', TpvMedicalFitness::FIT)->count(),
            'unfit'    => $rows->where('fitness_status', TpvMedicalFitness::UNFIT)->count(),
            'pending'  => $rows->where('fitness_status', TpvMedicalFitness::PENDING)->count(),
            'expired'  => $rows->filter(fn ($m) => $m->is_expired)->count(),
        ];

        return response()->json([
            'data'      => $rows,
            'summary'   => $summary,
            'statuses'  => TpvMedicalFitness::ALL,
        ]);
    }
}
