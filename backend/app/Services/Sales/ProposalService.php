<?php

namespace App\Services\Sales;

use App\Exceptions\UnauthorizedTenantException;
use App\Models\Sales\Proposal;
use App\Models\Sales\SalesLineItem;
use App\Repositories\Sales\ProposalRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProposalService
{
    public function __construct(private ProposalRepository $proposalRepository)
    {
    }

    public function list(int $tenantId, ?string $status, ?string $search)
    {
        return $this->proposalRepository->filtered($tenantId, $status, $search);
    }

    public function create(array $data, array $lineItems, int $tenantId, int $userId): Proposal
    {
        return DB::transaction(function () use ($data, $lineItems, $tenantId, $userId) {
            $proposal = Proposal::create([
                ...$data,
                'tenant_id'  => $tenantId,
                'created_by' => $userId,
                'status'     => $data['status'] ?? 'Draft',
            ]);

            $this->syncLineItems($proposal, $lineItems);
            $proposal->recalcTotals();

            Log::channel('sales')->info('Proposal created', ['proposal_id' => $proposal->id, 'tenant_id' => $tenantId]);

            return $proposal->load('lineItems');
        });
    }

    public function show(Proposal $proposal, int $tenantId): Proposal
    {
        $this->assertTenant($proposal, $tenantId);
        return $proposal->load(['lineItems', 'assignedUser']);
    }

    public function update(Proposal $proposal, array $data, ?array $lineItems, bool $hasLineItems, int $tenantId): Proposal
    {
        $this->assertTenant($proposal, $tenantId);

        return DB::transaction(function () use ($proposal, $data, $lineItems, $hasLineItems, $tenantId) {
            $proposal->update($data);

            if ($hasLineItems) {
                $this->syncLineItems($proposal, $lineItems ?? []);
                $proposal->recalcTotals();
            }

            Log::channel('sales')->info('Proposal updated', ['proposal_id' => $proposal->id, 'tenant_id' => $tenantId]);

            return $proposal->fresh()->load('lineItems');
        });
    }

    public function delete(Proposal $proposal, int $tenantId): void
    {
        $this->assertTenant($proposal, $tenantId);
        $proposal->delete();
        Log::channel('sales')->info('Proposal deleted', ['proposal_id' => $proposal->id, 'tenant_id' => $tenantId]);
    }

    public function send(Proposal $proposal, int $tenantId): Proposal
    {
        $this->assertTenant($proposal, $tenantId);
        $proposal->update(['status' => 'Sent', 'sent_at' => now()]);
        Log::channel('sales')->info('Proposal sent', ['proposal_id' => $proposal->id, 'tenant_id' => $tenantId]);
        return $proposal->fresh();
    }

    public function updateStatus(Proposal $proposal, string $status, int $tenantId): Proposal
    {
        $this->assertTenant($proposal, $tenantId);

        $data = ['status' => $status];
        if ($status === 'Accepted') $data['accepted_at'] = now();
        if ($status === 'Declined') $data['declined_at'] = now();

        $proposal->update($data);
        Log::channel('sales')->info('Proposal status updated', ['proposal_id' => $proposal->id, 'status' => $status, 'tenant_id' => $tenantId]);

        return $proposal->fresh();
    }

    /**
     * Store the public-view URL as the QR payload. The frontend renders
     * the actual QR code image client-side (qrcode.react) from this
     * string — no server-side image generation needed.
     */
    public function generateQR(Proposal $proposal, string $baseUrl, int $tenantId): Proposal
    {
        $this->assertTenant($proposal, $tenantId);

        $proposal->update([
            'qr_code_data' => rtrim($baseUrl, '/') . '/portal/proposals/' . $proposal->portal_token,
        ]);

        Log::channel('sales')->info('Proposal QR generated', ['proposal_id' => $proposal->id, 'tenant_id' => $tenantId]);

        return $proposal->fresh();
    }

    /**
     * Public, unauthenticated lookup by portal_token — used by the
     * client-facing proposal view and by trackEmailOpen(). OTP
     * verification (public_view_otp_enabled) is stored but not enforced
     * here: there is no OTP delivery mechanism in this codebase yet
     * (Master Plan V2 explicitly defers "Proposal email OTP
     * verification" to a future phase), so enforcing it now would just
     * lock every OTP-enabled proposal out with no way to complete the
     * flow.
     */
    public function findByPortalToken(string $token): Proposal
    {
        $proposal = Proposal::where('portal_token', $token)->first();

        if (! $proposal) {
            throw new \App\Exceptions\ResourceNotFoundException('Proposal');
        }

        return $proposal->load('lineItems');
    }

    public function trackEmailOpen(string $token, ?string $device): void
    {
        $proposal = Proposal::where('portal_token', $token)->first();
        if (! $proposal) return;

        $proposal->increment('email_opened_count');
        $proposal->update([
            'email_opened_at'     => $proposal->email_opened_at ?? now(),
            'email_opened_device' => $device ?? $proposal->email_opened_device,
        ]);

        Log::channel('sales')->info('Proposal email opened', [
            'proposal_id' => $proposal->id, 'tenant_id' => $proposal->tenant_id, 'count' => $proposal->email_opened_count,
        ]);
    }

    private function assertTenant(Proposal $proposal, int $tenantId): void
    {
        if ($proposal->tenant_id !== $tenantId) {
            Log::channel('sales')->warning('Unauthorized proposal access attempt', ['proposal_id' => $proposal->id, 'tenant_id' => $tenantId]);
            throw new UnauthorizedTenantException('Unauthorized');
        }
    }

    private function syncLineItems(Proposal $proposal, array $items): void
    {
        SalesLineItem::where('lineable_type', Proposal::class)
                     ->where('lineable_id', $proposal->id)
                     ->delete();

        foreach ($items as $idx => $item) {
            $total = SalesLineItem::computeTotal($item);
            SalesLineItem::create([
                'lineable_type' => Proposal::class,
                'lineable_id'   => $proposal->id,
                'item_id'       => $item['item_id'] ?? null,
                'item_name'     => $item['item_name'],
                'description'   => $item['description'] ?? null,
                'qty'           => $item['qty'],
                'unit'          => $item['unit'] ?? 'pcs',
                'rate'          => $item['rate'],
                'tax'           => $item['tax'] ?? 0,
                'discount'      => $item['discount'] ?? 0,
                'total'         => $total,
                'sort_order'    => $idx,
            ]);
        }
    }
}
