<?php

namespace App\Services\Inventory;

use App\Exceptions\BusinessException;
use App\Models\Inventory\Voucher;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The approval gate on stock documents.
 *
 * Settings > Approval has offered these switches since the module shipped, and
 * nothing read them. A manager could turn on "delivery voucher needs approval",
 * believe stock was gated, and watch it post anyway. A setting that lies is
 * worse than a setting that is missing, because people act on it.
 *
 * The rule this enforces:
 *   • a document needs approval when its type is switched on AND its value is
 *     at or above the threshold (threshold 0 = every document of that type);
 *   • the person who raised it can never approve it — that is the entire point
 *     of a second pair of eyes;
 *   • rejection returns it to draft with a reason, so the requester knows what
 *     to fix rather than just finding it refused.
 *
 * Where no rule applies, a draft posts directly exactly as it always did.
 */
class ApprovalService
{
    /** type => the settings key that gates it. */
    private const RULES = [
        'receipt'         => 'require_approval_receipt',
        'delivery'        => 'require_approval_delivery',
        'loss_adjustment' => 'require_approval_adjustment',
        // An internal transfer moves stock between warehouses without changing
        // what the company owns, so it rides on the delivery rule rather than
        // getting a switch of its own that nobody would think to look for.
        'internal'        => 'require_approval_delivery',
    ];

    public function __construct(
        private ConfigService $config,
        private InventoryNotifier $notifier,
    ) {
    }

    /** Does this document have to be approved before it can post? */
    public function isRequired(Voucher $voucher): bool
    {
        $key = self::RULES[$voucher->type] ?? null;
        if (! $key) {
            return false;
        }

        $settings = $this->config->all($voucher->tenant_id);
        if (! ($settings[$key] ?? false)) {
            return false;
        }

        // A threshold of 0 means "every one of them"; above that, only documents
        // worth at least the threshold are worth interrupting someone for.
        $threshold = (float) ($settings['approval_threshold_amount'] ?? 0);

        return $threshold <= 0 || (float) $voucher->total_amount >= $threshold;
    }

    /**
     * Send a draft for approval. Returns the fresh voucher.
     */
    public function submit(int $voucherId, int $tenantId, int $userId): Voucher
    {
        $voucher = $this->find($voucherId, $tenantId);

        if ($voucher->status !== 'draft') {
            throw new BusinessException('Only a draft can be sent for approval.', 422);
        }
        if (! $this->isRequired($voucher)) {
            throw new BusinessException('This document does not need approval — post it directly.', 422);
        }
        if ($voucher->items()->count() === 0) {
            throw new BusinessException('Add at least one line before sending this for approval.', 422);
        }

        $voucher->forceFill([
            'status'           => 'pending_approval',
            'submitted_at'     => now(),
            'rejection_reason' => null,
            'rejected_by'      => null,
            'rejected_at'      => null,
        ])->save();

        $this->notifier->approvalRequested($voucher, $userId);

        return $voucher->fresh();
    }

    /** Approve a pending document. It still has to be posted afterwards. */
    public function approve(int $voucherId, int $tenantId, int $userId): Voucher
    {
        $voucher = $this->find($voucherId, $tenantId);
        $this->assertPending($voucher);
        $this->assertNotOwnWork($voucher, $userId);

        $voucher->forceFill([
            'status'      => 'approved',
            'approved_by' => $userId,
            'approved_at' => now(),
        ])->save();

        $this->notifier->approvalDecided($voucher, $userId, true, null);

        return $voucher->fresh();
    }

    /** Send it back with a reason. Back to draft, so it can be fixed and resent. */
    public function reject(int $voucherId, int $tenantId, int $userId, string $reason): Voucher
    {
        $voucher = $this->find($voucherId, $tenantId);
        $this->assertPending($voucher);
        $this->assertNotOwnWork($voucher, $userId);

        $reason = trim($reason);
        if ($reason === '') {
            throw new BusinessException('Say why you are rejecting it — "no" on its own gives the requester nothing to fix.', 422);
        }

        $voucher->forceFill([
            'status'           => 'draft',
            'rejected_by'      => $userId,
            'rejected_at'      => now(),
            'rejection_reason' => mb_substr($reason, 0, 1000),
            'submitted_at'     => null,
        ])->save();

        $this->notifier->approvalDecided($voucher, $userId, false, $reason);

        return $voucher->fresh();
    }

    /**
     * Called by VoucherService::post before anything moves. Throws when the
     * document should have been through the gate and hasn't.
     */
    public function assertPostable(Voucher $voucher): void
    {
        if ($voucher->status === 'pending_approval') {
            throw new BusinessException('This document is waiting for approval — it cannot be posted yet.', 422);
        }

        if ($voucher->status === 'approved') {
            return;   // already been through the gate
        }

        if ($this->isRequired($voucher)) {
            throw new BusinessException(
                'This document needs approval before it can move stock. Send it for approval first.',
                422
            );
        }
    }

    /** Documents waiting on this user's decision. */
    public function pendingFor(int $tenantId, int $userId, bool $isAdmin, array $managedWarehouseIds = []): array
    {
        $q = Voucher::forTenant($tenantId)
            ->where('status', 'pending_approval')
            ->with('creator:id,name')
            ->orderBy('submitted_at');

        // An approver sees what they could actually decide on: admins see all,
        // a warehouse manager sees their own warehouses. Either way, never your
        // own document — you cannot approve it, so listing it is just noise.
        if (! $isAdmin) {
            $q->whereIn('warehouse_id', $managedWarehouseIds ?: [0]);
        }
        $q->where('created_by', '!=', $userId);

        return $q->get()->all();
    }

    /* ── Guards ─────────────────────────────────────────────────── */

    private function assertPending(Voucher $voucher): void
    {
        if ($voucher->status !== 'pending_approval') {
            throw new BusinessException('This document is not waiting for approval.', 422);
        }
    }

    /**
     * The whole value of an approval is that someone else looked at it. Letting
     * the raiser approve their own document turns the gate into a checkbox.
     */
    private function assertNotOwnWork(Voucher $voucher, int $userId): void
    {
        if ((int) $voucher->created_by === $userId) {
            throw new BusinessException(
                'You raised this document, so you cannot approve it. Ask an admin or the warehouse manager.',
                403
            );
        }
    }

    private function find(int $id, int $tenantId): Voucher
    {
        return Voucher::forTenant($tenantId)->find($id)
            ?? throw new BusinessException('That document does not exist.', 404);
    }
}
