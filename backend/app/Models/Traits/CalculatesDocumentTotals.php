<?php

namespace App\Models\Traits;

use App\Models\Sales\SalesLineItem;

/**
 * Shared totals math for Proposal / Estimate / SalesInvoice (meeting 3.1-3.2):
 *
 *   line math (qty × rate − line discount)
 *   → subtotal
 *   → document discount (fixed | percent)
 *   → before_tax: tax proportionally on the discounted base (per-rate round(2))
 *     after_tax:  tax on the full base, discount subtracted after
 *   → total
 *
 * Also computes supply_type (intra → CGST+SGST split, inter → IGST) by
 * comparing the tenant's registered state with the document's billing state.
 * Totals are supply-neutral — the split is presentation only.
 */
trait CalculatesDocumentTotals
{
    /**
     * Tax grouped by NAME across the document's lines — CGST 9%, SGST 9%,
     * IGST 18% … — so totals blocks (screen, portal and PDF) can itemise each
     * one instead of showing a single combined figure.
     *
     * Amounts are scaled by the same proportion the tax total was (a
     * before-tax discount shrinks the taxable base), so the rows always sum to
     * `tax_total`. Lines with no named taxes are skipped: those documents fall
     * back to the supply-type split at the render layer.
     *
     * @return array<int, array{name: string, rate: float, amount: float}>
     */
    public function taxBreakdown(): array
    {
        $this->loadMissing('lineItems');

        $gross = 0.0;
        $byName = [];

        foreach ($this->lineItems as $li) {
            $taxes = $li->taxes;
            if (! is_array($taxes) || $taxes === []) {
                continue;
            }
            $base = (float) $li->qty * (float) $li->rate;
            $afterDiscount = (($this->discount_type ?? null) === 'after_tax')
                ? $base - SalesLineItem::discountAmount([
                    'qty' => $li->qty, 'rate' => $li->rate,
                    'discount' => $li->discount, 'discount_mode' => $li->discount_mode,
                  ])
                : $base;

            foreach ($taxes as $tax) {
                $rate = (float) ($tax['rate'] ?? 0);
                $name = (string) ($tax['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $amount = $afterDiscount * ($rate / 100);
                $gross += $amount;
                $key = $name.'|'.$rate;
                $byName[$key] ??= ['name' => $name, 'rate' => $rate, 'amount' => 0.0];
                $byName[$key]['amount'] += $amount;
            }
        }

        if ($byName === []) {
            return [];
        }

        // Scale to the actual tax_total so a before-tax discount is reflected.
        $factor = $gross > 0 ? ((float) $this->tax_total) / $gross : 1.0;

        $rows = array_values(array_map(fn ($row) => [
            'name'   => $row['name'],
            'rate'   => round($row['rate'], 2),
            'amount' => round($row['amount'] * $factor, 2),
        ], $byName));

        usort($rows, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $rows;
    }

    /**
     * @return array{subtotal: float, line_discounts: float, doc_discount: float,
     *               all_discounts: float, after_discount: float, tax_total: float, total: float}
     */
    protected function computeDocumentTotals(): array
    {
        // `discount_type` controls WHEN tax is worked out relative to the line
        // discounts:
        //   before_tax → tax is calculated BEFORE the discount (on the full
        //                line value), then the discount comes off the total.
        //   after_tax  → the discount is taken first and tax is calculated on
        //                the discounted value.
        $taxAfterDiscount = ($this->discount_type ?? null) === 'after_tax';

        $lineSubtotal = 0.0;
        $lineDiscounts = 0.0;
        $taxTotal = 0.0;

        foreach ($this->lineItems as $li) {
            $base = (float) $li->qty * (float) $li->rate;
            $discount = SalesLineItem::discountAmount([
                'qty' => $li->qty, 'rate' => $li->rate,
                'discount' => $li->discount, 'discount_mode' => $li->discount_mode,
            ]);

            $taxableBase = $taxAfterDiscount ? ($base - $discount) : $base;
            $taxTotal += round($taxableBase * ((float) $li->tax / 100), 2);

            $lineSubtotal += $base;
            $lineDiscounts += $discount;
        }

        $baseAfterLines = $lineSubtotal - $lineDiscounts;
        $docDiscount = 0.0;   // document-level discount retired — line discounts only
        $total = $baseAfterLines + $taxTotal;

        return [
            'subtotal'       => round($lineSubtotal, 2),
            'line_discounts' => round($lineDiscounts, 2),
            'doc_discount'   => round($docDiscount, 2),
            'all_discounts'  => round($lineDiscounts + $docDiscount, 2),
            'after_discount' => round($baseAfterLines - $docDiscount, 2),
            'tax_total'      => round($taxTotal, 2),
            'total'          => round($total, 2),
        ];
    }

    /** intra | inter | null (unknown — either state missing). */
    protected function computeSupplyType(): ?string
    {
        $tenantState = \App\Models\Tenant::whereKey($this->tenant_id)->value('state');

        // Snapshot on the document wins; otherwise fall back to the linked
        // customer's billing state.
        $docState = $this->billing_state ?? $this->state ?? null;
        if (! $docState) {
            $clientId = $this->client_id
                ?? ((($this->rel_type ?? null) === 'customer') ? $this->rel_id : null);
            if ($clientId) {
                $docState = \App\Models\Customer\Client::whereKey($clientId)->value('billing_state');
            }
        }

        $norm = fn ($v) => $v === null ? null : mb_strtolower(trim((string) $v));
        [$a, $b] = [$norm($tenantState), $norm($docState)];

        if (! $a || ! $b) {
            return null;
        }

        return $a === $b ? 'intra' : 'inter';
    }
}
