<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\StoreOnboardingRequest;
use App\Models\Hr\HrOffer;
use App\Models\Hr\HrOnboarding;
use App\Models\Hr\HrOnboardingDocument;
use App\Services\Hr\OfferService;
use App\Services\Hr\OnboardingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class OnboardingController extends Controller
{
    public function __construct(
        private OnboardingService $onboardingService,
        private OfferService $offerService,
    ) {
    }

    public function index(Request $request)
    {
        return response()->json(
            $this->onboardingService->list($request->user()->tenant_id, $request->only(['status', 'verification_status']))
        );
    }

    public function store(StoreOnboardingRequest $request)
    {
        $record = $this->onboardingService->create($request->validated(), $request->user()->tenant_id);

        return response()->json($record, 201);
    }

    public function show(Request $request, HrOnboarding $onboarding)
    {
        $this->assertTenant($request, $onboarding);

        return response()->json($onboarding->load(['candidate', 'documents']));
    }

    /** HR runs document / background / medical verification and approves/rejects. */
    public function verify(Request $request, HrOnboarding $onboarding)
    {
        $this->assertTenant($request, $onboarding);
        $this->assertCanManage($request);

        $data = $request->validate([
            'doc_verified'        => 'nullable|boolean',
            'background_verified' => 'nullable|boolean',
            'medical_verified'    => 'nullable|boolean',
            'verification_notes'  => 'nullable|string|max:2000',
            'decision'            => 'nullable|in:approve,reject',
            'rejection_reason'    => 'nullable|string|max:2000',
        ]);

        $updated = $this->onboardingService->verify($onboarding, $data);

        // On approval, automatically generate the offer letter/record so HR only
        // has to click "Send Offer" (reuses OfferService; best-effort).
        if (($data['decision'] ?? null) === 'approve' && $updated->isApproved()) {
            $this->autoGenerateOffer($updated, $request->user()->tenant_id);
        }

        return response()->json($updated->fresh(['candidate.offer', 'documents']));
    }

    /** Auto-create a Generated offer for a just-approved candidate (if none exists). */
    private function autoGenerateOffer(HrOnboarding $onboarding, int $tenantId): void
    {
        $candidate = $onboarding->candidate;
        if (! $candidate || HrOffer::where('candidate_id', $candidate->id)->exists()) {
            return;
        }

        $candidate->loadMissing('jobPosting');
        $job = $candidate->jobPosting;
        $ctc = $candidate->expected_ctc ?? optional($job)->salary_to ?? optional($job)->salary_from ?? 0;

        try {
            $this->offerService->create([
                'candidate_id'     => $candidate->id,
                'position'         => $onboarding->position ?? optional($job)->title,
                'department'       => $onboarding->department ?? optional($job)->department,
                'offered_ctc'      => $ctc,
                'joining_date'     => now()->addDays(30)->toDateString(),
                'validity_date'    => now()->addDays(7)->toDateString(),
                'probation_period' => '3 months',
                'notice_period'    => '1 month',
            ], $tenantId);
        } catch (\Throwable $e) {
            Log::channel('hr')->error('Auto offer generation failed', ['onboarding_id' => $onboarding->id, 'error' => $e->getMessage()]);
        }
    }

    /** HR verifies a single uploaded document (Verified / Rejected + remarks). */
    public function verifyDocument(Request $request, HrOnboarding $onboarding, HrOnboardingDocument $document)
    {
        $this->assertTenant($request, $onboarding);
        $this->assertCanManage($request);
        abort_unless((int) $document->onboarding_id === (int) $onboarding->id, 404, 'Document not found');

        $data = $request->validate([
            'status'  => 'required|in:Verified,Rejected,Pending',
            'remarks' => 'nullable|string|max:1000',
        ]);

        $this->onboardingService->verifyDocument($document, $data['status'], $data['remarks'] ?? null);

        return response()->json($onboarding->fresh(['candidate', 'documents']));
    }

    /** Stream a candidate-uploaded onboarding document to HR. */
    public function downloadDocument(Request $request, HrOnboarding $onboarding, HrOnboardingDocument $document)
    {
        $this->assertTenant($request, $onboarding);
        abort_unless((int) $document->onboarding_id === (int) $onboarding->id, 404, 'Document not found');

        abort_unless(Storage::disk(OnboardingService::DOC_DISK)->exists($document->path), 404, 'File not found');

        return response()->download(
            Storage::disk(OnboardingService::DOC_DISK)->path($document->path),
            $document->original_name
        );
    }

    public function toggleStep(Request $request, HrOnboarding $onboarding)
    {
        $this->assertTenant($request, $onboarding);

        if ($request->has('checklist')) {
            return response()->json($this->onboardingService->toggleChecklist($onboarding, $request->input('checklist')));
        }

        $validated = $request->validate([
            'step' => 'required|in:doc_verification,joining_confirmed,emp_id_generated,dept_assigned,manager_assigned,record_created',
        ]);

        return response()->json($this->onboardingService->toggleStep($onboarding, $validated['step']));
    }

    public function destroy(Request $request, HrOnboarding $onboarding)
    {
        $this->assertTenant($request, $onboarding);

        $this->onboardingService->destroy($onboarding);

        return response()->json(['message' => 'Deleted']);
    }

    private function assertTenant(Request $request, HrOnboarding $onboarding): void
    {
        abort_unless((int) $onboarding->tenant_id === (int) $request->user()->tenant_id, 404, 'Onboarding not found');
    }

    private function assertCanManage(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to manage onboarding');
    }
}
