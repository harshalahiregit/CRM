<?php

namespace App\Http\Controllers\Api\Tpv;

use App\Http\Controllers\Controller;
use App\Models\Tpv\TpvApproval;
use App\Services\Tpv\TpvApprovalService;
use App\Support\Tpv\TpvSettings;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Central TPV approval register (Sangoe TPV §12). Separate from onboarding approvals. */
class TpvApprovalController extends Controller
{
    public function __construct(private TpvApprovalService $service, private TpvSettings $settings) {}

    public function index(Request $request)
    {
        return response()->json([
            'data' => $this->service->list($request->user()->tenant_id, $request->only(['status', 'approval_type', 'vendor_id'])),
            // Effective (tenant-configurable §34) active types for the dropdown.
            'types' => array_values(array_map(
                fn ($t) => ['value' => $t['value'], 'label' => $t['label']],
                array_filter($this->settings->approvalTypes($request->user()->tenant_id)['types'], fn ($t) => $t['is_active'] ?? true)
            )),
        ]);
    }

    public function store(Request $request)
    {
        $active = array_column(
            array_filter($this->settings->approvalTypes($request->user()->tenant_id)['types'], fn ($t) => $t['is_active'] ?? true),
            'value'
        );
        $data = $request->validate([
            'approval_type' => ['required', Rule::in($active)],
            'title' => 'required|string|max:200',
            'description' => 'nullable|string',
            'vendor_id' => 'nullable|integer|exists:vendors,id',
            'subject_type' => 'nullable|string|max:150',
            'subject_id' => 'nullable|integer',
            'priority' => ['nullable', Rule::in(TpvApproval::PRIORITIES)],
        ]);

        return response()->json(
            $this->service->raise($data, $request->user()->tenant_id, $request->user()->id),
            201
        );
    }

    public function decide(Request $request, TpvApproval $approval)
    {
        $this->assertTenant($request, $approval);

        // Writes are admin-only; reads (index) stay open to staff.
        abort_unless($request->user()->role === 'admin', 403, 'Only an admin may decide approvals.');

        $data = $request->validate([
            'decision' => 'required|in:approve,reject,cancel',
            'remarks' => 'nullable|string',
        ]);

        return response()->json(
            $this->service->decide($approval, $data['decision'], $data['remarks'] ?? null, $request->user())
        );
    }
}
