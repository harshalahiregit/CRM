<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Controller;
use App\Models\Purchase\PurchaseKickoffMeeting;
use App\Models\Purchase\PurchaseMomActionItem;
use App\Services\Purchase\PurchaseMomActionService;
use App\Support\Purchase\PurchaseMomActionStatus;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Purchase MOM action items — the staff surface over the Purchase-owned action
 * engine (PurchaseMomActionService, purchase_mom_action_items). Independent of
 * the shared/TPV kickoff_mom_items. Every meeting and action is tenant-guarded
 * (404 on mismatch), and each action must belong to the bound meeting.
 */
class PurchaseMomActionController extends Controller
{
    public function __construct(private PurchaseMomActionService $service)
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
            'description'                => 'required|string|max:2000',
            'responsible_participant_id' => 'nullable|integer',
            'responsible_names'          => 'nullable|string|max:500',
            'responsible_org'            => 'nullable|string|max:160',
            'priority'                   => 'nullable|string|max:20',
            'target_date'                => 'nullable|date',
            'remark'                     => 'nullable|string|max:2000',
            'notes'                      => 'nullable|string|max:2000',
        ]);

        return response()->json($this->service->create($kickoff, $data, $request->user()), 201);
    }

    public function update(Request $request, PurchaseKickoffMeeting $kickoff, PurchaseMomActionItem $action)
    {
        $this->assertAction($request, $kickoff, $action);

        $data = $request->validate([
            'description'                => 'sometimes|string|max:2000',
            'responsible_participant_id' => 'sometimes|nullable|integer',
            'responsible_names'          => 'sometimes|nullable|string|max:500',
            'responsible_org'            => 'sometimes|nullable|string|max:160',
            'priority'                   => 'sometimes|nullable|string|max:20',
            'target_date'                => 'sometimes|nullable|date',
            'remark'                     => 'sometimes|nullable|string|max:2000',
            'notes'                      => 'sometimes|nullable|string|max:2000',
        ]);

        return response()->json($this->service->update($action, $data, $request->user()));
    }

    public function progress(Request $request, PurchaseKickoffMeeting $kickoff, PurchaseMomActionItem $action)
    {
        $this->assertAction($request, $kickoff, $action);

        $data = $request->validate([
            'status'            => ['required', Rule::in(PurchaseMomActionStatus::ALL)],
            'verification_note' => 'nullable|string|max:2000',
            'evidence'          => 'nullable|file|max:8192',
        ]);

        if ($request->hasFile('evidence')) {
            $data['evidence'] = $request->file('evidence');
        }

        return response()->json($this->service->progress($action, $data, $request->user()));
    }

    public function evidence(Request $request, PurchaseKickoffMeeting $kickoff, PurchaseMomActionItem $action)
    {
        $this->assertAction($request, $kickoff, $action);

        $file = $this->service->evidenceFile($action);
        abort_unless($file, 404, 'No evidence attached.');

        return response()->download($file['path'], $file['filename'], [
            'Content-Type'        => $file['mime'],
            'Content-Disposition' => 'inline; filename="'.$file['filename'].'"',
        ]);
    }

    public function destroy(Request $request, PurchaseKickoffMeeting $kickoff, PurchaseMomActionItem $action)
    {
        $this->assertAction($request, $kickoff, $action);

        $this->service->delete($action, $request->user());

        return response()->json(['message' => 'Deleted']);
    }

    private function assertMeeting(Request $request, PurchaseKickoffMeeting $kickoff): void
    {
        abort_unless((int) $kickoff->tenant_id === (int) $request->user()->tenant_id, 404, 'Meeting not found');
    }

    private function assertAction(Request $request, PurchaseKickoffMeeting $kickoff, PurchaseMomActionItem $action): void
    {
        $this->assertMeeting($request, $kickoff);
        abort_unless(
            (int) $action->tenant_id === (int) $request->user()->tenant_id
                && (int) $action->purchase_kickoff_meeting_id === (int) $kickoff->id,
            404,
            'Action not found'
        );
    }
}
