<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Controller;
use App\Models\Purchase\PurchaseKickoffMeeting;
use App\Models\Purchase\PurchaseMomAgendaItem;
use App\Services\Purchase\PurchaseMomAgendaService;
use Illuminate\Http\Request;

/**
 * Purchase meeting agenda builder (Meeting.docx §3/§4). Staff surface over
 * PurchaseMomAgendaService (purchase_mom_agenda_items). Tenant-guarded; each
 * agenda item must belong to the bound meeting.
 */
class PurchaseMomAgendaController extends Controller
{
    public function __construct(private PurchaseMomAgendaService $service)
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
            'item'                 => 'required|string|max:255',
            'description'          => 'nullable|string|max:2000',
            'owner_participant_id' => 'nullable|integer',
            'owner_names'          => 'nullable|string|max:300',
            'duration_minutes'     => 'nullable|integer|min:0|max:1440',
            'priority'             => 'nullable|string|max:20',
        ]);

        return response()->json($this->service->create($kickoff, $data, $request->user()), 201);
    }

    public function update(Request $request, PurchaseKickoffMeeting $kickoff, PurchaseMomAgendaItem $agendaItem)
    {
        $this->assertItem($request, $kickoff, $agendaItem);

        $data = $request->validate([
            'item'                 => 'sometimes|string|max:255',
            'description'          => 'sometimes|nullable|string|max:2000',
            'owner_participant_id' => 'sometimes|nullable|integer',
            'owner_names'          => 'sometimes|nullable|string|max:300',
            'duration_minutes'     => 'sometimes|nullable|integer|min:0|max:1440',
            'priority'             => 'sometimes|nullable|string|max:20',
            'discussion'           => 'sometimes|nullable|string|max:5000',
            'decision'             => 'sometimes|nullable|string|max:5000',
        ]);

        return response()->json($this->service->update($agendaItem, $data, $request->user()));
    }

    public function destroy(Request $request, PurchaseKickoffMeeting $kickoff, PurchaseMomAgendaItem $agendaItem)
    {
        $this->assertItem($request, $kickoff, $agendaItem);
        $this->service->delete($agendaItem, $request->user());

        return response()->json(['message' => 'Deleted']);
    }

    public function loadTemplate(Request $request, PurchaseKickoffMeeting $kickoff)
    {
        $this->assertMeeting($request, $kickoff);

        return response()->json($this->service->loadTemplate($kickoff, $request->user()));
    }

    public function copyPrevious(Request $request, PurchaseKickoffMeeting $kickoff)
    {
        $this->assertMeeting($request, $kickoff);

        return response()->json($this->service->copyFromPrevious($kickoff, $request->user()));
    }

    private function assertMeeting(Request $request, PurchaseKickoffMeeting $kickoff): void
    {
        abort_unless((int) $kickoff->tenant_id === (int) $request->user()->tenant_id, 404, 'Meeting not found');
    }

    private function assertItem(Request $request, PurchaseKickoffMeeting $kickoff, PurchaseMomAgendaItem $agendaItem): void
    {
        $this->assertMeeting($request, $kickoff);
        abort_unless(
            (int) $agendaItem->tenant_id === (int) $request->user()->tenant_id
                && (int) $agendaItem->purchase_kickoff_meeting_id === (int) $kickoff->id,
            404,
            'Agenda item not found'
        );
    }
}
