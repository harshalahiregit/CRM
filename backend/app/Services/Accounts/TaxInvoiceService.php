<?php

namespace App\Services\Accounts;

use App\Exceptions\BusinessException;
use App\Models\Accounts\CompanyProfile;
use App\Models\Accounts\GstRate;
use App\Models\Accounts\Ledger;
use App\Models\Accounts\TdsSection;
use App\Models\Accounts\Voucher;

/**
 * Builds and posts a GST tax invoice / purchase bill: given taxable line amounts +
 * GST rate + place of supply, it computes the CGST/SGST or IGST split, constructs
 * the balanced double-entry voucher (party ↔ income/expense ↔ tax ledgers), and
 * records the tax breakup for returns — all through the one PostingService gate.
 * Optional TDS on purchases deducts to a TDS-payable ledger.
 *
 * This is the production "book a GST invoice" experience: the user enters business
 * facts, not debits/credits.
 */
class TaxInvoiceService
{
    /** Duties & Taxes ledger names seeded by AccountsSetupService. */
    private const GST_LEDGERS = [
        'cgst_out' => 'Output CGST', 'sgst_out' => 'Output SGST', 'igst_out' => 'Output IGST', 'cess_out' => 'Output Cess',
        'cgst_in' => 'Input CGST', 'sgst_in' => 'Input SGST', 'igst_in' => 'Input IGST', 'cess_in' => 'Input Cess',
        'tds' => 'TDS Payable',
    ];

    public function __construct(private PostingService $posting, private GstCalculator $gst)
    {
    }

    public function post(array $data, int $tenantId, ?int $userId): Voucher
    {
        $isSales = ($data['type'] ?? 'sales') === 'sales';
        $buyerState = $data['place_of_supply_state'] ?? null;
        $sellerState = optional(CompanyProfile::forTenant($tenantId)->first())->state_code;
        $intra = $this->gst->isIntraState($sellerState, $buyerState);

        $party = $this->ledger($tenantId, (int) ($data['party_ledger_id'] ?? 0), 'Party');

        $items = $data['items'] ?? [];
        if (empty($items)) {
            throw new BusinessException('Add at least one line.');
        }

        // Preload item ledgers and GST rates in two queries (no per-item lookups).
        $itemLedgers = Ledger::forTenant($tenantId)->whereIn('id', array_filter(array_column($items, 'ledger_id')))->get()->keyBy('id');
        $rates = GstRate::forTenant($tenantId)->whereIn('id', array_filter(array_column($items, 'gst_rate_id')))->get()->keyBy('id');

        $lines = [];       // balanced journal legs
        $taxLines = [];     // return metadata
        $componentTotals = ['cgst' => 0.0, 'sgst' => 0.0, 'igst' => 0.0, 'cess' => 0.0];
        $totalTaxable = 0.0;

        foreach ($items as $i => $item) {
            $taxable = round((float) ($item['taxable_amount'] ?? 0), 2);
            if ($taxable <= 0) {
                throw new BusinessException('Line ' . ($i + 1) . ': taxable amount must be positive.');
            }
            $itemLedger = $itemLedgers->get((int) ($item['ledger_id'] ?? 0));
            if (! $itemLedger) {
                throw new BusinessException('Line ' . ($i + 1) . ': ledger not found.');
            }
            $totalTaxable += $taxable;

            // income (sales) is credited; expense/asset (purchase) is debited
            $lines[] = ['ledger_id' => $itemLedger->id, ($isSales ? 'credit' : 'debit') => $taxable];

            $rate = isset($item['gst_rate_id']) ? $rates->get($item['gst_rate_id']) : null;
            if ($rate) {
                foreach ($this->gst->breakup($taxable, $rate, $intra) as $c) {
                    $componentTotals[$c['tax_type']] += $c['tax_amount'];
                    $taxLines[] = [
                        'tax_type' => $c['tax_type'], 'rate' => $c['rate'],
                        'taxable_amount' => $taxable, 'tax_amount' => $c['tax_amount'],
                        'hsn_sac_id' => $item['hsn_sac_id'] ?? null,
                        'place_of_supply_state' => $buyerState,
                        'direction' => $isSales ? 'outward' : 'inward',
                    ];
                }
            }
        }

        // Tax ledger legs (same side as the income/expense legs).
        $suffix = $isSales ? '_out' : '_in';
        $totalTax = 0.0;
        foreach (['cgst', 'sgst', 'igst', 'cess'] as $comp) {
            $amt = round($componentTotals[$comp], 2);
            if ($amt <= 0) {
                continue;
            }
            $totalTax += $amt;
            $taxLedger = $this->ledger($tenantId, null, ucfirst($comp), self::GST_LEDGERS[$comp . $suffix]);
            $lines[] = ['ledger_id' => $taxLedger->id, ($isSales ? 'credit' : 'debit') => $amt];
        }

        $grandTotal = round($totalTaxable + $totalTax, 2);

        // TDS on purchases: deduct from what we owe the vendor.
        $tdsAmount = 0.0;
        if (! $isSales && ! empty($data['tds_section_code'])) {
            $section = TdsSection::forTenant($tenantId)->active()->where('section_code', $data['tds_section_code'])->first();
            if (! $section) {
                throw new BusinessException("TDS section {$data['tds_section_code']} not found.");
            }
            $tdsAmount = round($totalTaxable * (float) $section->rate_percent / 100, 2);
            if ($tdsAmount > 0) {
                $tdsLedger = $this->ledger($tenantId, null, 'TDS Payable', self::GST_LEDGERS['tds']);
                $lines[] = ['ledger_id' => $tdsLedger->id, 'credit' => $tdsAmount];
                $taxLines[] = [
                    'tax_type' => 'tds', 'rate' => (float) $section->rate_percent,
                    'taxable_amount' => $totalTaxable, 'tax_amount' => $tdsAmount,
                    'tds_section_code' => $section->section_code, 'direction' => 'inward',
                ];
            }
        }

        // Party leg balances the voucher.
        if ($isSales) {
            $lines[] = ['ledger_id' => $party->id, 'debit' => $grandTotal];            // debtor owes us
        } else {
            $lines[] = ['ledger_id' => $party->id, 'credit' => round($grandTotal - $tdsAmount, 2)]; // we owe vendor, net of TDS
        }

        return $this->posting->post([
            'voucher_type_code' => $isSales ? 'sales' : 'purchase',
            'date'              => $data['date'],
            'narration'         => $data['narration'] ?? ($isSales ? 'Tax invoice' : 'Purchase bill'),
            'reference_no'      => $data['reference_no'] ?? null,
            'party_id'          => $party->id,
            'lines'             => $lines,
            'tax_lines'         => $taxLines,
        ], $tenantId, $userId);
    }

    private function ledger(int $tenantId, ?int $id, string $label, ?string $byName = null): Ledger
    {
        $ledger = $byName
            ? Ledger::forTenant($tenantId)->where('name', $byName)->first()
            : Ledger::forTenant($tenantId)->find($id);

        if (! $ledger) {
            throw new BusinessException($byName
                ? "The \"{$byName}\" ledger is missing. Run accounts setup first."
                : "{$label} ledger not found.");
        }
        return $ledger;
    }
}
