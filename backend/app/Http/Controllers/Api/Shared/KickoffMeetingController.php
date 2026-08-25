<?php

namespace App\Http\Controllers\Api\Shared;

use App\Contracts\ProjectDirectoryContract;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shared\StoreKickoffMeetingRequest;
use App\Http\Requests\Shared\TransitionKickoffRequest;
use App\Http\Requests\Shared\UpdateKickoffMeetingRequest;
use App\Models\Shared\KickoffAttendee;
use App\Models\Shared\KickoffMeeting;
use App\Models\Shared\KickoffMomItem;
use App\Models\Shared\MeetingIssue;
use App\Models\User;
use App\Services\Shared\KickoffMeetingService;
use App\Services\Helpdesk\Contracts\CustomerServiceContract;
use App\Services\Shared\MeetingAIService;
use App\Services\Shared\MeetingRegisterService;
use App\Services\Shared\VendorLiveStatusService;
use App\Support\Shared\MeetingIssueStatus;
use App\Support\Shared\MeetingTypeCatalog;
use App\Support\Shared\MomActionStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class KickoffMeetingController extends Controller
{
    public function __construct(private KickoffMeetingService $kickoffService) {}

    public function index(Request $request)
    {
        return response()->json(
            $this->kickoffService->list(
                $request->user()->tenant_id,
                $request->only(['status', 'meeting_type', 'subject_type', 'subject_id', 'awaiting_ack', 'search', 'project_id'])
            )
        );
    }

    /**
     * Active projects for the "which project is this meeting for?" picker
     * (Meeting.docx §16). Read-only, served from the Projects module's contract —
     * a soft link, so no FK and consumers tolerate ids that no longer resolve.
     */
    public function projects(Request $request, ProjectDirectoryContract $projects)
    {
        return response()->json($projects->listProjects($request->user()->tenant_id));
    }

    /**
     * A vendor's live governance status (Meeting.docx §4) — workforce, training,
     * PPE, compliance, incidents, CAPA, strikes, gate. Feeds the create form so a
     * template load can pull the vendor's current status into the agenda.
     */
    public function vendorStatus(Request $request, VendorLiveStatusService $status)
    {
        $data = $request->validate(['vendor_id' => 'required|integer']);

        return response()->json($status->snapshot($request->user()->tenant_id, (int) $data['vendor_id']));
    }

    /**
     * A project's meeting rollup (Meeting.docx §16) — the counts + meeting list a
     * PM sees when they open a project. Keyed on the soft project_id link.
     */
    public function projectMeetings(Request $request, int $project)
    {
        return response()->json(
            $this->kickoffService->projectMeetings($request->user()->tenant_id, $project)
        );
    }

    /** AI: suggest an agenda for a meeting being planned (Meeting.docx §18). */
    public function aiSuggestAgenda(Request $request, MeetingAIService $ai)
    {
        $data = $request->validate([
            'meeting_type' => 'nullable|string|max:60',
            'subject_type' => 'nullable|string|max:40',
            'subject_id' => 'nullable|integer',
        ]);

        return response()->json($ai->suggestAgenda(
            $request->user()->tenant_id,
            $data['meeting_type'] ?? config('meetings.default_type', 'kickoff'),
            $data['subject_type'] ?? null,
            $data['subject_id'] ?? null,
        ));
    }

    /** AI: summarise a meeting's captured minutes (Meeting.docx §18). */
    public function aiSummary(Request $request, KickoffMeeting $kickoffMeeting, MeetingAIService $ai)
    {
        $this->assertTenant($request, $kickoffMeeting);

        return response()->json($ai->summariseMinutes($kickoffMeeting));
    }

    /** The configurable meeting-type catalogue (Meeting.docx) + agenda priorities. */
    public function meetingTypes(Request $request, MeetingTypeCatalog $catalog)
    {
        $tenantId = $request->user()->tenant_id;

        return response()->json([
            // Tenant catalogue = config baseline + admin-defined types/templates.
            'types' => $catalog->types($tenantId),
            'default_type' => config('meetings.default_type', 'kickoff'),
            'priorities' => config('meetings.priorities', ['Low', 'Medium', 'High']),
            // Meeting-level option lists (Meeting.docx §2).
            'meeting_priorities' => config('meetings.meeting_priorities', ['Low', 'Medium', 'High', 'Urgent']),
            'confidentiality' => config('meetings.confidentiality', ['Public', 'Internal', 'Confidential', 'Restricted']),
            // Per-type standard agendas the Agenda Builder can one-click load.
            'templates' => $catalog->templates($tenantId),
            'issue_severities' => config('meetings.issue_severities', ['Low', 'Medium', 'High', 'Critical']),
            'issue_categories' => config('meetings.issue_categories', []),
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
            'subject_type' => 'required|string',
            'subject_id' => 'required|integer',
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

    /** The Meetings dashboard aggregate (Meeting.docx §14). */
    public function dashboard(Request $request)
    {
        return response()->json($this->kickoffService->dashboard($request->user()->tenant_id));
    }

    /**
     * A subject's full meeting history + rollup totals (open actions/issues,
     * status mix, acknowledgements outstanding). Drives the history card.
     */
    public function history(Request $request)
    {
        $data = $request->validate([
            'subject_type' => 'required|string',
            'subject_id' => 'required|integer',
        ]);

        return response()->json(
            $this->kickoffService->subjectHistory($request->user()->tenant_id, $data['subject_type'], $data['subject_id'])
        );
    }

    public function store(StoreKickoffMeetingRequest $request)
    {
        return response()->json(
            $this->kickoffService->schedule($request->validated(), $request->user()),
            201
        );
    }

    public function show(Request $request, KickoffMeeting $kickoffMeeting, ProjectDirectoryContract $projects)
    {
        $this->assertTenant($request, $kickoffMeeting);

        $meeting = $this->kickoffService->find($kickoffMeeting->id, $request->user()->tenant_id);

        // Resolve the soft project link to a display name for the detail view.
        // Single lookup here (not appended to every list row) keeps the list N+1-free.
        $payload = $meeting->toArray();
        $payload['project_label'] = $meeting->project_id
            ? $projects->labelFor((int) $meeting->project_id, $request->user()->tenant_id)
            : null;

        return response()->json($payload);
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
        //
        // The presence check is deliberately NOT `required_without`: that rule
        // reads a NULL attendance_status as absent, and null is a real value
        // here — "not marked yet" (Meeting.docx §6). The form posts every row on
        // save, so one unmarked attendee made the whole request 422 and you could
        // only save attendance by marking every single person.
        $data = $request->validate([
            'attendance' => 'required|array|min:1',
            'attendance.*.id' => 'required|integer',
            'attendance.*.attended' => 'nullable|boolean',
            'attendance.*.attendance_status' => 'nullable|string|in:'.implode(',', KickoffAttendee::STATUSES),
            'attendance.*.remark' => 'nullable|string|max:1000',
        ]);

        // Presence by KEY, so an explicit null still counts as an instruction.
        foreach ($request->input('attendance', []) as $i => $row) {
            if (! is_array($row)
                || (! array_key_exists('attended', $row) && ! array_key_exists('attendance_status', $row))) {
                abort(422, "attendance.{$i} must carry either attended or attendance_status.");
            }
        }

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
            'status' => 'nullable|string|in:'.implode(',', MomActionStatus::ALL),
            'note' => 'nullable|string|max:2000',
            'priority' => 'nullable|string',
            'responsible_org' => 'nullable|string|max:160',
            'target_date' => 'nullable|date',
            'evidence' => 'nullable|file|mimes:pdf,doc,docx,png,jpg,jpeg|max:10240',
        ]);

        // Store an uploaded evidence file (if any) and hand its path to the service.
        if ($request->hasFile('evidence')) {
            $data['evidence_path'] = $request->file('evidence')->store('kickoff/action-evidence', 'kickoff_docs');
        }

        return response()->json(
            $this->kickoffService->progressAction($momItem, $data, $request->user())
        );
    }

    /** Push a MOM action into the Task module as a real Task (Meeting.docx §8). */
    public function pushActionTask(Request $request, KickoffMeeting $kickoffMeeting, KickoffMomItem $momItem)
    {
        $this->assertTenant($request, $kickoffMeeting);
        $this->assertItemBelongs($momItem, $kickoffMeeting);

        return response()->json(
            $this->kickoffService->pushActionToTask($momItem, $request->user())
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
            'status' => 'nullable|string|in:'.implode(',', MeetingIssueStatus::ALL),
            'severity' => 'nullable|string',
            'category' => 'nullable|string|max:60',
            'owner_names' => 'nullable|string|max:300',
            'due_date' => 'nullable|date',
        ]);

        return response()->json($this->kickoffService->progressIssue($meetingIssue, $data, $request->user()));
    }

    /** Escalate a meeting issue into a real HSSE Incident. */
    public function convertIssue(Request $request, KickoffMeeting $kickoffMeeting, MeetingIssue $meetingIssue)
    {
        $this->assertTenant($request, $kickoffMeeting);
        abort_unless((int) $meetingIssue->kickoff_meeting_id === (int) $kickoffMeeting->id, 404, 'Issue not found on this meeting.');

        $data = $request->validate([
            'type' => 'nullable|string|max:60',
            'severity' => 'nullable|string|in:Minor,Moderate,Serious,Fatal',
            'stop_work' => 'nullable|boolean',
        ]);

        return response()->json($this->kickoffService->convertIssueToIncident($meetingIssue, $data, $request->user()));
    }

    /** Convert an issue into a real Sangoe Task (Meeting.docx §10). */
    public function convertIssueTask(Request $request, KickoffMeeting $kickoffMeeting, MeetingIssue $meetingIssue)
    {
        $this->assertTenant($request, $kickoffMeeting);
        abort_unless((int) $meetingIssue->kickoff_meeting_id === (int) $kickoffMeeting->id, 404, 'Issue not found on this meeting.');

        return response()->json($this->kickoffService->convertIssueToTask($meetingIssue, $request->user()));
    }

    /** Escalate a meeting issue into an NCR (Meeting.docx §10). */
    public function convertIssueNcr(Request $request, KickoffMeeting $kickoffMeeting, MeetingIssue $meetingIssue)
    {
        $this->assertTenant($request, $kickoffMeeting);
        abort_unless((int) $meetingIssue->kickoff_meeting_id === (int) $kickoffMeeting->id, 404, 'Issue not found on this meeting.');

        $data = $request->validate([
            'severity' => 'nullable|string|in:Minor,Major,Critical',
            'requirement' => 'nullable|string|max:2000',
        ]);

        return response()->json($this->kickoffService->convertIssueToNcr($meetingIssue, $data, $request->user()));
    }

    /** Escalate a meeting issue into a CAPA (Meeting.docx §10). */
    public function convertIssueCapa(Request $request, KickoffMeeting $kickoffMeeting, MeetingIssue $meetingIssue)
    {
        $this->assertTenant($request, $kickoffMeeting);
        abort_unless((int) $meetingIssue->kickoff_meeting_id === (int) $kickoffMeeting->id, 404, 'Issue not found on this meeting.');

        $data = $request->validate([
            'type' => 'nullable|string|in:Corrective,Preventive',
            'priority' => 'nullable|string|in:Low,Medium,High,Critical',
            'root_cause' => 'nullable|string|max:2000',
        ]);

        return response()->json($this->kickoffService->convertIssueToCapa($meetingIssue, $data, $request->user()));
    }

    /** Raise a meeting issue as an approval request (Meeting.docx §10). */
    public function convertIssueApproval(Request $request, KickoffMeeting $kickoffMeeting, MeetingIssue $meetingIssue)
    {
        $this->assertTenant($request, $kickoffMeeting);
        abort_unless((int) $meetingIssue->kickoff_meeting_id === (int) $kickoffMeeting->id, 404, 'Issue not found on this meeting.');

        $data = $request->validate([
            'approval_type' => 'nullable|string|max:60',
            'priority' => 'nullable|string|in:Low,Medium,High,Urgent',
        ]);

        return response()->json($this->kickoffService->convertIssueToApproval($meetingIssue, $data, $request->user()));
    }

    /** Send (or re-send) the meeting invitation (Meeting.docx §1). */
    public function sendInvitations(Request $request, KickoffMeeting $kickoffMeeting)
    {
        $this->assertTenant($request, $kickoffMeeting);

        return response()->json($this->kickoffService->sendInvitations($kickoffMeeting, $request->user()));
    }

    /** Per-recipient Sent / Viewed / Acknowledged tracker (Meeting.docx §13). */
    public function distribution(Request $request, KickoffMeeting $kickoffMeeting)
    {
        $this->assertTenant($request, $kickoffMeeting);

        return response()->json($this->kickoffService->distributionTracker($kickoffMeeting));
    }

    /** Customers for the "which customer is this meeting for?" picker (§2). */
    public function customers(Request $request, CustomerServiceContract $customers)
    {
        return response()->json($customers->listCustomers($request->user()->tenant_id));
    }

    /**
     * Staff for the participant picker (Meeting.docx §5 — "participants should be
     * linked to Sangoe identities wherever possible").
     *
     * Name, e-mail and designation only, and only inside the caller's tenant: a
     * picker needs to identify a colleague, not expose the staff table.
     */
    public function staff(Request $request)
    {
        return response()->json(
            User::where('tenant_id', $request->user()->tenant_id)
                ->whereIn('role', ['admin', 'staff'])
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'designation'])
        );
    }

    /* ── Cross-meeting registers (Meeting.docx §8 / §9 / §10) ─────────────── */

    public function decisionRegister(Request $request, MeetingRegisterService $registers)
    {
        return response()->json($registers->decisions(
            $request->user()->tenant_id,
            $request->only(['status', 'project_id', 'vendor', 'meeting_id', 'search', 'from', 'to']),
        ));
    }

    public function issueRegister(Request $request, MeetingRegisterService $registers)
    {
        return response()->json($registers->issues(
            $request->user()->tenant_id,
            $request->only(['status', 'severity', 'category', 'project_id', 'vendor', 'meeting_id', 'search', 'from', 'to']),
        ));
    }

    public function actionRegister(Request $request, MeetingRegisterService $registers)
    {
        return response()->json($registers->actions(
            $request->user()->tenant_id,
            $request->only(['status', 'priority', 'project_id', 'vendor', 'meeting_id', 'search', 'from', 'to']),
        ));
    }

    /** The filter options the three registers offer. */
    public function registerOptions(Request $request, MeetingRegisterService $registers)
    {
        return response()->json($registers->options($request->user()->tenant_id));
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

    /** Submit the minutes for approval (Draft → Pending Approval). */
    public function momSubmit(Request $request, KickoffMeeting $kickoffMeeting)
    {
        $this->assertTenant($request, $kickoffMeeting);

        return response()->json(
            $this->kickoffService->submitMomForApproval($kickoffMeeting, $request->user())
        );
    }

    /** Approve or return submitted minutes. decision = approve | return. */
    public function momDecide(Request $request, KickoffMeeting $kickoffMeeting)
    {
        $this->assertTenant($request, $kickoffMeeting);

        $data = $request->validate([
            'decision' => 'required|string|in:approve,return',
            'note' => 'nullable|string|max:2000',
        ]);

        return response()->json(
            $this->kickoffService->decideMom($kickoffMeeting, $data['decision'], $data['note'] ?? null, $request->user())
        );
    }

    /** Reopen approved/distributed minutes for revision (→ Draft). */
    public function momRevise(Request $request, KickoffMeeting $kickoffMeeting)
    {
        $this->assertTenant($request, $kickoffMeeting);

        return response()->json(
            $this->kickoffService->reviseMom($kickoffMeeting, $request->user())
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
            'meeting' => $published,
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
