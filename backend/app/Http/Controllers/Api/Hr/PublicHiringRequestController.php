<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Models\Hr\HrExternalCompany;
use App\Services\Hr\RecruitmentServicesService;
use Illuminate\Http\Request;

/**
 * Public hiring-request submission (Phase 1) — no auth. An external company
 * submits through its own token link; the token IS the credential. Mirrors the
 * token-based Career / Onboarding / Offer portals.
 */
class PublicHiringRequestController extends Controller
{
    use ApiResponse;

    public function __construct(private RecruitmentServicesService $service)
    {
    }

    private function company(string $token): HrExternalCompany
    {
        $company = HrExternalCompany::where('public_token', $token)->where('status', 'Active')->first();
        abort_if(! $company, 404, 'This hiring-request link is invalid or inactive.');

        return $company;
    }

    /* GET /api/hiring-request/{token} — just the company name for the form header. */
    public function show(string $token)
    {
        $company = $this->company($token);

        return $this->success(['company_name' => $company->name]);
    }

    /* POST /api/hiring-request/{token} — external company submits a requirement. */
    public function store(Request $request, string $token)
    {
        $company = $this->company($token);

        $data = $request->validate([
            'job_title'           => 'required|string|max:200',
            'department'          => 'nullable|string|max:120',
            'employment_type'     => 'nullable|in:Full-time,Part-time,Contract,Internship',
            'work_mode'           => 'nullable|in:Onsite,Remote,Hybrid',
            'number_of_positions' => 'nullable|integer|min:1|max:1000',
            'experience_required' => 'nullable|string|max:100',
            'education'           => 'nullable|string|max:150',
            'location'            => 'nullable|string|max:150',
            'salary_min'          => 'nullable|numeric|min:0',
            'salary_max'          => 'nullable|numeric|min:0|gte:salary_min',
            'required_skills'     => 'nullable|array',
            'required_skills.*'   => 'string|max:60',
            'job_description'     => 'nullable|string|max:20000',
            'priority'            => 'nullable|in:Low,Medium,High,Critical',
            'submitter_name'      => 'nullable|string|max:120',
            'submitter_email'     => 'nullable|email|max:150',
        ]);

        $this->service->publicSubmit($company, $data);

        return $this->success(null, 'Your hiring request has been submitted. Our team will review it shortly.', 201);
    }
}
