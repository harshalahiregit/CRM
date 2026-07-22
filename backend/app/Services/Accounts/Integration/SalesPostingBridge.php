<?php

namespace App\Services\Accounts\Integration;

use App\Exceptions\BusinessException;
use App\Models\Accounts\AccountGroup;
use App\Models\Accounts\AccountMapping;
use App\Models\Accounts\Ledger;
use App\Models\Accounts\Voucher;
use App\Models\Customer\Client;
use App\Models\Customer\ClientExpense;
use App\Models\Sales\CreditNote;
use App\Models\Sales\CreditNoteRefund;
use App\Models\Sales\SalesInvoice;
use App\Models\Sales\SalesPayment;
use App\Services\Accounts\PostingService;
use App\Services\Accounts\ReversalService;
use Illuminate\Support\Facades\Log;

/**
 * The Posting-Rule Resolver (spec v2 §7): turns a Sales/Customer document into a
 * balanced voucher and posts it through PostingService — the one gate. Ledgers are
 * resolved by semantic role via account_mappings; the party (debtor) ledger is
 * resolved/created per client. Every voucher back-links to its source
 * (source_type/source_id) so posting is idempotent and reversible on cancel.
 *
 * This service READS other modules' models but never writes to their tables.
 */
class SalesPostingBridge
{
    public function __construct(
        private PostingService $posting,
        private ReversalService $reversal,
    ) {
    }

    /* ── Sales invoice → Dr Debtor / Cr Sales / Cr Output GST ─────────── */
    public function postInvoice(SalesInvoice $inv): ?Voucher
    {
        $tenantId = $inv->tenant_id;
        if (! $inv->client_id || (float) $inv->total <= 0) {
            return null;
        }
        if ($this->postedVoucher('sales_invoice', $inv->id, $tenantId)) {
            return null; // idempotent
        }

        $party = $this->partyLedger($inv->client_id, $tenantId);
        $total = round((float) $inv->total, 2);
        $tax   = round((float) $inv->tax_total, 2);
        $net   = round($total - $tax, 2);

        $lines = [['ledger_id' => $party->id, 'debit' => $total]];
        if ($net != 0) {
            $lines[] = ['ledger_id' => $this->role('sales_income', $tenantId), 'credit' => $net];
        }
        $taxLines = [];
        if ($tax > 0) {
            $this->appendGst($lines, $taxLines, $tenantId, $tax, $net, $inv->supply_type, $inv->billing_state, 'outward', 'output');
        }

        return $this->post($tenantId, 'sales', $inv->date, $inv->client_id, $inv->number,
            'sales_invoice', $inv->id, "Sales invoice {$inv->number}", $lines, $taxLines, $inv->created_by);
    }

    /* ── Payment received → Dr Bank/Cash (+ Dr TDS Recv) / Cr Debtor ──── */
    public function postReceipt(SalesPayment $pay): ?Voucher
    {
        $tenantId = $pay->tenant_id;
        $invoice = SalesInvoice::find($pay->invoice_id);
        if (! $invoice || ! $invoice->client_id) {
            return null;
        }
        if (($pay->payment_type ?? 'received') !== 'received') {
            return null; // only customer receipts flow through sales_payments
        }
        if ($this->postedVoucher('sales_payment', $pay->id, $tenantId)) {
            return null;
        }

        $party  = $this->partyLedger($invoice->client_id, $tenantId);
        $amount = round((float) $pay->amount, 2);
        $tds    = round((float) ($pay->tds_amount ?? 0), 2);
        if ($amount <= 0 && $tds <= 0) {
            return null;
        }

        $lines = [['ledger_id' => $this->bankLedger($pay->mode, $tenantId), 'debit' => $amount]];
        if ($tds > 0) {
            // Customer-deducted TDS on our income = an asset (TDS receivable), not our 26Q liability.
            $lines[] = ['ledger_id' => $this->role('tds_receivable', $tenantId), 'debit' => $tds];
        }
        $lines[] = ['ledger_id' => $party->id, 'credit' => round($amount + $tds, 2)];

        return $this->post($tenantId, 'receipt', $pay->date, $invoice->client_id, $invoice->number,
            'sales_payment', $pay->id, "Receipt for {$invoice->number}", $lines, [], $pay->created_by);
    }

    /* ── Credit note → Dr Sales / Dr Output GST / Cr Debtor (sales return) ── */
    public function postCreditNote(CreditNote $cn): ?Voucher
    {
        $tenantId = $cn->tenant_id;
        if (! $cn->client_id || (float) $cn->total <= 0) {
            return null;
        }
        if ($this->postedVoucher('credit_note', $cn->id, $tenantId)) {
            return null;
        }

        $party = $this->partyLedger($cn->client_id, $tenantId);
        $total = round((float) $cn->total, 2);
        $tax   = round((float) $cn->tax_total, 2);
        $net   = round($total - $tax, 2);
        $supply = $cn->invoice_id ? optional(SalesInvoice::find($cn->invoice_id))->supply_type : null;

        $lines = [];
        if ($net != 0) {
            $lines[] = ['ledger_id' => $this->role('sales_income', $tenantId), 'debit' => $net];
        }
        $taxLines = [];
        if ($tax > 0) {
            // Reduce output tax: debit output GST ledgers; record negative outward tax lines so GSTR nets down.
            $this->appendGst($lines, $taxLines, $tenantId, $tax, $net, $supply, null, 'outward', 'output', debitSide: true, negativeTax: true);
        }
        $lines[] = ['ledger_id' => $party->id, 'credit' => $total];

        return $this->post($tenantId, 'credit_note', $cn->date, $cn->client_id, $cn->number,
            'credit_note', $cn->id, "Credit note {$cn->number}", $lines, $taxLines, $cn->created_by);
    }

    /* ── Credit-note cash refund → Dr Debtor / Cr Bank ────────────────── */
    public function postRefund(CreditNoteRefund $refund): ?Voucher
    {
        $cn = CreditNote::find($refund->credit_note_id);
        if (! $cn || ! $cn->client_id) {
            return null;
        }
        $tenantId = $cn->tenant_id;
        if ($this->postedVoucher('credit_note_refund', $refund->id, $tenantId)) {
            return null;
        }

        $amount = round((float) $refund->amount, 2);
        if ($amount <= 0) {
            return null;
        }
        $party = $this->partyLedger($cn->client_id, $tenantId);
        $lines = [
            ['ledger_id' => $party->id, 'debit' => $amount],
            ['ledger_id' => $this->bankLedger($refund->mode, $tenantId), 'credit' => $amount],
        ];

        return $this->post($tenantId, 'payment', $refund->date, $cn->client_id, $cn->number,
            'credit_note_refund', $refund->id, "Refund on {$cn->number}", $lines, [], $refund->created_by);
    }

    /* ── Client expense → Dr Expense / Cr Cash-Bank ───────────────────── */
    public function postExpense(ClientExpense $exp): ?Voucher
    {
        $tenantId = $exp->tenant_id;
        $amount = round((float) $exp->amount, 2);
        if ($amount <= 0) {
            return null;
        }
        if ($this->postedVoucher('client_expense', $exp->id, $tenantId)) {
            return null;
        }

        $lines = [
            ['ledger_id' => $this->role('expense_default', $tenantId), 'debit' => $amount],
            ['ledger_id' => $this->bankLedger($exp->payment_mode, $tenantId), 'credit' => $amount],
        ];

        return $this->post($tenantId, 'payment', $exp->date, null, ($exp->name ?: "EXP-{$exp->id}"),
            'client_expense', $exp->id, 'Expense: ' . ($exp->name ?: $exp->category ?: 'general'), $lines, [], $exp->created_by);
    }

    /** Reverse the voucher posted for a source document (on cancel/void/delete). */
    public function reverseForSource(string $sourceType, int $sourceId, int $tenantId, ?int $userId = null): void
    {
        $voucher = $this->postedVoucher($sourceType, $sourceId, $tenantId);
        if ($voucher && ! $voucher->reversedBy()->exists()) {
            $this->reversal->cancel($voucher, $tenantId, $userId, 'Source document cancelled');
        }
    }

    /* ── Internals ────────────────────────────────────────────────────── */

    /** Append GST legs + tax-line metadata (intra → CGST+SGST, inter → IGST). */
    private function appendGst(array &$lines, array &$taxLines, int $tenantId, float $tax, float $net, ?string $supplyType, ?string $placeOfSupply, string $direction, string $prefix, bool $debitSide = false, bool $negativeTax = false): void
    {
        $intra = $supplyType !== 'inter';
        $rate = $net > 0 ? round($tax / $net * 100, 2) : 0;
        $side = $debitSide ? 'debit' : 'credit';
        $sign = $negativeTax ? -1 : 1;

        if ($intra) {
            $cgst = round($tax / 2, 2);
            $sgst = round($tax - $cgst, 2);
            $lines[] = ['ledger_id' => $this->role("{$prefix}_cgst", $tenantId), $side => $cgst];
            $lines[] = ['ledger_id' => $this->role("{$prefix}_sgst", $tenantId), $side => $sgst];
            $taxLines[] = ['tax_type' => 'cgst', 'rate' => round($rate / 2, 2), 'taxable_amount' => $sign * $net, 'tax_amount' => $sign * $cgst, 'place_of_supply_state' => $placeOfSupply, 'direction' => $direction];
            $taxLines[] = ['tax_type' => 'sgst', 'rate' => round($rate / 2, 2), 'taxable_amount' => $sign * $net, 'tax_amount' => $sign * $sgst, 'place_of_supply_state' => $placeOfSupply, 'direction' => $direction];
        } else {
            $lines[] = ['ledger_id' => $this->role("{$prefix}_igst", $tenantId), $side => $tax];
            $taxLines[] = ['tax_type' => 'igst', 'rate' => $rate, 'taxable_amount' => $sign * $net, 'tax_amount' => $sign * $tax, 'place_of_supply_state' => $placeOfSupply, 'direction' => $direction];
        }
    }

    private function post(int $tenantId, string $type, $date, ?int $partyId, ?string $reference, string $sourceType, int $sourceId, string $narration, array $lines, array $taxLines, ?int $userId): Voucher
    {
        return $this->posting->post([
            'voucher_type_code' => $type,
            'date'              => $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : (string) $date,
            'narration'         => $narration,
            'party_id'          => $partyId,
            'reference_no'      => $reference,
            'source_type'       => $sourceType,
            'source_id'         => $sourceId,
            'lines'             => $lines,
            'tax_lines'         => $taxLines,
        ], $tenantId, $userId);
    }

    private function postedVoucher(string $sourceType, int $sourceId, int $tenantId): ?Voucher
    {
        return Voucher::forTenant($tenantId)
            ->where('source_type', $sourceType)->where('source_id', $sourceId)
            ->where('is_reversal', false)->where('status', 'posted')
            ->first();
    }

    private function role(string $roleKey, int $tenantId): int
    {
        $ledgerId = AccountMapping::forTenant($tenantId)->where('role_key', $roleKey)->value('ledger_id');
        if (! $ledgerId) {
            throw new BusinessException("Account mapping '{$roleKey}' is not configured. Run accounts setup.");
        }
        return (int) $ledgerId;
    }

    /** Cash mode → cash ledger; otherwise a real bank ledger if one exists, else the default. */
    private function bankLedger(?string $mode, int $tenantId): int
    {
        if ($mode && stripos($mode, 'cash') !== false) {
            return $this->role('cash', $tenantId);
        }
        $bank = Ledger::forTenant($tenantId)->where('is_bank', true)->where('is_active', true)->orderBy('id')->value('id');
        return $bank ? (int) $bank : $this->role('bank_default', $tenantId);
    }

    /** Find (or create) the client's control ledger under Sundry Debtors. */
    private function partyLedger(int $clientId, int $tenantId): Ledger
    {
        $ledger = Ledger::forTenant($tenantId)->where('is_party', true)->where('party_id', $clientId)->first();
        if ($ledger) {
            return $ledger;
        }

        $group = AccountGroup::forTenant($tenantId)->where('name', 'Sundry Debtors')->first();
        if (! $group) {
            throw new BusinessException('The "Sundry Debtors" group is missing. Run accounts setup.');
        }

        $name = optional(Client::find($clientId))->company ?: "Customer #{$clientId}";
        // Guard against a duplicate ledger name within the tenant.
        if (Ledger::forTenant($tenantId)->where('name', $name)->exists()) {
            $name .= " (#{$clientId})";
        }

        return Ledger::create([
            'tenant_id' => $tenantId, 'group_id' => $group->id, 'name' => $name,
            'is_party' => true, 'party_id' => $clientId, 'opening_balance_type' => 'dr',
        ]);
    }
}
