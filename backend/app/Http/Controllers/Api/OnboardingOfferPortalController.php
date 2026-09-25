<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrOffer;
use App\Services\Hr\OfferService;
use App\Services\Hr\OnboardingPortalToken;

/**
 * The candidate's offer, reached on their ONBOARDING link.
 *
 * The onboarding portal has always had an Offer tab. It used to work by having
 * the onboarding dashboard hand the browser the offer's own raw token, so a
 * candidate who opened one link ended up holding two separate bearer
 * credentials — and the second one was published in an ordinary JSON response.
 * There is no raw offer token to hand over any more, and there should not have
 * been one to hand over before.
 *
 * ONE CREDENTIAL, NOT TWO. The candidate is already authenticated, by the
 * onboarding token they arrived with. This resolves the offer FROM that token,
 * through the candidate who owns the onboarding — so the offer reached is
 * necessarily their own, and there is no way to name another one.
 *
 * A THIN ADAPTER AND NOTHING MORE. Every action, validation rule and response
 * shape is inherited unchanged from OfferPortalController; the only thing
 * overridden is how a token becomes an offer. Accepting an offer here runs the
 * identical OfferService::accept() with the identical state-machine guard, so
 * the two entry points cannot drift apart or disagree about what is allowed.
 *
 * Onboarding token semantics are untouched: the same OnboardingPortalToken
 * resolver, the same TTL, the same revocation, the same fail-closed null. A
 * token that cannot open the onboarding portal cannot open this either, and
 * this grants nothing the candidate could not already reach.
 */
class OnboardingOfferPortalController extends OfferPortalController
{
    public function __construct(
        OfferService $offerService,
        private OnboardingPortalToken $onboardingToken,
    ) {
        parent::__construct($offerService);
    }

    /**
     * The offer belonging to the candidate whose onboarding link this is.
     *
     * Two ways to fail and one answer for both, matching every other token
     * surface: a token that does not resolve, and a candidate with no offer
     * yet, are equally "nothing here". Distinguishing them would tell an
     * unauthenticated caller whether a given token was real.
     */
    protected function resolve(string $token): HrOffer
    {
        $onboarding = $this->onboardingToken->resolve($token);

        // Reached through the relation rather than by id, which is what makes
        // cross-record access impossible rather than merely checked for.
        $offer = $onboarding?->candidate?->offer;

        if (! $offer) {
            throw new BusinessException('Offer link is invalid or has expired.', 404);
        }

        return $offer;
    }
}
