<?php

namespace App\Http\Controllers\Api\Vendor;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vendor\StoreVendorRequest;
use App\Http\Requests\Vendor\UpdateVendorRequest;
use App\Models\Vendor\Vendor;
use App\Services\Vendor\VendorService;
use App\Support\Task\VendorTaskLink;
use Illuminate\Http\Request;

class VendorController extends Controller
{
    public function __construct(private VendorService $vendorService)
    {
    }

    public function index(Request $request)
    {
        return response()->json(
            $this->vendorService->list(
                $request->user()->tenant_id,
                // per_page/page/sort_* are additive: omit them and the response
                // is the same bare array every existing caller already reads.
                $request->only([
                    'status', 'vendor_type', 'category', 'engagement', 'search',
                    'per_page', 'sort_column', 'sort_direction',
                ])
            )
        );
    }

    public function store(StoreVendorRequest $request)
    {
        $vendor = $this->vendorService->create($request->validated(), $request->user()->tenant_id);

        return response()->json($vendor, 201);
    }

    /**
     * Tasks linked to this TPV vendor (tasks.rel_type = 'tpv_vendor').
     *
     * Read-only. Tasks are created and edited in the Task module; this endpoint
     * only surfaces them on the vendor's Tasks tab.
     */
    public function tasks(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);
        $tenantId = (int) $request->user()->tenant_id;

        // A TPV signs in as a User, so work delegated to that login belongs on this
        // tab too -- not only tasks raised against the vendor as an organisation.
        $portalUserId = $vendor->user_id
            ?: ($vendor->email ? \App\Models\User::where('tenant_id', $tenantId)
                ->where('email', $vendor->email)->value('id') : null);

        return response()->json([
            'summary' => VendorTaskLink::summary(VendorTaskLink::TPV, $vendor->id, $tenantId, $portalUserId),
            'tasks'   => VendorTaskLink::forVendor(VendorTaskLink::TPV, $vendor->id, $tenantId, $portalUserId),
        ]);
    }

    public function show(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);

        $relations = ['contacts', 'documents', 'tpvOnboarding', 'accountManager:id,name', 'user:id,name,email,status'];
        // Purchase vendors carry a separate onboarding record; load it so the shared
        // detail screen can show the onboarding badge. Pure-TPV payloads are unchanged.
        if ($vendor->hasEngagement('purchase')) {
            $relations[] = 'purchaseOnboarding';
        }

        $vendor->load($relations);
        // Vendor Detail dashboard: last activation e-mail, full notification
        // timeline and portal login stats. All read from existing stores.
        $vendor->setAttribute('last_notification', $this->vendorService->lastNotification($vendor));
        $vendor->setAttribute('notification_timeline', $this->vendorService->notificationTimeline($vendor));
        $vendor->setAttribute('login_stats', $this->vendorService->loginStats($vendor));

        return response()->json($vendor);
    }

    /** Resend the activation e-mail. Active vendors only; every send is logged. */
    public function resendActivation(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);

        return response()->json($this->vendorService->resendActivationEmail($vendor, $request->user()));
    }

    public function update(Request $request, Vendor $vendor, UpdateVendorRequest $updateRequest)
    {
        $this->assertTenant($request, $vendor);

        return response()->json(
            $this->vendorService->update($vendor, $updateRequest->validated(), $request->user())
        );
    }

    /** Admin gate — makes the vendor transactable by Purchase and TPV. */
    public function approve(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);

        $data = $request->validate(['remarks' => 'nullable|string']);

        return response()->json(
            $this->vendorService->approve($vendor, $request->user(), $data['remarks'] ?? null)
        );
    }

    public function updateStatus(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);

        $data = $request->validate([
            'status'  => 'required|string',
            'remarks' => 'nullable|string',
        ]);

        return response()->json(
            $this->vendorService->updateStatus($vendor, $data['status'], $request->user(), $data['remarks'] ?? null)
        );
    }

    public function destroy(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);

        $this->vendorService->destroy($vendor);

        return response()->json(['message' => 'Deleted']);
    }

    public function stats(Request $request)
    {
        return response()->json($this->vendorService->stats($request->user()->tenant_id));
    }

    /** Dashboard "Send Email" action — an ad-hoc message to the vendor. */
    public function sendEmail(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);

        $data = $request->validate([
            'subject' => 'required|string|max:200',
            'body'    => 'required|string|max:5000',
        ]);

        $result = $this->vendorService->sendEmail($vendor, $data['subject'], $data['body'], $request->user());

        return response()->json(['status' => 'success', 'result' => $result]);
    }

    /**
     * GET /api/vendors/{vendor}/login-link
     *
     * Mints a one-time set-password link and returns the pre-filled subject and
     * body for the Send Email dialog. It does NOT send anything — the admin sees
     * the draft, can edit it, and sends via the existing email endpoint. That
     * keeps one send path instead of two.
     */
    public function loginLink(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to issue portal logins');

        return response()->json($this->vendorService->buildLoginLink($vendor));
    }

    private function assertTenant(Request $request, Vendor $vendor): void
    {
        abort_unless((int) $vendor->tenant_id === (int) $request->user()->tenant_id, 404, 'Vendor not found');
    }
}
