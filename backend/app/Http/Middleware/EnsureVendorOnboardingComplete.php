<?php

namespace App\Http\Middleware;

use App\Support\Purchase\PurchaseOnboardingStatus;
use App\Support\Tpv\TpvOnboardingStatus;
use App\Support\Vendor\VendorStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Onboarding first. Everything else after.
 *
 * A vendor who has not been approved may not run the operational side of the
 * portal. Adding workers matters most: a worker record is what later carries a
 * medical clearance, an induction, a PPE issue and a site badge, so one created
 * before approval is a person who can be walked onto a site by a company nobody
 * has cleared to be there.
 *
 * ── This was hidden, not enforced ───────────────────────────────────────
 * The portal already hid the workforce menu behind `vendor.status === 'Active'`,
 * so from a browser it looked closed. It was not. EnsureVendorPortalAccess asks
 * two questions — is this a vendor role, and does a vendor profile exist — and
 * nothing else, so POST /api/portal/workers answered a Draft vendor the whole
 * time. A hidden menu is not a permission; anything holding the vendor's token
 * could post straight past it.
 *
 * ── Default-deny, with the exceptions written down ──────────────────────
 * The allowlist is the design. Gating route by route means the next operational
 * route somebody adds is open until a person remembers this rule; gating
 * everything except a named list means it is closed until somebody argues for
 * it, in writing, here.
 *
 * ── Why only writes ─────────────────────────────────────────────────────
 * Reads stay open. An unapproved vendor reading their own (empty) worker list
 * discloses nothing, and the portal dashboard calls `workers/stats` to draw the
 * "awaiting approval" screen — blocking it would break the very page that tells
 * them what they are waiting for. The harm is in CREATING operational records,
 * so that is what this refuses.
 */
class EnsureVendorOnboardingComplete
{
    /**
     * Paths a vendor may still write to before they are approved.
     *
     * Each one is here because onboarding itself needs it. Anything not on this
     * list is refused until the vendor is Active.
     */
    private const OPEN_BEFORE_APPROVAL = [
        // The wizard: profile, step changes, and the submission itself. Without
        // these no vendor could ever reach approval in the first place.
        'api/portal/onboarding',
        'api/portal/onboarding/*',
        'api/portal/purchase/onboarding',
        'api/portal/purchase/onboarding/*',

        // Onboarding evidence — the document uploads and re-submissions that a
        // rejected document has to be answered with.
        'api/portal/documents',
        'api/portal/documents/*',
        'api/portal/purchase/documents',
        'api/portal/purchase/documents/*',

        // The kick-off meeting happens BEFORE the vendor is activated — that is
        // the point of a kick-off. Marking attendance on it is a write, so
        // gating this would break the meeting flow for every new vendor.
        'api/portal/meetings',
        'api/portal/meetings/*',
        'api/portal/purchase/meetings',
        'api/portal/purchase/meetings/*',

        // Marking a notification read. They are being told their onboarding was
        // rejected; they must be able to dismiss it.
        'api/portal/notifications',
        'api/portal/notifications/*',
        'api/portal/purchase/notifications',
        'api/portal/purchase/notifications/*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        // Reads are not what this guards — see the class docblock.
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        // Whichever portal gate ran before this one put the vendor here. If
        // neither did, that gate is the thing refusing the request, not this.
        $vendor = $request->attributes->get('portalVendor')
            ?? $request->attributes->get('purchaseVendor');

        if (! $vendor) {
            return $next($request);
        }

        if ($this->isOnboarded($vendor)) {
            return $next($request);
        }

        if ($request->is(...self::OPEN_BEFORE_APPROVAL)) {
            return $next($request);
        }

        // Says which state they are in and what happens next, because "403
        // Forbidden" on a screen they were just shown is indistinguishable from
        // a broken portal.
        return response()->json([
            'status' => 'error',
            'message' => 'Your onboarding is not approved yet, so this is not available. '
                .'Complete and submit your onboarding — once it is approved you can add '
                .'workers and use the rest of the portal.',
            'vendor_status' => $vendor->status,
        ], 403);
    }

    /**
     * Has this vendor's onboarding actually been approved?
     *
     * This used to read `$vendor->status === ACTIVE`, which is not the same
     * question and answers it wrongly in the common case: a vendor can be set
     * Active — by hand, by an older import, by an admin activating before the
     * wizard was finished — while its onboarding still sits In_Progress at step
     * 1. Every one of those walked straight through the gate this class exists
     * to be, and could create the worker records the docblock above describes.
     *
     * So the onboarding decides when there is one. The status column is the
     * fallback for a vendor with no onboarding record at all, where there is
     * nothing to be approved and no other answer available.
     *
     * Both vendor masters arrive here — TPV's Vendor (relation tpvOnboarding,
     * because it can also carry a Purchase one) and PurchaseVendor (relation
     * onboarding) — so the relation is resolved by name rather than assumed.
     */
    private function isOnboarded(object $vendor): bool
    {
        foreach (['onboarding', 'tpvOnboarding'] as $relation) {
            if (! method_exists($vendor, $relation)) {
                continue;
            }

            if ($record = $vendor->{$relation}()->first()) {
                // The two engines keep separate status vocabularies that happen
                // to agree on this word; both are named rather than one being
                // assumed to cover the other.
                return in_array((string) $record->status, [
                    PurchaseOnboardingStatus::APPROVED,
                    TpvOnboardingStatus::APPROVED,
                ], true);
            }
        }

        // No onboarding record at all is NOT a pass. It used to fall through to
        // `status === Active`, which meant a vendor activated by hand skipped
        // onboarding entirely and could write from its first login. Both portals
        // create the record as soon as the vendor opens Onboarding, so refusing
        // here strands nobody — it makes them start.
        return false;
    }
}
