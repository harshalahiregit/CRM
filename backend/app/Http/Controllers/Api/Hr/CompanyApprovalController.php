<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Models\Hr\HrExternalCompany;
use App\Services\Hr\CompanyAccountService;
use Illuminate\Http\Request;

/**
 * HR-side approval of self-registered external companies. Same permission gate
 * (canManageHrQueue) and tenant guard as the rest of Recruitment Services.
 */
class CompanyApprovalController extends Controller
{
    use ApiResponse;

    public function __construct(private CompanyAccountService $service)
    {
    }

    private function assertCanManage(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to manage company accounts');
    }

    private function assertTenant(Request $request, HrExternalCompany $company): void
    {
        abort_unless((int) $company->tenant_id === (int) $request->user()->tenant_id, 404);
    }

    /** GET /api/recruitment-services/company-accounts/pending */
    public function pending(Request $request)
    {
        $this->assertCanManage($request);

        return $this->success($this->service->pending($request->user()->tenant_id));
    }

    /** POST /api/recruitment-services/company-accounts/{company}/approve */
    public function approve(Request $request, HrExternalCompany $company)
    {
        $this->assertCanManage($request);
        $this->assertTenant($request, $company);

        return $this->success($this->service->approve($company, $request->user()), 'Company approved');
    }

    /** POST /api/recruitment-services/company-accounts/{company}/reject */
    public function reject(Request $request, HrExternalCompany $company)
    {
        $this->assertCanManage($request);
        $this->assertTenant($request, $company);
        $data = $request->validate(['reason' => 'nullable|string|max:2000']);

        return $this->success($this->service->reject($company, $request->user(), $data['reason'] ?? null), 'Company rejected');
    }
}
