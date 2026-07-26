<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hr\HrOffer;
use App\Services\Hr\OfferService;
use Illuminate\Http\Request;

/**
 * Public, unauthenticated candidate Offer Letter portal. Access is scoped by the
 * secret {token}; the service resolves the offer/tenant from it and hard-scopes
 * every read/write. No candidate can reach another candidate's or tenant's offer.
 */
class OfferPortalController extends Controller
{
    public function __construct(private OfferService $offerService)
    {
    }

    /* GET /api/offer/{token} — view offer (marks it Viewed) */
    public function show(string $token)
    {
        $offer = $this->offerService->byToken($token);

        return response()->json($this->offerService->portalView($offer, markViewed: true));
    }

    /* GET /api/offer/{token}/letter — download the offer letter file (if any) */
    public function letter(string $token)
    {
        $offer = $this->offerService->byToken($token);
        $file  = $this->offerService->offerLetterFile($offer);

        abort_if(! $file, 404, 'Offer letter file is not available for download.');

        return response()->download($file['path'], $file['filename']);
    }

    /* POST /api/offer/{token}/accept — accept with the confirmation checkbox */
    public function accept(Request $request, string $token)
    {
        $data = $request->validate([
            'agreed'    => 'required|accepted',
            'full_name' => 'required|string|max:150',
            // Optional drawn signature as a base64 PNG data URL.
            'signature' => 'nullable|string|max:2000000',
        ]);

        $offer = $this->offerService->byToken($token);

        $ua = (string) $request->userAgent();
        $this->offerService->accept($offer, [
            'ip'        => $request->ip(),
            'device'    => $this->deviceFrom($ua),
            'browser'   => $this->browserFrom($ua),
            'name'      => $data['full_name'],
            'signature' => $data['signature'] ?? null,
        ]);

        return response()->json(['success' => true, 'offer' => $this->offerService->portalView($offer->fresh('candidate'))]);
    }

    /* POST /api/offer/{token}/decline */
    public function decline(Request $request, string $token)
    {
        $data  = $request->validate(['reason' => 'nullable|string|max:1000']);
        $offer = $this->offerService->byToken($token);
        $this->offerService->decline($offer, $data['reason'] ?? null);

        return response()->json(['success' => true, 'offer' => $this->offerService->portalView($offer->fresh('candidate'))]);
    }

    /* POST /api/offer/{token}/clarify */
    public function clarify(Request $request, string $token)
    {
        $data  = $request->validate(['message' => 'required|string|max:1000']);
        $offer = $this->offerService->byToken($token);
        $this->offerService->requestClarification($offer, $data['message']);

        return response()->json(['success' => true, 'offer' => $this->offerService->portalView($offer->fresh('candidate'))]);
    }

    /* POST /api/offer/{token}/tasks — complete a pre-joining task */
    public function task(Request $request, string $token)
    {
        $data = $request->validate([
            'key'   => 'required|string',
            'value' => 'nullable|string|max:2000',
            'file'  => 'nullable|file|mimes:pdf,jpg,jpeg,png,doc,docx|max:10240',
        ]);

        $offer = $this->offerService->byToken($token);
        $this->offerService->updatePreJoiningTask($offer, $data['key'], $data['value'] ?? null, $request->file('file'));

        return response()->json(['success' => true, 'offer' => $this->offerService->portalView($offer->fresh('candidate'))]);
    }

    private function deviceFrom(string $ua): string
    {
        return preg_match('/mobile|android|iphone|ipad/i', $ua) ? 'Mobile' : 'Desktop';
    }

    private function browserFrom(string $ua): string
    {
        foreach (['Edg' => 'Edge', 'OPR' => 'Opera', 'Chrome' => 'Chrome', 'Firefox' => 'Firefox', 'Safari' => 'Safari'] as $needle => $name) {
            if (str_contains($ua, $needle)) {
                return $name;
            }
        }

        return 'Unknown';
    }
}
