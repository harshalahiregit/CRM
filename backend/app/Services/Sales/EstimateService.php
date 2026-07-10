<?php

namespace App\Services\Sales;

use App\Exceptions\BusinessException;
use App\Exceptions\UnauthorizedTenantException;
use App\Models\Sales\Estimate;
use App\Models\Sales\SalesInvoice;
use App\Models\Sales\SalesLineItem;
use App\Models\Sales\SalesPayment;
use App\Repositories\Sales\EstimateRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EstimateService
{
    public function __construct(private EstimateRepository $estimateRepository)
    {
    }

    public function list(int $tenantId, ?string $status)
    {
        return $this->estimateRepository->filtered($tenantId, $status);
    }

    public function create(array $data, array $lineItems, int $tenantId, int $userId): Estimate
    {
        return DB::transaction(function () use ($data, $lineItems, $tenantId, $userId) {
            $estimate = Estimate::create([
                ...$data,
                'tenant_id'  => $tenantId,
                'created_by' => $userId,
                'status'     => $data['status'] ?? 'Draft',
            ]);

            $this->syncLineItems($estimate, $lineItems);
            $estimate->recalcTotals();

            Log::channel('sales')->info('Estimate created', ['estimate_id' => $estimate->id, 'tenant_id' => $tenantId]);

            return $estimate->load('lineItems');
        });
    }

    public function show(Estimate $estimate, int $tenantId): Estimate
    {
        $this->assertTenant($estimate, $tenantId);
        return $estimate->load(['lineItems', 'agent']);
    }

    public function update(Estimate $estimate, array $data, ?array $lineItems, bool $hasLineItems, int $tenantId): Estimate
    {
        $this->assertTenant($estimate, $tenantId);

        return DB::transaction(function () use ($estimate, $data, $lineItems, $hasLineItems, $tenantId) {
            $estimate->update($data);

            if ($hasLineItems) {
                $this->syncLineItems($estimate, $lineItems ?? []);
                $estimate->recalcTotals();
            }

            Log::channel('sales')->info('Estimate updated', ['estimate_id' => $estimate->id, 'tenant_id' => $tenantId]);

            return $estimate->fresh()->load('lineItems');
        });
    }

    public function delete(Estimate $estimate, int $tenantId): void
    {
        $this->assertTenant($estimate, $tenantId);
        $estimate->delete();
        Log::channel('sales')->info('Estimate deleted', ['estimate_id' => $estimate->id, 'tenant_id' => $tenantId]);
    }

    public function send(Estimate $estimate, int $tenantId): Estimate
    {
        $this->assertTenant($estimate, $tenantId);
        $estimate->update(['status' => 'Sent', 'sent_at' => now()]);
        Log::channel('sales')->info('Estimate sent', ['estimate_id' => $estimate->id, 'tenant_id' => $tenantId]);
        return $estimate->fresh();
    }

    public function convertToInvoice(Estimate $estimate, ?string $dueDate, int $tenantId, int $userId): SalesInvoice
    {
        $this->assertTenant($estimate, $tenantId);

        return DB::transaction(function () use ($estimate, $dueDate, $tenantId, $userId) {
            $invoice = SalesInvoice::create([
                'tenant_id'     => $tenantId,
                'client_id'     => $estimate->client_id,
                'project_id'    => $estimate->project_id,
                'estimate_id'   => $estimate->id,
                'date'          => now()->toDateString(),
                'due_date'      => $dueDate ?? now()->addDays(30)->toDateString(),
                'currency'      => $estimate->currency,
                'sale_agent'    => $estimate->sale_agent,
                'discount_type' => $estimate->discount_type,
                'status'        => 'Draft',
                'terms'         => $estimate->terms,
                'adminnote'     => $estimate->adminnote,
                'clientnote'    => $estimate->clientnote,
                'created_by'    => $userId,
            ]);

            foreach ($estimate->lineItems as $idx => $li) {
                SalesLineItem::create([
                    'lineable_type' => SalesInvoice::class,
                    'lineable_id'   => $invoice->id,
                    'item_id'       => $li->item_id,
                    'item_name'     => $li->item_name,
                    'description'   => $li->description,
                    'qty'           => $li->qty,
                    'unit'          => $li->unit,
                    'rate'          => $li->rate,
                    'tax'           => $li->tax,
                    'discount'      => $li->discount,
                    'total'         => $li->total,
                    'sort_order'    => $idx,
                ]);
            }

            $invoice->recalcTotals();

            // Carry over any payment already recorded against the proforma
            // invoice (Master Plan V2 §B4: "Convert to Tax Invoice action
            // with payment data carried over").
            if ($estimate->payment_received && $estimate->payment_amount > 0) {
                SalesPayment::create([
                    'tenant_id'      => $tenantId,
                    'invoice_id'     => $invoice->id,
                    'date'           => $estimate->payment_date ?? now()->toDateString(),
                    'amount'         => min($estimate->payment_amount, $invoice->total),
                    'mode'           => 'Carried over from Proforma Invoice',
                    'note'           => "Payment carried over from {$estimate->reference}",
                    'created_by'     => $userId,
                ]);
                $invoice->recalcBalance();
            }

            $estimate->update([
                'status' => 'Accepted',
                'converted_invoice_id' => $invoice->id,
            ]);

            Log::channel('sales')->info('Estimate converted to invoice', [
                'estimate_id' => $estimate->id, 'invoice_id' => $invoice->id, 'tenant_id' => $tenantId,
            ]);

            return $invoice->fresh();
        });
    }

    /**
     * Record a payment against a proforma invoice (estimate) directly —
     * distinct from SalesPayment, which tracks payments against a Tax
     * Invoice. Master Plan V2 §B4: "Add payment recording against
     * proforma invoice" + "PAID badge".
     */
    public function recordPayment(Estimate $estimate, array $data, int $tenantId): Estimate
    {
        $this->assertTenant($estimate, $tenantId);

        if ((float) $data['amount'] > (float) $estimate->total) {
            throw new BusinessException('Payment amount exceeds the proforma invoice total.', 422);
        }

        $estimate->update([
            'payment_received' => true,
            'payment_amount'   => $data['amount'],
            'payment_date'     => $data['date'] ?? now()->toDateString(),
        ]);

        Log::channel('sales')->info('Proforma invoice payment recorded', [
            'estimate_id' => $estimate->id, 'tenant_id' => $tenantId, 'amount' => $data['amount'],
        ]);

        return $estimate->fresh();
    }

    private function assertTenant(Estimate $estimate, int $tenantId): void
    {
        if ($estimate->tenant_id !== $tenantId) {
            Log::channel('sales')->warning('Unauthorized estimate access attempt', ['estimate_id' => $estimate->id, 'tenant_id' => $tenantId]);
            throw new UnauthorizedTenantException();
        }
    }

    private function syncLineItems(Estimate $estimate, array $items): void
    {
        SalesLineItem::where('lineable_type', Estimate::class)
                     ->where('lineable_id', $estimate->id)
                     ->delete();

        foreach ($items as $idx => $item) {
            SalesLineItem::create([
                'lineable_type' => Estimate::class,
                'lineable_id'   => $estimate->id,
                'item_id'       => $item['item_id'] ?? null,
                'item_name'     => $item['item_name'],
                'description'   => $item['description'] ?? null,
                'qty'           => $item['qty'],
                'unit'          => $item['unit'] ?? 'pcs',
                'rate'          => $item['rate'],
                'tax'           => $item['tax'] ?? 0,
                'discount'      => $item['discount'] ?? 0,
                'total'         => SalesLineItem::computeTotal($item),
                'sort_order'    => $idx,
            ]);
        }
    }
}
