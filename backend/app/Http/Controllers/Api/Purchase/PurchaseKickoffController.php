<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchase\StorePurchaseKickoffRequest;
use App\Http\Requests\Purchase\UpdatePurchaseKickoffRequest;
use App\Models\Purchase\PurchaseKickoffMeeting;
use App\Services\Purchase\PurchaseKickoffService;
use App\Support\Purchase\PurchaseKickoffStatus;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Purchase kickoff meetings — the staff surface over the Purchase-owned kickoff
 * engine (PurchaseKickoffService, purchase_kickoff_* tables). Independent of the
 * shared/TPV kickoff controller and tables. Every bound meeting is tenant-guarded
 * (404 on mismatch).
 */
class PurchaseKickoffController extends Controller
{
    public function __construct(private PurchaseKickoffService $service)
    {
    }

    public function stats(Request $request)
    {
        return response()->json($this->service->stats($request->user()->tenant_id));
    }

    public function dashboard(Request $request)
    {
        return response()->json($this->service->dashboard($request->user()->tenant_id));
    }

    /**
     * The configurable meeting-type catalogue (Sangoe TPV §9 / §39) — powers the
     * "Meeting Type" picker on the New Meeting form. Kickoff is one type here.
     */
    public function meetingTypes(Request $request)
    {
        return response()->json([
            'types'    => \App\Support\Purchase\PurchaseMeetingTypeCatalog::types(),
            'default'  => \App\Support\Purchase\PurchaseMeetingTypeCatalog::DEFAULT,
        ]);
    }

    public function index(Request $request)
    {
        return response()->json(
            $this->service->list($request->user()->tenant_id, $request->only(['status', 'purchase_vendor_id', 'search', 'awaiting_ack']))
        );
    }

    public function store(StorePurchaseKickoffRequest $request)
    {
        $meeting = $this->service->schedule($request->validated(), $request->user());

        return response()->json($meeting, 201);
    }

    public function show(Request $request, PurchaseKickoffMeeting $kickoff)
    {
        $this->assertTenant($request, $kickoff);

        return response()->json($this->service->find($kickoff->id, $request->user()->tenant_id));
    }

    public function update(UpdatePurchaseKickoffRequest $request, PurchaseKickoffMeeting $kickoff)
    {
        $this->assertTenant($request, $kickoff);

        return response()->json($this->service->update($kickoff, $request->validated(), $request->user()));
    }

    public function transition(Request $request, PurchaseKickoffMeeting $kickoff)
    {
        $this->assertTenant($request, $kickoff);

        $data = $request->validate([
            'status'       => ['required', Rule::in(PurchaseKickoffStatus::ALL)],
            'delay_reason' => 'nullable|string|max:500',
            'scheduled_at' => 'nullable|date',
            'minutes'      => 'nullable|string',
        ]);

        return response()->json($this->service->transition($kickoff, $data['status'], $data, $request->user()));
    }

    public function attendance(Request $request, PurchaseKickoffMeeting $kickoff)
    {
        $this->assertTenant($request, $kickoff);

        $data = $request->validate([
            'rows'                     => 'required|array',
            'rows.*.id'                => 'required|integer',
            'rows.*.attended'          => 'nullable|boolean',
            'rows.*.attendance_status' => ['nullable', Rule::in(\App\Models\Purchase\PurchaseKickoffParticipant::ATTENDANCE)],
        ]);

        return response()->json($this->service->markAttendance($kickoff, $data['rows'], $request->user()));
    }

    public function remind(Request $request, PurchaseKickoffMeeting $kickoff)
    {
        $this->assertTenant($request, $kickoff);

        return response()->json($this->service->sendReminder($kickoff, $request->user()));
    }

    public function uploadMom(Request $request, PurchaseKickoffMeeting $kickoff)
    {
        $this->assertTenant($request, $kickoff);

        $request->validate(['file' => 'required|file|mimes:pdf|max:8192']);

        return response()->json($this->service->uploadMom($kickoff, $request->file('file'), $request->user()));
    }

    public function generateMom(Request $request, PurchaseKickoffMeeting $kickoff)
    {
        $this->assertTenant($request, $kickoff);

        return response()->json($this->service->generateMom($kickoff, $request->user()));
    }

    public function momFile(Request $request, PurchaseKickoffMeeting $kickoff)
    {
        $this->assertTenant($request, $kickoff);

        $file = $this->service->currentMomFile($kickoff);
        abort_unless($file, 404, 'MOM not available yet.');

        $this->service->markMomViewed($kickoff);

        return response()->download($file['path'], $file['filename'], [
            'Content-Type'        => $file['mime'],
            'Content-Disposition' => 'inline; filename="'.$file['filename'].'"',
        ]);
    }

    public function previousSummary(Request $request, PurchaseKickoffMeeting $kickoff)
    {
        $this->assertTenant($request, $kickoff);

        return response()->json($this->service->previousSummary($kickoff));
    }

    public function carryForward(Request $request, PurchaseKickoffMeeting $kickoff)
    {
        $this->assertTenant($request, $kickoff);

        return response()->json($this->service->carryForwardOpenItems($kickoff, $request->user()));
    }

    public function momSubmit(Request $request, PurchaseKickoffMeeting $kickoff)
    {
        $this->assertTenant($request, $kickoff);

        return response()->json($this->service->submitMomForApproval($kickoff, $request->user()));
    }

    public function momDecide(Request $request, PurchaseKickoffMeeting $kickoff)
    {
        $this->assertTenant($request, $kickoff);

        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'return'])],
            'note'     => 'nullable|string|max:2000',
        ]);

        return response()->json($this->service->decideMom($kickoff, $data['decision'], $data['note'] ?? null, $request->user()));
    }

    public function momRevise(Request $request, PurchaseKickoffMeeting $kickoff)
    {
        $this->assertTenant($request, $kickoff);

        return response()->json($this->service->reviseMom($kickoff, $request->user()));
    }

    public function publish(Request $request, PurchaseKickoffMeeting $kickoff)
    {
        $this->assertTenant($request, $kickoff);

        return response()->json($this->service->publishForAck($kickoff, $request->user()));
    }

    public function destroy(Request $request, PurchaseKickoffMeeting $kickoff)
    {
        $this->assertTenant($request, $kickoff);

        $this->service->delete($kickoff, $request->user());

        return response()->json(['message' => 'Deleted']);
    }

    private function assertTenant(Request $request, PurchaseKickoffMeeting $kickoff): void
    {
        abort_unless((int) $kickoff->tenant_id === (int) $request->user()->tenant_id, 404, 'Kickoff meeting not found');
    }
}
