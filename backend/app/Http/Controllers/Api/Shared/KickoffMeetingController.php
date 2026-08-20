<?php

namespace App\Http\Controllers\Api\Shared;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shared\StoreKickoffMeetingRequest;
use App\Http\Requests\Shared\TransitionKickoffRequest;
use App\Http\Requests\Shared\UpdateKickoffMeetingRequest;
use App\Models\Shared\KickoffAttendee;
use App\Models\Shared\KickoffMeeting;
use App\Models\Shared\KickoffMomItem;
use App\Models\Shared\MeetingIssue;
use App\Services\Shared\KickoffMeetingService;
use App\Support\Shared\MeetingIssueStatus;
use App\Support\Shared\MomActionStatus;
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
            'types'             => config('meetings.types', []),
            'default_type'      => config('meetings.default_type', 'kickoff'),
            'priorities'        => config('meetings.priorities', ['Low', 'Medium', 'High']),
            // Per-type standard agendas the Agenda Builder can one-click load.
            'templates'         => config('meetings.templates', []),
            'issue_severities'  => config('meetings.issue_severities', ['Low', 'Medium', 'High', 'Critical']),
            'issue_categories'  => config('meetings.issue_categories', []),
            'decision_statuses' => config('meetings.decision_statuses', ['Active', 'Superseded', 'Rescinded']),
        ]);
    }

    /**
     * Still-open actions and issues from a subject's earlier meetings, to
     * pre-load into a new one (Meeting.docx — carry-forward). Read-only; the
     * items only become records once the new meeting is saved with them.
     */
    public function carryForward(Request $request)
    {
        $data = $request->validate([
            'subject_type'       => 'required|string',
            'subject_id'         => 'required|integer',
            'exclude_meeting_id' => 'nullable|integer',
        ]);

        return response()->json(
            $this->kickoffService->carryForwardItems(
                $request->user()->tenant_id,
                $data['subject_type'],
                $data['subject_id'],
                $data['exclude_meeting_id'] ?? null,
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

    /** Progress a single MOM action through its lifecycle (the Action Engine). */
    public function progressAction(Request $request, KickoffMeeting $kickoffMeeting, KickoffMomItem $momItem)
    {
        $this->assertTenant($request, $kickoffMeeting);
        $this->assertItemBelongs($momItem, $kickoffMeeting);

        $data = $request->validate([
            'status'          => 'nullable|string|in:'.implode(',', MomActionStatus::ALL),
            'note'            => 'nullable|string|max:2000',
            'priority'        => 'nullable|string',
            'responsible_org' => 'nullable|string|max:160',
            'target_date'     => 'nullable|date',
            'evidence'        => 'nullable|file|mimes:pdf,doc,docx,png,jpg,jpeg|max:10240',
        ]);

        // Store an uploaded evidence file (if any) and hand its path to the service.
        if ($request->hasFile('evidence')) {
            $data['evidence_path'] = $request->file('evidence')->store('kickoff/action-evidence', 'kickoff_docs');
        }

        return response()->json(
            $this->kickoffService->progressAction($momItem, $data, $request->user())
        );
    }

    /** Stream a MOM action's evidence file inline. */
    public function actionEvidence(Request $request, KickoffMeeting $kickoffMeeting, KickoffMomItem $momItem)
    {
        $this->assertTenant($request, $kickoffMeeting);
        $this->assertItemBelongs($momItem, $kickoffMeeting);

        abort_unless($momItem->evidence_path && Storage::disk('kickoff_docs')->exists($momItem->evidence_path), 404, 'No evidence on file.');

        return Storage::disk('kickoff_docs')->response($momItem->evidence_path);
    }

    private function assertItemBelongs(KickoffMomItem $item, KickoffMeeting $meeting): void
    {
        abort_unless((int) $item->kickoff_meeting_id === (int) $meeting->id, 404, 'Action not found on this meeting.');
    }

    /** Progress a meeting issue through its lifecycle (Meeting.docx §10). */
    public function progressIssue(Request $request, KickoffMeeting $kickoffMeeting, MeetingIssue $meetingIssue)
    {
        $this->assertTenant($request, $kickoffMeeting);
        abort_unless((int) $meetingIssue->kickoff_meeting_id === (int) $kickoffMeeting->id, 404, 'Issue not found on this meeting.');

        $data = $request->validate([
            'status'      => 'nullable|string|in:'.implode(',', MeetingIssueStatus::ALL),
            'severity'    => 'nullable|string',
            'category'    => 'nullable|string|max:60',
            'owner_names' => 'nullable|string|max:300',
            'due_date'    => 'nullable|date',
        ]);

        return response()->json($this->kickoffService->progressIssue($meetingIssue, $data, $request->user()));
    }

    /** Escalate a meeting issue into a real HSSE Incident. */
    public function convertIssue(Request $request, KickoffMeeting $kickoffMeeting, MeetingIssue $meetingIssue)
    {
        $this->assertTenant($request, $kickoffMeeting);
        abort_unless((int) $meetingIssue->kickoff_meeting_id === (int) $kickoffMeeting->id, 404, 'Issue not found on this meeting.');

        $data = $request->validate([
            'type'      => 'nullable|string|max:60',
            'severity'  => 'nullable|string|in:Minor,Moderate,Serious,Fatal',
            'stop_work' => 'nullable|boolean',
        ]);

        return response()->json($this->kickoffService->convertIssueToIncident($meetingIssue, $data, $request->user()));
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
