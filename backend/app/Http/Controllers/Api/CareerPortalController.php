<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CareerApplicationRequest;
use App\Services\Hr\CareerPortalService;
use Illuminate\Http\Request;

/**
 * Public, unauthenticated Career Portal. Tenant is taken from the {tenantSlug}
 * URL segment; the service hard-scopes every query to it.
 */
class CareerPortalController extends Controller
{
    public function __construct(private CareerPortalService $careerPortalService)
    {
    }

    /* GET /api/careers/{tenantSlug} — public branding info */
    public function tenant(string $tenantSlug)
    {
        $tenant = $this->careerPortalService->tenant($tenantSlug);

        return response()->json([
            'name'           => $tenant->name,
            'slug'           => $tenant->slug,
            'logo_url'       => $tenant->logo_url,
            'branding_color' => $tenant->branding_color,
        ]);
    }

    /* GET /api/careers/{tenantSlug}/jobs */
    public function jobs(Request $request, string $tenantSlug)
    {
        $tenant = $this->careerPortalService->tenant($tenantSlug);

        return response()->json($this->careerPortalService->jobs($tenant, $request->only(['search', 'department', 'job_type'])));
    }

    /* GET /api/careers/{tenantSlug}/jobs/{jobId} */
    public function job(string $tenantSlug, int $jobId)
    {
        $tenant = $this->careerPortalService->tenant($tenantSlug);

        return response()->json($this->careerPortalService->job($tenant, $jobId));
    }

    /* POST /api/careers/{tenantSlug}/jobs/{jobId}/apply */
    public function apply(CareerApplicationRequest $request, string $tenantSlug, int $jobId)
    {
        $tenant    = $this->careerPortalService->tenant($tenantSlug);
        $candidate = $this->careerPortalService->apply($tenant, $jobId, $request->validated(), $request->file('resume'));

        return response()->json([
            'success'   => true,
            'message'   => 'Application submitted successfully. Our team will review it and get back to you.',
            'reference' => 'APP-'.$candidate->id,
        ], 201);
    }

    /* POST /api/careers/{tenantSlug}/jobs/{jobId}/status — track an application */
    public function status(Request $request, string $tenantSlug, int $jobId)
    {
        $data = $request->validate([
            'email' => 'nullable|email',
            'phone' => 'nullable|string|max:30',
        ]);

        if (empty($data['email']) && empty($data['phone'])) {
            return response()->json(['message' => 'Provide your email or phone to track your application.'], 422);
        }

        $tenant = $this->careerPortalService->tenant($tenantSlug);

        return response()->json(
            $this->careerPortalService->applicationStatus($tenant, $jobId, $data['email'] ?? null, $data['phone'] ?? null)
        );
    }

    /* POST /api/careers/{tenantSlug}/jobs/{jobId}/offer/respond — accept/decline */
    public function respondOffer(Request $request, string $tenantSlug, int $jobId)
    {
        $data = $request->validate([
            'email'  => 'required|email',
            'action' => 'required|in:accept,decline',
            'reason' => 'nullable|string|max:500',
        ]);

        $tenant = $this->careerPortalService->tenant($tenantSlug);

        return response()->json(
            $this->careerPortalService->respondToOffer($tenant, $jobId, $data['email'], $data['action'], $data['reason'] ?? null)
        );
    }

    /* GET /api/careers/{tenantSlug}/jobs/{jobId}/offer/letter — download offer */
    public function offerLetter(Request $request, string $tenantSlug, int $jobId)
    {
        $data   = $request->validate(['email' => 'required|email']);
        $tenant = $this->careerPortalService->tenant($tenantSlug);
        $file   = $this->careerPortalService->offerLetter($tenant, $jobId, $data['email']);

        return response()->download($file['path'], $file['filename']);
    }
}
