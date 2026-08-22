<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Controller;
use App\Models\Purchase\PurchaseKickoffMeeting;
use App\Models\Purchase\PurchaseMomDecision;
use App\Services\Purchase\PurchaseMomDecisionService;
use App\Support\Purchase\PurchaseMomDecisionStatus;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Purchase MOM decisions — the staff surface over the Purchase-owned decision
 * register (PurchaseMomDecisionService, purchase_mom_decisions). Independent of
 * the shared/TPV meeting_decisions. Every meeting and decision is tenant-guarded
 * (404 on mismatch), and each decision must belong to the bound meeting.
 */
class PurchaseMomDecisionController extends Controller
{
    public function __construct(private PurchaseMomDecisionService $service)
    {
    }

    public function index(Request $request, PurchaseKickoffMeeting $kickoff)
    {
        $this->assertMeeting($request, $kickoff);

        return response()->json($this->service->forMeeting($kickoff));
    }

    public function store(Request $request, PurchaseKickoffMeeting $kickoff)
    {
        $this->assertMeeting($request, $kickoff);

        $data = $request->validate([
            'decision'                  => 'required|string|max:2000',
            'decided_by_participant_id' => 'nullable|integer',
            'decided_by_names'          => 'nullable|string|max:300',
            'impact'                    => 'nullable|string|max:2000',
            'effective_date'            => 'nullable|date',
            'status'                    => ['nullable', Rule::in(PurchaseMomDecisionStatus::ALL)],
        ]);

        return response()->json($this->service->create($kickoff, $data, $request->user()), 201);
    }

    public function update(Request $request, PurchaseKickoffMeeting $kickoff, PurchaseMomDecision $decision)
    {
        $this->assertDecision($request, $kickoff, $decision);

        $data = $request->validate([
            'decision'                  => 'sometimes|string|max:2000',
            'decided_by_participant_id' => 'sometimes|nullable|integer',
            'decided_by_names'          => 'sometimes|nullable|string|max:300',
            'impact'                    => 'sometimes|nullable|string|max:2000',
            'effective_date'            => 'sometimes|nullable|date',
            'status'                    => ['sometimes', Rule::in(PurchaseMomDecisionStatus::ALL)],
        ]);

        return response()->json($this->service->update($decision, $data, $request->user()));
    }

    public function destroy(Request $request, PurchaseKickoffMeeting $kickoff, PurchaseMomDecision $decision)
    {
        $this->assertDecision($request, $kickoff, $decision);

        $this->service->delete($decision, $request->user());

        return response()->json(['message' => 'Deleted']);
    }

    private function assertMeeting(Request $request, PurchaseKickoffMeeting $kickoff): void
    {
        abort_unless((int) $kickoff->tenant_id === (int) $request->user()->tenant_id, 404, 'Meeting not found');
    }

    private function assertDecision(Request $request, PurchaseKickoffMeeting $kickoff, PurchaseMomDecision $decision): void
    {
        $this->assertMeeting($request, $kickoff);
        abort_unless(
            (int) $decision->tenant_id === (int) $request->user()->tenant_id
                && (int) $decision->purchase_kickoff_meeting_id === (int) $kickoff->id,
            404,
            'Decision not found'
        );
    }
}
