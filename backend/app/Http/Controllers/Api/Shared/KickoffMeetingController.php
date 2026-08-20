<?php

namespace App\Http\Controllers\Api\Shared;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shared\StoreKickoffMeetingRequest;
use App\Http\Requests\Shared\TransitionKickoffRequest;
use App\Http\Requests\Shared\UpdateKickoffMeetingRequest;
use App\Models\Shared\KickoffAttendee;
use App\Models\Shared\KickoffMeeting;
use App\Services\Shared\KickoffMeetingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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
                $request->only(['status', 'meeting_type', 'subject_type', 'subject_id', 'awaiting_ack', 'search'])
            )
        );
    }

    /** The configurable meeting-type catalogue (Meeting.docx) + agenda priorities. */
    public function meetingTypes()
    {
        return response()->json([
            'types'        => config('meetings.types', []),
            'default_type' => config('meetings.default_type', 'kickoff'),
            'priorities'   => config('meetings.priorities', ['Low', 'Medium', 'High']),
        ]);
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

    /** Mark who attended — a post-meeting edit of the attendance flags. */
    public function attendance(Request $request, KickoffMeeting $kickoffMeeting)
    {
        $this->assertTenant($request, $kickoffMeeting);

        // `attended` was required here; it is now optional so a caller can send
        // attendance_status instead. At least one of the two must be present —
        // an entry carrying neither would silently do nothing.
        $data = $request->validate([
            'attendance'                     => 'required|array|min:1',
            'attendance.*.id'                => 'required|integer',
            'attendance.*.attended'          => 'required_without:attendance.*.attendance_status|nullable|boolean',
            'attendance.*.attendance_status' => 'nullable|string|in:'.implode(',', KickoffAttendee::STATUSES),
            'attendance.*.remark'            => 'nullable|string|max:1000',
        ]);

        return response()->json(
            $this->kickoffService->markAttendance($kickoffMeeting, $data['attendance'], $request->user())
        );
    }

    /** Send a manual reminder — email is live, WhatsApp/SMS are queued stubs. */
    public function remind(Request $request, KickoffMeeting $kickoffMeeting)
    {
        $this->assertTenant($request, $kickoffMeeting);

        return response()->json([
            'status' => 'success',
            'result' => $this->kickoffService->sendReminder($kickoffMeeting, $request->user()),
        ]);
    }

    /** Generate (or regenerate) the Minutes-of-Meeting PDF from existing data. */
    public function generateMom(Request $request, KickoffMeeting $kickoffMeeting)
    {
        $this->assertTenant($request, $kickoffMeeting);

        return response()->json(
            $this->kickoffService->generateMom($kickoffMeeting, $request->user())
        );
    }

    /** Stream the stored MoM document — inline for View, attachment for Download. */
    public function momFile(Request $request, KickoffMeeting $kickoffMeeting)
    {
        $this->assertTenant($request, $kickoffMeeting);

        abort_unless(
            $kickoffMeeting->mom_path && Storage::disk('kickoff_docs')->exists($kickoffMeeting->mom_path),
            404,
            'No minutes document has been generated yet.'
        );

        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return Storage::disk('kickoff_docs')->response(
            $kickoffMeeting->mom_path,
            "MOM-{$kickoffMeeting->id}.pdf",
            ['Content-Type' => 'application/pdf'],
            $disposition
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
