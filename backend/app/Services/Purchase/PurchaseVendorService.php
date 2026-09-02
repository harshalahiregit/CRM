<?php

namespace App\Services\Purchase;

use App\Exceptions\BusinessException;
use App\Models\Purchase\PurchaseVendor;
use App\Models\User;
use App\Repositories\Purchase\PurchaseVendorRepository;
use App\Support\Purchase\PurchaseRegistrationType as RegistrationType;
use App\Support\Purchase\PurchaseVendorStatus as Status;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The Purchase Vendor master engine — CRUD, search, statistics, activation and
 * status transitions for the Purchase module's OWN vendor entity
 * (purchase_vendors). Completely independent of the shared VendorService / TPV.
 * Reuses only generic infra (BaseRepository, Auditable trait, logging).
 */
class PurchaseVendorService
{
    public function __construct(
        private PurchaseVendorRepository $repo,
        private PurchaseVendorPortalAuthService $portalAuth,
        private PurchaseActivationNotifier $notifier,
        private PurchaseSettingService $settings,
    ) {
    }

    public function list(int $tenantId, array $filters): Collection
    {
        return $this->repo->filtered($tenantId, $filters);
    }

    public function stats(int $tenantId): array
    {
        return $this->repo->stats($tenantId);
    }

    public function find(int $id, int $tenantId): PurchaseVendor
    {
        $vendor = $this->repo->findForTenant($id, $tenantId);
        if (! $vendor) {
            throw new BusinessException('Purchase vendor not found.', 404);
        }

        return $vendor;
    }

    public function create(array $data, User $actor): PurchaseVendor
    {
        $tenantId = $actor->tenant_id;

        $vendor = PurchaseVendor::create([
            ...$data,
            'tenant_id'            => $tenantId,
            'purchase_vendor_code' => $data['purchase_vendor_code'] ?? $this->generateCode($tenantId),
            // An admin-created vendor records its type explicitly too — falling
            // back to the vendor_type picked on the form, then to Standard.
            'registration_type'    => RegistrationType::normalize(
                $data['registration_type'] ?? $data['vendor_type'] ?? null
            ),
            'status'               => $data['status'] ?? Status::DRAFT,
        ]);

        $vendor->recordAudit('Purchase Vendor Created', $actor, null, ['company_name' => $vendor->company_name]);
        Log::channel('purchase')->info('Purchase vendor created', [
            'purchase_vendor_id' => $vendor->id, 'tenant_id' => $tenantId,
        ]);

        // Welcome the vendor immediately with login credentials so they can sign
        // in and complete onboarding — not only at activation (mirrors TPV).
        if (! empty($vendor->email) && ! $vendor->password) {
            $plain = $this->portalAuth->provision($vendor->fresh(), $actor);
            if ($plain) {
                $this->notifier->onCredentialsIssued($vendor->fresh(), $plain);
            }
        }

        return $vendor->fresh();
    }

    public function update(PurchaseVendor $vendor, array $data, User $actor): PurchaseVendor
    {
        // The code is immutable once assigned; status changes go through updateStatus/approve.
        unset($data['purchase_vendor_code'], $data['status']);

        $vendor->update($data);
        $vendor->recordAudit('Purchase Vendor Updated', $actor, null, ['company_name' => $vendor->company_name]);

        return $vendor->fresh();
    }

    /** Activate a vendor for procurement (Draft/Pending → Active). */
    public function approve(PurchaseVendor $vendor, User $actor): PurchaseVendor
    {
        if ($vendor->status === Status::ACTIVE) {
            throw new BusinessException('This vendor is already active.');
        }

        $vendor->update([
            'status'      => Status::ACTIVE,
            'approved_at' => now(),
            'approved_by' => $actor->id,
        ]);
        $vendor->recordAudit('Purchase Vendor Activated', $actor, null, ['to' => Status::ACTIVE]);
        Log::channel('purchase')->info('Purchase vendor activated', [
            'purchase_vendor_id' => $vendor->id, 'actor_id' => $actor->id,
        ]);

        // The FULL activation — access window, portal login and the once-only
        // activation e-mail. Shared with updateStatus() so a direct status change
        // to Active and an onboarding approval both activate for real.
        $this->runActivation($vendor->fresh(), $actor);

        return $vendor->fresh();
    }

    /**
     * The full activation side-effects, applied whenever a vendor first becomes
     * Active — from approve(), from updateStatus(), or (via updateStatus) from
     * PurchaseOnboardingService::approve. Mirrors VendorService's TPV activation:
     *
     *   • open a temporary vendor's access window (no-op for standard vendors and
     *     for a window that is already open),
     *   • stamp approved_at if it was never set (a direct status change has none),
     *   • provision (or re-activate) the portal login — returns a one-time
     *     password only when the system had to generate one,
     *   • fire the logged, once-only activation e-mail.
     *
     * Without this the account would be Active in name only: the portal login
     * unprovisioned and no real activation mail — the exact gap this pass closes.
     */
    private function runActivation(PurchaseVendor $vendor, User $actor): void
    {
        $patch = $this->openAccessWindow($vendor, now());
        if ($vendor->approved_at === null) {
            $patch['approved_at'] = now();
        }
        if ($patch !== []) {
            $vendor->update($patch);
        }

        $tempPassword = $this->portalAuth->provision($vendor, $actor);
        $this->notifier->onActivated($vendor->fresh(), $tempPassword);
    }

    /**
     * The access-window patch applied when a vendor is activated.
     *
     * Purchase-owned and the single place this module computes an expiry:
     * period comes from Purchase Settings (temporary_vendor_validity_days),
     * counted from the activation moment — never from created_at. Returns an
     * empty patch for Standard vendors (they never expire) and for a window
     * that is already open (re-activation must not extend access).
     */
    private function openAccessWindow(PurchaseVendor $vendor, \Carbon\CarbonInterface $activatedAt): array
    {
        if (! $vendor->isTemporary() || $vendor->access_expires_at !== null) {
            return [];
        }

        $days = (int) $this->settings->get($vendor->tenant_id, 'temporary_vendor_validity_days');
        $days = $days > 0 ? $days : 5;

        return ['access_expires_at' => $activatedAt->copy()->addDays($days)];
    }

    /** Admin-triggered resend of the activation e-mail (Active vendors only). */
    public function resendActivationEmail(PurchaseVendor $vendor, User $actor): array
    {
        if ($vendor->status !== Status::ACTIVE) {
            throw new BusinessException('Only an active vendor can be sent the activation e-mail.');
        }

        $log = $this->notifier->resend($vendor);
        $vendor->recordAudit('Activation Email Resent', $actor, null, ['status' => $log->status]);

        return ['status' => $log->status, 'sent_at' => $log->sent_at, 'recipient' => $log->recipient];
    }

    /**
     * Chronological notification history for the Vendor Detail timeline.
     * Reuses purchase_notification_logs — no second store.
     */
    public function notificationTimeline(PurchaseVendor $vendor, int $limit = 50): array
    {
        return \App\Models\Purchase\PurchaseNotificationLog::forTenant($vendor->tenant_id)
            ->where('vendor_id', $vendor->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'type', 'channel', 'subject', 'recipient', 'status', 'sent_at', 'response', 'created_at'])
            ->all();
    }

    /** Login stats for the Vendor Detail dashboard (Purchase portal only). */
    public function loginStats(PurchaseVendor $vendor): array
    {
        return [
            'first_login_at' => $vendor->first_login_at,
            'last_login_at'  => $vendor->last_login_at,
            'login_count'    => (int) ($vendor->login_count ?? 0),
        ];
    }

    /** Last notification for the Vendor Detail dashboard. */
    public function lastNotification(PurchaseVendor $vendor): ?array
    {
        $log = $this->notifier->latestFor($vendor);

        return $log ? [
            'type' => $log->type, 'channel' => $log->channel, 'status' => $log->status,
            'sent_at' => $log->sent_at, 'recipient' => $log->recipient, 'subject' => $log->subject,
        ] : null;
    }

    public function updateStatus(PurchaseVendor $vendor, string $status, User $actor, ?string $remarks = null): PurchaseVendor
    {
        if (! Status::isValid($status)) {
            throw new BusinessException("Invalid vendor status: {$status}");
        }

        $from = $vendor->status;

        // Rule 1 — "No Approval, No Activation" (parity with VendorService). Block a
        // raw status flip to Active unless the onboarding has been approved; the
        // sanctioned path (PurchaseOnboardingService::approve) sets it Approved first.
        if ($status === Status::ACTIVE && $from !== Status::ACTIVE) {
            $obStatus = $vendor->onboarding()->value('status');
            if ($obStatus !== \App\Support\Purchase\PurchaseOnboardingStatus::APPROVED) {
                throw new BusinessException('Purchase vendor cannot be activated until its onboarding is approved — "No Approval, No Activation".');
            }
        }

        $vendor->update(['status' => $status, 'notes' => $remarks ?? $vendor->notes]);
        $vendor->recordAudit('Purchase Vendor Status Changed', $actor, $remarks, ['from' => $from, 'to' => $status]);

        // A first-time activation runs the FULL activation (portal login + access
        // window + once-only activation e-mail), not just a status flag — the same
        // contract as VendorService::updateStatus on the TPV side. This is what
        // PurchaseOnboardingService::approve routes through, so an approved vendor
        // is genuinely activated rather than merely marked Active.
        if ($status === Status::ACTIVE && $from !== Status::ACTIVE) {
            $this->runActivation($vendor->fresh(), $actor);
        }

        return $vendor->fresh();
    }

    public function delete(PurchaseVendor $vendor, User $actor): void
    {
        $vendor->recordAudit('Purchase Vendor Deleted', $actor, null, ['company_name' => $vendor->company_name]);
        $vendor->delete();
    }

    /**
     * The single source of Purchase Vendor codes (PV-####).
     *
     * Public so every creation path — admin create, portal self-registration and
     * the /auth/register/vendor flow — mints codes the same way instead of each
     * re-implementing the format. Skips codes already taken (soft-deleted rows
     * included), so a deleted or concurrently-created row can't cause a clash.
     */
    public function nextVendorCode(int $tenantId): string
    {
        $seq = PurchaseVendor::withTrashed()->where('tenant_id', $tenantId)->count() + 1;

        do {
            $code = 'PV-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
            $taken = PurchaseVendor::withTrashed()
                ->where('tenant_id', $tenantId)
                ->where('purchase_vendor_code', $code)
                ->exists();
            $seq++;
        } while ($taken);

        return $code;
    }

    private function generateCode(int $tenantId): string
    {
        return $this->nextVendorCode($tenantId);
    }
}
