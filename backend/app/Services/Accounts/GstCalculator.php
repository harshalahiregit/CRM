<?php

namespace App\Services\Accounts;

use App\Models\Accounts\GstRate;

/**
 * GST component maths. Place of supply decides the split: intra-state (buyer state
 * == seller state) → CGST + SGST; inter-state → IGST. Cess applies either way.
 * (spec §8: CGST+SGST for intra-state, IGST for inter-state, by place of supply.)
 */
class GstCalculator
{
    public function isIntraState(?string $sellerState, ?string $buyerState): bool
    {
        return $sellerState && $buyerState && strtoupper(trim($sellerState)) === strtoupper(trim($buyerState));
    }

    /**
     * @return array<array{tax_type:string, rate:float, tax_amount:float}>
     */
    public function breakup(float $taxable, GstRate $rate, bool $intra): array
    {
        $components = [];
        $pct = fn ($p) => round($taxable * (float) $p / 100, 2);

        if ($intra) {
            if ((float) $rate->cgst > 0) {
                $components[] = ['tax_type' => 'cgst', 'rate' => (float) $rate->cgst, 'tax_amount' => $pct($rate->cgst)];
            }
            if ((float) $rate->sgst > 0) {
                $components[] = ['tax_type' => 'sgst', 'rate' => (float) $rate->sgst, 'tax_amount' => $pct($rate->sgst)];
            }
        } elseif ((float) $rate->igst > 0) {
            $components[] = ['tax_type' => 'igst', 'rate' => (float) $rate->igst, 'tax_amount' => $pct($rate->igst)];
        }

        if ((float) $rate->cess > 0) {
            $components[] = ['tax_type' => 'cess', 'rate' => (float) $rate->cess, 'tax_amount' => $pct($rate->cess)];
        }

        return $components;
    }
}
