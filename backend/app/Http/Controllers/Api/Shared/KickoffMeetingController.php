<?php

namespace App\Http\Controllers\Api\Shared;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shared\StoreKickoffMeetingRequest;
use App\Http\Requests\Shared\TransitionKickoffRequest;
use App\Http\Requests\Shared\UpdateKickoffMeetingRequest;
use App\Models\Shared\KickoffMeeting;
use App\Services\Shared\KickoffMeetingService;
use Illuminate\Http\Request;

class KickoffMeetingController extends Controller
{
    public function __construct(private KickoffMeetingService $kickoffService)
    {
    }

    public function index(Request $request)
    {
        return response()->json(
            $this->kickoffService->list(
                $request->user()->tenant_id,
                $request->only(['status', 'subject_type', 'subject_id', 'awaiting_ack', 'search'])
            )
        );
    }

    public function stats(Request $request)
    {
        return response()->json($this->kickoffService->stats($request->user()->tenant_id));
    }

    public function store(StoreKickoffMeetingRequest $request)
    {
        return response()->json(
            $this->kickoffService->schedule($request->validated(), $request->user()),
            201
        );
    }

    public function show(Request $request, KickoffMeeting $kickoffMeeting)
    {
        $this->assertTenant($request, $kickoffMeeting);

        return response()->json($this->kickoffService->find($kickoffMeeting->id, $request->user()->tenant_id));
    }

    public function update(UpdateKickoffMeetingRequest $request, KickoffMeeting $kickoffMeeting)
    {
        $this->assertTenant($request, $kickoffMeeting);

        return response()->json($this->kickoffService->update($kickoffMeeting, $request->validated(), $request->user()));
    }

    public function transition(TransitionKickoffRequest $request, KickoffMeeting $kickoffMeeting)
    {
        $this->assertTenant($request, $kickoffMeeting);
        $data = $request->validated();

        return response()->json(
            $this->kickoffService->transition($kickoffMeeting, $data['status'], $data, $request->user())
        );
    }

    public function uploadMom(Request $request, KickoffMeeting $kickoffMeeting)
    {
        $this->assertTenant($request, $kickoffMeeting);
        $request->validate(['mom' => 'required|file|mimes:pdf,doc,docx|max:10240']);

        return response()->json(
            $this->kickoffService->uploadMom($kickoffMeeting, $request->file('mom'), $request->user())
        );
    }

    /**
     * Publish minutes for vendor acknowledgement. The token is disclosed once,
     * here — hidden on the model everywhere else, so this is the only place it
     * is legitimately returned. The frontend composes the link from origin.
     */
    public function publish(Request $request, KickoffMeeting $kickoffMeeting)
    {
        $this->assertTenant($request, $kickoffMeeting);
        $published = $this->kickoffService->publishForAck($kickoffMeeting, $request->user());

        return response()->json([
            'meeting'   => $published,
            'ack_token' => $published->getRawOriginal('ack_token'),
        ]);
    }

    public function destroy(Request $request, KickoffMeeting $kickoffMeeting)
    {
        $this->assertTenant($request, $kickoffMeeting);
        $this->kickoffService->delete($kickoffMeeting, $request->user());

        return response()->json(['message' => 'Kickoff meeting deleted']);
    }

    /** Route-model binding does not know about tenants — reads must be guarded. */
    private function assertTenant(Request $request, KickoffMeeting $meeting): void
    {
        abort_unless(
            (int) $meeting->tenant_id === (int) $request->user()->tenant_id,
            404,
            'Kickoff meeting not found'
        );
    }
}
