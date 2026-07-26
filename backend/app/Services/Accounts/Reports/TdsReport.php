<?php

namespace App\Services\Accounts\Reports;

use Illuminate\Support\Facades\DB;

/**
 * TDS summary (26Q-style) — tax deducted at source, grouped by section, from
 * inward tds acc_voucher_tax_lines over the period.
 */
class TdsReport extends ReportService
{
    public function generate(int $tenantId, array $filters): array
    {
        $rows = DB::table('acc_voucher_tax_lines as tl')
            ->join('acc_vouchers as v', 'v.id', '=', 'tl.voucher_id')
            ->where('tl.tenant_id', $tenantId)
            ->where('tl.tax_type', 'tds')
            ->where('v.status', 'posted')->whereNull('v.deleted_at')
            ->when($filters['from'] ?? null, fn ($q) => $q->whereDate('v.date', '>=', $filters['from']))
            ->when($filters['to'] ?? null, fn ($q) => $q->whereDate('v.date', '<=', $filters['to']))
            ->groupBy('tl.tds_section_code')
            ->selectRaw('tl.tds_section_code as section, COUNT(*) as deductions,
                COALESCE(SUM(tl.taxable_amount),0) as base_amount, COALESCE(SUM(tl.tax_amount),0) as tds_amount')
            ->get();

        $sections = $rows->map(fn ($r) => [
            'section'    => $r->section,
            'deductions' => (int) $r->deductions,
            'base'       => round((float) $r->base_amount, 2),
            'tds'        => round((float) $r->tds_amount, 2),
        ])->all();

        return [
            'from' => $filters['from'] ?? null, 'to' => $filters['to'] ?? null,
            'sections' => $sections,
            'total_tds' => round((float) $rows->sum('tds_amount'), 2),
        ];
    }
}
