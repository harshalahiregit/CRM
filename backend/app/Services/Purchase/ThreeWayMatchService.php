<?php

namespace App\Services\Purchase;

use App\Models\Purchase\PurchaseInvoice;
use App\Support\Purchase\ThreeWayMatch as Match_;

/**
 * The 3-way match engine: reconciles an invoice's lines against the PO lines
 * they bill for and the quantities a GRN accepted.
 *
 * Pure evaluation — reads the invoice and its links, returns a structured
 * result, mutates nothing. That keeps the arithmetic unit-testable and lets both
 * the approval guard and the read-only preview endpoint share one implementation.
 */
class ThreeWayMatchService
{
    /**
     * Evaluate an invoice. Returns per-line comparisons and an overall verdict.
     *
     * An invoice with no PO link at all cannot be matched — that is a legitimate
     * free-hand invoice, reported as 'not_applicable' rather than a failure.
     */
    public function evaluate(PurchaseInvoice $invoice): array
    {
        $invoice->loadMissing(['items.purchaseOrderItem', 'purchaseOrder']);

        if (! $invoice->purchase_order_id) {
            return [
                'applicable' => false,
                'verdict'    => null,
                'blocked'    => false,
                'summary'    => 'Not raised from a purchase order — 3-way match does not apply.',
                'lines'      => [],
            ];
        }

        $lines    = [];
        $verdicts = [];

        foreach ($invoice->items as $item) {
            $line = $this->evaluateLine($item);
            $lines[]    = $line;
            $verdicts[] = $line['verdict'];
        }

        $verdict  = Match_::rollUp($verdicts);
        $blocked  = in_array($verdict, Match_::BLOCKING, true);

        return [
            'applicable' => true,
            'verdict'    => $verdict,
            'verdict_label' => Match_::label($verdict),
            'blocked'    => $blocked,
            'summary'    => $this->summarise($verdict, $lines),
            'lines'      => $lines,
        ];
    }

    /** True when the invoice may be approved under the block-over/warn-under policy. */
    public function passesForApproval(PurchaseInvoice $invoice): array
    {
        $result = $this->evaluate($invoice);

        return [$result['blocked'] === false, $result];
    }

    private function evaluateLine($item): array
    {
        $billedQty  = (float) $item->qty;
        $billedRate = (float) $item->rate;
        $poItem     = $item->purchaseOrderItem;

        $base = [
            'description' => $item->description,
            'billed_qty'  => $billedQty,
            'billed_rate' => $billedRate,
        ];

        // No PO line behind this invoice line — nothing to reconcile against.
        if (! $poItem) {
            return [
                ...$base,
                'ordered_qty' => null, 'received_qty' => null, 'po_rate' => null,
                'qty_variance' => null, 'price_variance_pct' => null,
                'verdict' => Match_::UNMATCHED,
                'verdict_label' => Match_::label(Match_::UNMATCHED),
            ];
        }

        $orderedQty  = (float) $poItem->qty;
        $receivedQty = (float) $poItem->received_qty;   // running total of GRN-accepted
        $poRate      = (float) $poItem->rate;

        // You can only legitimately be billed for what was BOTH ordered and
        // received — the smaller of the two is the ceiling.
        $billable    = min($orderedQty, $receivedQty);
        $qtyVariance = round($billedQty - $billable, 4);

        $priceVarPct = $poRate > 0 ? round(($billedRate - $poRate) / $poRate, 4) : ($billedRate > 0 ? 1.0 : 0.0);

        $verdict = $this->lineVerdict($qtyVariance, $priceVarPct);

        return [
            ...$base,
            'ordered_qty'        => $orderedQty,
            'received_qty'       => $receivedQty,
            'billable_qty'       => $billable,
            'po_rate'            => $poRate,
            'qty_variance'       => $qtyVariance,
            'price_variance_pct' => $priceVarPct,
            'verdict'            => $verdict,
            'verdict_label'      => Match_::label($verdict),
        ];
    }

    private function lineVerdict(float $qtyVariance, float $priceVarPct): string
    {
        // Over-billing on quantity is the hard failure — billed more than the
        // smaller of ordered/received, beyond the rounding tolerance.
        if ($qtyVariance > Match_::QTY_TOLERANCE) {
            return Match_::OVER_BILLED;
        }

        // Rate above the PO price beyond tolerance is also a block: a vendor
        // cannot unilaterally raise the agreed price on the invoice.
        if ($priceVarPct > Match_::PRICE_TOLERANCE_PCT) {
            return Match_::PRICE_VARIANCE;
        }

        // Billed for less than received — allowed, but surfaced.
        if ($qtyVariance < -Match_::QTY_TOLERANCE) {
            return Match_::UNDER_BILLED;
        }

        return Match_::MATCHED;
    }

    private function summarise(string $verdict, array $lines): string
    {
        $flagged = array_filter($lines, fn ($l) => $l['verdict'] !== Match_::MATCHED);

        return match ($verdict) {
            Match_::MATCHED      => 'All lines match the order and receipt.',
            Match_::OVER_BILLED  => count($flagged).' line(s) bill more than was ordered or received — approval blocked.',
            Match_::PRICE_VARIANCE => count($flagged).' line(s) bill above the agreed PO price — approval blocked.',
            Match_::UNDER_BILLED => count($flagged).' line(s) bill less than received — allowed, review advised.',
            Match_::UNMATCHED    => count($flagged).' line(s) have no PO line to match against.',
            default              => 'Reviewed.',
        };
    }
}
