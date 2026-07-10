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
}
