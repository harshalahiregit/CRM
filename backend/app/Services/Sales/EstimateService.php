<?php

namespace App\Services\Sales;

use App\Exceptions\UnauthorizedTenantException;
use App\Models\Estimate;
use App\Models\SalesInvoice;
use App\Models\SalesLineItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EstimateService
{
    public function list(int $tenantId, ?string $status)
    {
        $query = Estimate::forTenant($tenantId)->with('lineItems');

        if ($status && $status !== 'All') {
            $query->where('status', $status);
        }

        return $query->latest()->get();
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
            $estimate->update(['status' => 'Accepted']);

            Log::channel('sales')->info('Estimate converted to invoice', [
                'estimate_id' => $estimate->id, 'invoice_id' => $invoice->id, 'tenant_id' => $tenantId,
            ]);

            return $invoice;
        });
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
