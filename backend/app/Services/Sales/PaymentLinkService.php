<?php

namespace App\Services\Sales;

use App\Exceptions\BusinessException;
use App\Exceptions\UnauthorizedTenantException;
use App\Models\Sales\PaymentLink;
use Illuminate\Support\Facades\Log;

class PaymentLinkService
{
    public function list(int $tenantId, array $filters)
    {
        $query = PaymentLink::where('tenant_id', $tenantId)->with('invoice:id,number');

        if (! empty($filters['status']) && $filters['status'] !== 'All') {
            $query->where('status', $filters['status']);
        }

        $links = $query->latest()->get();

        $links->each(function (PaymentLink $link) {
            if ($link->status === 'active' && $link->isExpired()) {
                $link->update(['status' => 'expired']);
            }
        });

        return $links;
    }

    public function create(array $data, int $tenantId, int $userId): PaymentLink
    {
        $link = PaymentLink::create([
            ...$data,
            'tenant_id'  => $tenantId,
            'created_by' => $userId,
            'status'     => 'active',
        ]);

        Log::channel('sales')->info('Payment link created', ['link_id' => $link->id, 'tenant_id' => $tenantId]);

        return $link->load('invoice:id,number');
    }

    public function markPaid(PaymentLink $link, array $data, int $tenantId): PaymentLink
    {
        $this->assertTenant($link, $tenantId);

        if ($link->status !== 'active') {
            throw new BusinessException('Only an active payment link can be marked paid.', 422);
        }

        $link->update([
            'status'         => 'paid',
            'transaction_id' => $data['transaction_id'] ?? null,
            'paid_at'        => now(),
        ]);

        Log::channel('sales')->info('Payment link marked paid', ['link_id' => $link->id, 'tenant_id' => $tenantId]);

        return $link->fresh();
    }

    public function cancel(PaymentLink $link, int $tenantId): PaymentLink
    {
        $this->assertTenant($link, $tenantId);
        $link->update(['status' => 'cancelled']);
        Log::channel('sales')->info('Payment link cancelled', ['link_id' => $link->id, 'tenant_id' => $tenantId]);

        return $link->fresh();
    }

    public function delete(PaymentLink $link, int $tenantId): void
    {
        $this->assertTenant($link, $tenantId);
        $link->delete();
        Log::channel('sales')->info('Payment link deleted', ['link_id' => $link->id, 'tenant_id' => $tenantId]);
    }

    private function assertTenant(PaymentLink $link, int $tenantId): void
    {
        if ($link->tenant_id !== $tenantId) {
            throw new UnauthorizedTenantException();
        }
    }
}
