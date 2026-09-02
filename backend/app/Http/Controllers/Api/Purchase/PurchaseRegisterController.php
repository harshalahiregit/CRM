<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Controller;
use App\Models\Purchase\PurchaseVendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cross-vendor registers — prequalification, risk and due diligence.
 *
 * Purchase already had all three, but only ONE VENDOR AT A TIME
 * (/vendors/{id}/prequalification, /risk, /due-diligence). That answers "how did
 * this vendor score?" and never "who has not been assessed yet?", which is the
 * question a register exists for and the only one that surfaces the gap.
 *
 * Everything here reads columns already on purchase_vendors — no new tables. The
 * per-vendor endpoints stay the place assessments are WRITTEN; these are the
 * read-across.
 */
class PurchaseRegisterController extends Controller
{
    /**
     * Prequalification register.
     *
     * Unassessed vendors are INCLUDED, not filtered out. A register that showed
     * only the scored ones would report a perfect site while half the vendors
     * had never been looked at.
     */
    public function prequalification(Request $request)
    {
        $rows = $this->base($request)
            ->get(['id', 'company_name', 'purchase_vendor_code', 'status', 'category',
                   'qualification_status', 'qualification_score', 'qualification_notes',
                   'qualified_at', 'qualified_by']);

        // Counted against the statuses the scorer can actually PRODUCE
        // (PurchaseQualificationStatus / config purchase_prequalification.outcomes):
        // Qualified >= 82, Conditional >= 60, otherwise Not_Qualified. There is no
        // 'Rejected' outcome — a counter for one would have sat at zero forever
        // and read as "nothing was ever turned down".
        //
        // Unassessed means null OR 'Pending': a vendor sitting at Pending has not
        // been scored, and splitting those two would understate the real backlog.
        return response()->json([
            'data'   => $rows,
            'totals' => [
                'vendors'       => $rows->count(),
                'qualified'     => $rows->where('qualification_status', 'Qualified')->count(),
                'conditional'   => $rows->where('qualification_status', 'Conditional')->count(),
                'not_qualified' => $rows->where('qualification_status', 'Not_Qualified')->count(),
                'unassessed'    => $rows->filter(fn ($v) => $v->qualification_status === null
                    || $v->qualification_status === 'Pending')->count(),
            ],
        ]);
    }

    /**
     * Risk register.
     *
     * Sorted by score DESCENDING with unassessed last: the point of the page is
     * the riskiest vendor, and alphabetical order buries it.
     */
    public function risk(Request $request)
    {
        $rows = $this->base($request)
            ->orderByRaw('CASE WHEN risk_score IS NULL THEN 1 ELSE 0 END, risk_score DESC')
            ->get(['id', 'company_name', 'purchase_vendor_code', 'status', 'category',
                   'risk_level', 'risk_score', 'risk_notes', 'risk_assessed_at']);

        $stale = $rows->filter(fn ($v) => $v->risk_assessed_at
            && \Carbon\Carbon::parse($v->risk_assessed_at)->lt(now()->subYear()))->count();

        return response()->json([
            'data'   => $rows,
            'totals' => [
                'vendors'    => $rows->count(),
                'high'       => $rows->whereIn('risk_level', ['High', 'Critical'])->count(),
                'unassessed' => $rows->whereNull('risk_level')->count(),
                // An assessment older than a year is not a current view of the
                // vendor, and counting it as "assessed" is how a register goes
                // quietly out of date.
                'stale'      => $stale,
            ],
        ]);
    }

    /**
     * Due-diligence register.
     *
     * The detail lives in purchase_due_diligences; this lists one row per vendor
     * with whether a record exists and when it was last touched.
     */
    public function dueDiligence(Request $request)
    {
        $tenantId = (int) $request->user()->tenant_id;
        $rows = $this->base($request)->get(['id', 'company_name', 'purchase_vendor_code', 'status', 'category']);

        $dd = collect();
        if (Schema::hasTable('purchase_due_diligences')) {
            $dd = DB::table('purchase_due_diligences')
                ->where('tenant_id', $tenantId)
                ->get()
                ->keyBy('purchase_vendor_id');
        }

        $data = $rows->map(function ($v) use ($dd) {
            $rec = $dd->get($v->id);

            return [
                'id'                   => $v->id,
                'company_name'         => $v->company_name,
                'purchase_vendor_code' => $v->purchase_vendor_code,
                'status'               => $v->status,
                'category'             => $v->category,
                'has_record'           => $rec !== null,
                'dd_status'            => $rec->status ?? null,
                'updated_at'           => $rec->updated_at ?? null,
            ];
        });

        return response()->json([
            'data'   => $data->values(),
            'totals' => [
                'vendors'  => $data->count(),
                'recorded' => $data->where('has_record', true)->count(),
                'missing'  => $data->where('has_record', false)->count(),
            ],
        ]);
    }

    /** Tenant-scoped vendor base, with the filters every register shares. */
    private function base(Request $request)
    {
        $q = PurchaseVendor::where('tenant_id', (int) $request->user()->tenant_id);

        if ($request->filled('status')) {
            $q->where('status', $request->query('status'));
        }
        if ($request->filled('category')) {
            $q->where('category', $request->query('category'));
        }
        if ($request->filled('q')) {
            $term = '%'.$request->query('q').'%';
            $q->where(fn ($w) => $w->where('company_name', 'like', $term)
                ->orWhere('purchase_vendor_code', 'like', $term));
        }

        return $q;
    }
}
