<?php

namespace App\Services\Accounts\Reports;

/**
 * GSTR-3B summary — output tax liability (outward supplies) vs input tax credit
 * (inward supplies), and the net GST payable per component. Derived from
 * acc_voucher_tax_lines.
 */
class Gstr3bReport extends Gstr1Report
{
    public function generate(int $tenantId, array $filters): array
    {
        $from = $filters['from'] ?? null;
        $to   = $filters['to'] ?? null;

        $outward = $this->summarise($this->taxLines($tenantId, 'outward', $from, $to));
        $inward  = $this->summarise($this->taxLines($tenantId, 'inward', $from, $to));

        $net = [];
        foreach (['cgst', 'sgst', 'igst', 'cess'] as $c) {
            $net[$c] = round($outward[$c] - $inward[$c], 2);
        }
        $net['total'] = round(array_sum($net), 2);

        return [
            'from' => $from, 'to' => $to,
            'outward_liability' => $outward,
            'input_tax_credit'  => $inward,
            'net_payable'       => $net,
        ];
    }

    private function summarise($rows): array
    {
        $s = ['taxable' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0, 'igst' => 0.0, 'cess' => 0.0];
        foreach ($rows as $r) {
            if (in_array($r->tax_type, ['cgst', 'igst'], true)) {
                $s['taxable'] += (float) $r->taxable_amount;
            }
            if (isset($s[$r->tax_type])) {
                $s[$r->tax_type] += (float) $r->tax_amount;
            }
        }
        return array_map(fn ($x) => round($x, 2), $s);
    }
}
