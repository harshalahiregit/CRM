<?php

namespace App\Http\Controllers\Api\Compliance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Compliance\IssueChecklistRequest;
use App\Http\Requests\Compliance\SignChecklistRequest;
use App\Http\Requests\Compliance\SubmitChecklistRequest;
use App\Models\Compliance\ComplianceChecklist;
use App\Services\Compliance\ComplianceChecklistService;
use App\Support\Compliance\SignatureTier;
use Illuminate\Http\Request;

class ComplianceChecklistController extends Controller
{
    public function __construct(private ComplianceChecklistService $checklistService)
    {
    }

    public function index(Request $request)
    {
        return response()->json(
            $this->checklistService->list(
                $request->user()->tenant_id,
                $request->only(['status', 'risk_band', 'template_id', 'subject_type', 'subject_id', 'awaiting'])
            )
        );
    }

    public function stats(Request $request)
    {
        return response()->json($this->checklistService->stats($request->user()->tenant_id));
    }

    /**
     * Issue. The fill-in link is returned exactly once, here — the token is
     * hidden on the model everywhere else, so this response is the only place
     * it is legitimately disclosed.
     */
    public function store(IssueChecklistRequest $request)
    {
        $checklist = $this->checklistService->issue($request->validated(), $request->user());

        return response()->json([
            'checklist'  => $checklist,
            'fill_token' => $this->fillToken($checklist),
        ], 201);
    }

    public function show(Request $request, ComplianceChecklist $checklist)
    {
        $this->assertTenant($request, $checklist);
        $checklist->load([
            'template:id,code,name,category,definition,thresholds',
            'signatures.user:id,name', 'issuer:id,name', 'assignee:id,name', 'auditLogs',
        ]);

        return response()->json([
            'checklist' => $checklist,
            'form'      => $this->checklistService->form($checklist),
        ]);
    }

    /** Save progress without submitting. */
    public function saveResponses(Request $request, ComplianceChecklist $checklist)
    {
        $this->assertTenant($request, $checklist);
        $data = $request->validate(['responses' => 'required|array']);

        return response()->json($this->checklistService->saveResponses($checklist, $data['responses']));
    }

    public function submit(SubmitChecklistRequest $request, ComplianceChecklist $checklist)
    {
        $this->assertTenant($request, $checklist);

        return response()->json($this->checklistService->submit(
            $checklist,
            $request->validated() + ['selfie' => $request->file('selfie')],
            ['ip' => $request->ip(), 'user_agent' => $request->userAgent()],
            $request->user(),
        ));
    }

    /** Manager tier of the signature chain. */
    public function managerSign(SignChecklistRequest $request, ComplianceChecklist $checklist)
    {
        return $this->sign($request, $checklist, SignatureTier::MANAGER);
    }

    /** Head tier — the final approval. */
    public function headSign(SignChecklistRequest $request, ComplianceChecklist $checklist)
    {
        return $this->sign($request, $checklist, SignatureTier::HEAD);
    }

    public function reopen(Request $request, ComplianceChecklist $checklist)
    {
        $this->assertTenant($request, $checklist);
        $reopened = $this->checklistService->reopen($checklist, $request->user());

        return response()->json([
            'checklist'  => $reopened,
            // A new token was minted, so the caller needs the new one to send on.
            'fill_token' => $this->fillToken($reopened),
        ]);
    }

    private function sign(SignChecklistRequest $request, ComplianceChecklist $checklist, string $tier)
    {
        $this->assertTenant($request, $checklist);

        return response()->json($this->checklistService->act(
            $checklist,
            $tier,
            $request->validated()['action'],
            $request->user(),
            $request->validated() + ['signature' => $request->file('signature')],
        ));
    }

    /**
     * The fill-in token, disclosed once at issue/reopen.
     *
     * Returns the token and not a URL: the frontend composes the link from
     * window.location.origin, the same way the worker badge builds its /scan/
     * QR. The API host and the browser origin are not the same thing, so the
     * backend is the wrong place to guess it.
     */
    private function fillToken(ComplianceChecklist $checklist): ?string
    {
        // getRawOriginal: public_token is $hidden, so the serialized model no
        // longer carries it by the time we get here.
        return $checklist->getRawOriginal('public_token');
    }

    private function assertTenant(Request $request, ComplianceChecklist $checklist): void
    {
        abort_unless(
            (int) $checklist->tenant_id === (int) $request->user()->tenant_id,
            404,
            'Checklist not found'
        );
    }
}
