<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Models\Hr\HrHiringRequest;
use App\Services\Hr\RecruitmentServicesService;
use Illuminate\Http\Request;

/**
 * Public client-tracking portal (Phase 2) — no auth. A client follows a
 * hiring request through its per-request tracking token and leaves feedback on
 * delivered candidates. The token IS the credential; mirrors the Phase 1
 * hiring-request portal and the Career/Offer/Onboarding token portals.
 */
class PublicClientTrackingController extends Controller
{
    use ApiResponse;

    public function __construct(private RecruitmentServicesService $service)
    {
    }

    private function request(string $token): HrHiringRequest
    {
        $request = HrHiringRequest::where('tracking_token', $token)->first();
        abort_if(! $request, 404, 'This tracking link is invalid.');

        return $request;
    }

    /* GET /api/client-tracking/{token} — full tracking payload. */
    public function show(string $token)
    {
        return $this->success($this->service->clientTracking($this->request($token)));
    }

    /* POST /api/client-tracking/{token}/feedback — client responds on a delivered candidate. */
    public function feedback(Request $request, string $token)
    {
        $hiringRequest = $this->request($token);
        $data = $request->validate([
            'submission_id' => 'required|integer',
            'decision'      => 'required|in:accept,reject,more',
            'comment'       => 'nullable|string|max:2000',
        ]);

        // Scope the submission to this token's request — a token only governs its own deliveries.
        $submission = $hiringRequest->submissions()->findOrFail($data['submission_id']);
        $this->service->recordClientFeedback($submission, $data['decision'], $data['comment'] ?? null);

        return $this->success(null, 'Thank you — your feedback has been recorded.');
    }

    /* POST /api/client-tracking/{token}/rating — client rates the engagement. */
    public function rating(Request $request, string $token)
    {
        $hiringRequest = $this->request($token);
        $data = $request->validate([
            'overall'           => 'required|integer|min:1|max:5',
            'communication'     => 'nullable|integer|min:1|max:5',
            'candidate_quality' => 'nullable|integer|min:1|max:5',
            'turnaround_time'   => 'nullable|integer|min:1|max:5',
            'feedback'          => 'nullable|string|max:2000',
        ]);

        $this->service->submitClientRating($hiringRequest, $data);

        return $this->success(null, 'Thank you for rating our service.');
    }

    /* GET /api/client-tracking/resume/{shareToken} — client downloads a shared resume. */
    public function resume(string $shareToken)
    {
        $file = $this->service->downloadSharedResume($shareToken);

        return response()->download($file['path'], $file['filename'], [
            'Content-Type'        => $file['mime'],
            'Content-Disposition' => 'inline; filename="'.$file['filename'].'"',
        ]);
    }
}
