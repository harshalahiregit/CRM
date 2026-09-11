<?php

namespace App\Services\Purchase;

use App\Exceptions\BusinessException;
use App\Models\Purchase\PurchaseVendor;
use App\Models\User;
use App\Support\Purchase\PurchaseAccessStatus as Access;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * The temporary Purchase Vendor's access window: warn, expire, and shut the door.
 *
 * Purchase had the front half of this and none of the back. A temporary vendor
 * got a registration type, an end date and a countdown badge — and when the
 * countdown reached zero, nothing happened. The portal guard looked only at
 * portal_status, nothing ever set portal_status on expiry, and no sweep existed
 * to notice. "Temporary" was a label with a clock next to it.
 *
 * Purchase-owned throughout: its own status vocabulary, its own model, its own
 * notifier. TpvAccessService is the same idea on the other engine and the two
 * never reference each other.
 */
class PurchaseAccessService
{
    public function __construct(private PurchaseActivationNotifier $notifier) {}

    /**
     * Close the window.
     *
     * Deleting the tokens is the part that matters. Flipping portal_status alone
     * stops the NEXT sign-in and leaves anybody already holding a token working
     * away until it lapses on its own — which for a vendor who was logged in
     * when the clock ran out is no expiry at all.
     */
    public function expire(PurchaseVendor $vendor, ?User $actor = null, bool $forced = true): PurchaseVendor
    {
        if ($vendor->access_status === Access::EXPIRED) {
            return $vendor;   // idempotent: the hourly sweep and the request gate both call this
        }

        $vendor->update([
            'access_status'     => Access::EXPIRED,
            // Truncate to now so the badge and the stored status cannot disagree.
            'access_expires_at' => now(),
            'portal_status'     => 'suspended',
        ]);

        // A Purchase vendor authenticates as ITSELF (tokenable = PurchaseVendor),
        // not through a User, so its own tokens are the session.
        $vendor->tokens()->delete();

        $vendor->recordAudit('Temporary Access Expired', $actor, null, ['forced' => $forced]);
        Log::channel('purchase')->info('Purchase temporary access expired', [
            'purchase_vendor_id' => $vendor->id, 'forced' => $forced,
        ]);

        $this->notifier->onAccessExpired($vendor->fresh());

        return $vendor->fresh();
    }

    /**
     * Expire on contact, for a window that ran out between sweeps.
     *
     * The sweep is hourly, so without this a vendor keeps working for up to an
     * hour after their access ends. The guard calls this on the request itself,
     * which closes that gap to zero.
     */
    public function lazyExpire(PurchaseVendor $vendor): PurchaseVendor
    {
        if ($vendor->isTemporary()
            && $vendor->access_status !== Access::CONVERTED
            && $vendor->accessSecondsRemaining() <= 0) {
            return $this->expire($vendor, null, false);
        }

        return $vendor;
    }

    /**
     * Move the window, with a reason.
     *
     * Purchase could promote a temporary vendor to permanent and could let one
     * expire, and had nothing in between — so an admin whose contractor needed
     * three more days had to choose between "permanent for ever" and "locked out
     * on Friday". Neither is what they meant.
     *
     * The reason is mandatory at the request layer, and stored. An extension
     * recording only a new date cannot answer why the window moved, which is the
     * question asked six months later; and a reason nobody is obliged to give is
     * a reason nobody gives.
     */
    public function extend(PurchaseVendor $vendor, User $actor, array $data): PurchaseVendor
    {
        if (! $vendor->isTemporary()) {
            throw new BusinessException('This vendor is permanent — there is no window to extend.', 422);
        }

        $expires = ! empty($data['access_expires_at'])
            ? Carbon::parse($data['access_expires_at'])
            : now()->copy()->addDays((int) ($data['validity_days'] ?? 0));

        if ($expires->isPast()) {
            throw new BusinessException('The new expiry has to be in the future.', 422);
        }

        $vendor->update([
            'access_expires_at'  => $expires,
            'access_status'      => Access::ACTIVE,
            'access_extended_at' => now(),
            'access_extended_by' => $actor->id,
            'extension_reason'   => $data['extension_reason'],
            'validity_days'      => $data['validity_days'] ?? $vendor->validity_days,
            // A new window earns a new set of warnings. Without clearing these,
            // a vendor extended past the 7-day mark is never warned again,
            // because the sweep believes it has already told them.
            'access_reminders_sent' => [],
            // An extension after the window shut is how a locked-out vendor gets
            // back in, so the suspension expire() applied has to come off.
            'portal_status'      => $vendor->portal_status === 'suspended' ? 'active' : $vendor->portal_status,
        ]);

        $vendor->recordAudit('Temporary Access Extended', $actor, $data['extension_reason'], [
            'access_expires_at' => $expires->toIso8601String(),
        ]);
        Log::channel('purchase')->info('Purchase temporary access extended', [
            'purchase_vendor_id' => $vendor->id, 'until' => $expires->toDateString(),
        ]);

        $this->notifier->onAccessExtended($vendor->fresh());

        return $vendor->fresh();
    }

    /**
     * The admin's view of one window: the countdown, who moved it and why, and
     * the audit trail behind it. The Purchase mirror of TpvAccessService::status.
     */
    public function status(PurchaseVendor $vendor): array
    {
        return $this->project($vendor) + [
            'extended_at'      => optional($vendor->access_extended_at)->toIso8601String(),
            'extended_by'      => $vendor->access_extended_by,
            'extension_reason' => $vendor->extension_reason,
            'converted_at'     => optional($vendor->converted_to_permanent_at)->toIso8601String(),
            'validity_days'    => $vendor->validity_days,
            'timeline'         => $vendor->auditLogs()->latest()->take(25)
                ->get(['action', 'comment', 'metadata', 'actor_name', 'created_at']),
        ];
    }

    /** The countdown the portal and the admin badge both read. */
    public function project(PurchaseVendor $vendor): array
    {
        $seconds = $vendor->accessSecondsRemaining();

        return [
            'is_temporary'      => $vendor->isTemporary(),
            'access_status'     => Access::derive($vendor->access_status, $seconds),
            'access_expires_at' => optional($vendor->access_expires_at)->toIso8601String(),
            'seconds_remaining' => $seconds === PHP_INT_MAX ? null : $seconds,
            'band'              => $seconds === PHP_INT_MAX ? 'permanent' : Access::band($seconds),
        ];
    }

    /**
     * The hourly sweep: warn at 7d / 3d / 1d / 6h, then expire what has lapsed.
     *
     * Runs across every tenant, because it is a scheduled command with nobody
     * signed in — hence withoutGlobalScopes on the query.
     */
    public function sendDueReminders(): array
    {
        $sent = 0;
        $expired = 0;

        PurchaseVendor::withoutGlobalScopes()
            ->whereNotNull('access_expires_at')
            // Anything already Expired or Converted is finished with. Null is
            // included on purpose: every row that predates this column has one,
            // and those are exactly the windows nobody has ever swept.
            ->where(fn ($q) => $q->whereNull('access_status')->orWhere('access_status', Access::ACTIVE))
            ->chunkById(200, function ($vendors) use (&$sent, &$expired) {
                foreach ($vendors as $vendor) {
                    if (! $vendor->isTemporary()) {
                        continue;   // a permanent vendor with a stale end date
                    }

                    $seconds = $vendor->accessSecondsRemaining();

                    if ($seconds <= 0) {
                        $this->lazyExpire($vendor);
                        $expired++;
                        continue;
                    }

                    $already = $vendor->access_reminders_sent ?? [];
                    $due = Access::dueReminders($seconds, $already);

                    if ($due === []) {
                        continue;
                    }

                    foreach ($due as $key) {
                        $this->notifier->onAccessExpiring($vendor, $key);
                        $sent++;
                    }

                    // Recorded even if a send failed. A mail server that is down
                    // must not turn into the same warning every hour for a week;
                    // the notifier logs the failure where it can be seen.
                    $vendor->update([
                        'access_reminders_sent' => array_values(array_unique([...$already, ...$due])),
                    ]);
                }
            });

        return ['reminders_sent' => $sent, 'expired' => $expired];
    }
}
