<?php

namespace App\Services\Customer;

use App\Exceptions\UnauthorizedTenantException;
use App\Models\Customer\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only financial rollups and cross-module loop-ins for a customer profile
 * (tax, financials, statement, and the invoices/estimates/proposals/credit-notes/
 * payments/tickets tabs). Kept separate from ClientService so every cross-module
 * raw query lives in one place. All methods assert tenant ownership first.
 */
class ClientLedgerService
{
    private const TAB_LIMIT = 200;

    /* ── Tax tab: GST / TDS rollup ────────────────────────────── */
    public function taxSummary(Client $client, int $tenantId): array
    {
        $this->assertTenant($client, $tenantId);

        $invoices = $this->invoiceScope($tenantId, $client->id);

        $gstTotal    = (clone $invoices)->sum('gst_amount');
        $gstPaid     = (clone $invoices)->where('gst_paid', true)->sum('gst_amount');
        $invoiceRows = (clone $invoices)
            ->select('id', 'number', 'date', 'total', 'gst_amount', 'gst_paid', 'status')
            ->orderByDesc('date')->limit(self::TAB_LIMIT)->get();

        $tdsTotal = DB::table('sales_payments')
            ->join('sales_invoices', 'sales_payments.invoice_id', '=', 'sales_invoices.id')
            ->where('sales_invoices.tenant_id', $tenantId)
            ->where('sales_invoices.client_id', $client->id)
            ->sum('sales_payments.tds_amount');

        return [
            'gst_total'     => (float) $gstTotal,
            'gst_paid'      => (float) $gstPaid,
            'gst_unpaid'    => (float) $gstTotal - (float) $gstPaid,
            'tds_deducted'  => (float) $tdsTotal,
            'invoice_count' => $invoiceRows->count(),
            'invoices'      => $invoiceRows,
        ];
    }

    /* ── Financial summary (KPI block) ────────────────────────── */
    public function financials(Client $client, int $tenantId): array
    {
        $this->assertTenant($client, $tenantId);

        $invoices = $this->invoiceScope($tenantId, $client->id);

        $credit = 0.0;
        if (Schema::hasTable('credit_notes')) {
            $credit = (float) DB::table('credit_notes')
                ->where('tenant_id', $tenantId)->where('client_id', $client->id)->whereNull('deleted_at')
                ->sum('remaining');
        }

        return [
            'total_billed'     => (float) (clone $invoices)->sum('total'),
            'total_paid'       => (float) (clone $invoices)->sum('paid'),
            'outstanding'      => (float) (clone $invoices)->sum('balance'),
            'available_credit' => $credit,
        ];
    }

    /**
     * Support tickets that loop in from the Helpdesk module via
     * tickets.customer_id (documented integration point, no FK). Guarded so it
     * degrades gracefully if the helpdesk tables are absent.
     */
    public function relatedTickets(Client $client, int $tenantId): array
    {
        $this->assertTenant($client, $tenantId);

        if (! Schema::hasTable('tickets')) {
            return [];
        }

        return DB::table('tickets')
            ->where('tenant_id', $tenantId)->where('customer_id', $client->id)->whereNull('deleted_at')
            ->select('id', 'subject', 'status', 'priority', 'created_at')
            ->orderByDesc('created_at')->limit(self::TAB_LIMIT)->get()->all();
    }

    /* ── Sales-document loop-ins (read-only, by client_id) ─────── */
    public function invoices(Client $client, int $tenantId, ?int $limit = self::TAB_LIMIT): array
    {
        $this->assertTenant($client, $tenantId);

        return $this->invoiceScope($tenantId, $client->id)
            ->select('id', 'number', 'date', 'due_date', 'total', 'paid', 'balance', 'status')
            ->orderByDesc('date')->when($limit, fn ($q) => $q->limit($limit))->get()->all();
    }

    public function estimates(Client $client, int $tenantId): array
    {
        $this->assertTenant($client, $tenantId);

        return DB::table('estimates')
            ->where('tenant_id', $tenantId)->where('client_id', $client->id)->whereNull('deleted_at')
            ->select('id', 'reference', 'subject', 'date', 'valid_until', 'total', 'status')
            ->orderByDesc('date')->limit(self::TAB_LIMIT)->get()->all();
    }

    public function proposals(Client $client, int $tenantId): array
    {
        $this->assertTenant($client, $tenantId);

        return DB::table('proposals')
            ->where('tenant_id', $tenantId)->where('rel_type', 'customer')->where('rel_id', $client->id)
            ->whereNull('deleted_at')
            ->select('id', 'subject', 'total', 'status', 'created_at')
            ->orderByDesc('created_at')->limit(self::TAB_LIMIT)->get()->all();
    }

    public function creditNotes(Client $client, int $tenantId, ?int $limit = self::TAB_LIMIT): array
    {
        $this->assertTenant($client, $tenantId);

        return DB::table('credit_notes')
            ->where('tenant_id', $tenantId)->where('client_id', $client->id)->whereNull('deleted_at')
            ->select('id', 'number', 'date', 'total', 'remaining', 'status')
            ->orderByDesc('date')->when($limit, fn ($q) => $q->limit($limit))->get()->all();
    }

    public function payments(Client $client, int $tenantId, ?int $limit = self::TAB_LIMIT): array
    {
        $this->assertTenant($client, $tenantId);

        return DB::table('sales_payments')
            ->join('sales_invoices', 'sales_payments.invoice_id', '=', 'sales_invoices.id')
            ->where('sales_invoices.tenant_id', $tenantId)
            ->where('sales_invoices.client_id', $client->id)
            ->select(
                'sales_payments.id', 'sales_payments.date', 'sales_payments.amount',
                'sales_payments.mode', 'sales_payments.tds_amount', 'sales_invoices.number as invoice_number',
            )
            ->orderByDesc('sales_payments.date')->when($limit, fn ($q) => $q->limit($limit))->get()->all();
    }

    /**
     * Chronological account statement: opening balance, then invoices as debits
     * and payments + credit notes as credits, with a running balance. Pulls the
     * full history (no per-type cap) so the running balance is accurate.
     */
    public function statement(Client $client, int $tenantId): array
    {
        $this->assertTenant($client, $tenantId);

        $lines = [];
        foreach ($this->invoices($client, $tenantId, null) as $inv) {
            $lines[] = ['date' => $inv->date, 'type' => 'Invoice', 'ref' => $inv->number, 'debit' => (float) $inv->total, 'credit' => 0.0];
        }
        foreach ($this->payments($client, $tenantId, null) as $pay) {
            $lines[] = ['date' => (string) $pay->date, 'type' => 'Payment', 'ref' => $pay->invoice_number, 'debit' => 0.0, 'credit' => (float) $pay->amount];
        }
        foreach ($this->creditNotes($client, $tenantId, null) as $cn) {
            $lines[] = ['date' => $cn->date, 'type' => 'Credit Note', 'ref' => $cn->number, 'debit' => 0.0, 'credit' => (float) $cn->total];
        }

        usort($lines, fn ($a, $b) => strcmp((string) $a['date'], (string) $b['date']));

        $opening = (float) $client->opening_balance;
        $running = $opening;
        foreach ($lines as &$line) {
            $running += $line['debit'] - $line['credit'];
            $line['balance'] = round($running, 2);
        }

        return [
            'opening_balance'      => $opening,
            'opening_balance_date' => optional($client->opening_balance_date)->toDateString(),
            'lines'                => $lines,
            'closing_balance'      => round($running, 2),
        ];
    }

    /* ── Helpers ──────────────────────────────────────────────── */
    private function invoiceScope(int $tenantId, int $clientId)
    {
        return DB::table('sales_invoices')
            ->where('tenant_id', $tenantId)->where('client_id', $clientId)->whereNull('deleted_at');
    }

    private function assertTenant(Client $client, int $tenantId): void
    {
        if ($client->tenant_id !== $tenantId) {
            throw new UnauthorizedTenantException('Unauthorized');
        }
    }
}
