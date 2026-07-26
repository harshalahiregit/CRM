<?php

namespace App\Services\Accounts\Reports;

use Illuminate\Support\Facades\DB;

/**
 * GSTR-1 (outward supplies) summary — rate-wise taxable value and tax split, from
 * outward acc_voucher_tax_lines over the period. Taxable is counted once per line
 * (from the CGST/IGST component) to avoid double-counting the paired SGST row.
 */
class Gstr1Report extends ReportService
{
    public function generate(int $tenantId, array $filters): array
    {
        $rows = $this->taxLines($tenantId, 'outward', $filters['from'] ?? null, $filters['to'] ?? null);

        $byRate = [];   // rate% => [taxable, cgst, sgst, igst, cess]
        $totals = ['taxable' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0, 'igst' => 0.0, 'cess' => 0.0];

        foreach ($rows as $r) {
            $amt = (float) $r->tax_amount;
            $taxable = (float) $r->taxable_amount;
            switch ($r->tax_type) {
                case 'igst':
                    $slab = round((float) $r->rate, 2);
                    $this->bucket($byRate, $slab);
                    $byRate[$slab]['igst'] += $amt; $byRate[$slab]['taxable'] += $taxable;
                    $totals['igst'] += $amt; $totals['taxable'] += $taxable;
                    break;
                case 'cgst':
                    $slab = round((float) $r->rate * 2, 2);
                    $this->bucket($byRate, $slab);
                    $byRate[$slab]['cgst'] += $amt; $byRate[$slab]['taxable'] += $taxable;
                    $totals['cgst'] += $amt; $totals['taxable'] += $taxable;
                    break;
                case 'sgst':
                    $slab = round((float) $r->rate * 2, 2);
                    $this->bucket($byRate, $slab);
                    $byRate[$slab]['sgst'] += $amt;
                    $totals['sgst'] += $amt;
                    break;
                case 'cess':
                    $totals['cess'] += $amt;
                    break;
            }
        }

        ksort($byRate);
        $rateRows = [];
        foreach ($byRate as $rate => $v) {
            $rateRows[] = ['rate' => $rate] + array_map(fn ($x) => round($x, 2), $v);
        }

        return [
            'from' => $filters['from'] ?? null, 'to' => $filters['to'] ?? null,
            'rows' => $rateRows,
            'totals' => array_map(fn ($x) => round($x, 2), $totals),
        ];
    }

    private function bucket(array &$byRate, float $rate): void
    {
        if (! isset($byRate[$rate])) {
            $byRate[$rate] = ['taxable' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0, 'igst' => 0.0, 'cess' => 0.0];
        }
    }

    protected function taxLines(int $tenantId, string $direction, ?string $from, ?string $to)
    {
        return DB::table('acc_voucher_tax_lines as tl')
            ->join('acc_vouchers as v', 'v.id', '=', 'tl.voucher_id')
            ->where('tl.tenant_id', $tenantId)
            ->where('tl.direction', $direction)
            ->where('v.status', 'posted')->whereNull('v.deleted_at')
            ->when($from, fn ($q) => $q->whereDate('v.date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('v.date', '<=', $to))
            ->get(['tl.tax_type', 'tl.rate', 'tl.taxable_amount', 'tl.tax_amount', 'tl.tds_section_code']);
    }
}
