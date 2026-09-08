<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchase\StorePurchaseKickoffRequest;
use App\Http\Requests\Purchase\UpdatePurchaseKickoffRequest;
use App\Models\Purchase\PurchaseKickoffDocument;
use App\Models\Purchase\PurchaseKickoffMeeting;
use App\Models\Purchase\PurchaseMomActionItem;
use App\Services\Purchase\PurchaseKickoffContentService;
use App\Services\Purchase\PurchaseKickoffService;
use App\Services\Purchase\PurchaseMeetingRegisterService;
use App\Services\Purchase\PurchaseVendorLiveStatusService;
use App\Services\Shared\OnlineMeetingService;
use App\Support\Purchase\PurchaseKickoffStatus;
use App\Services\Shared\MeetingPresence;
use App\Services\Shared\MeetingRoomNotes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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
     * "Meeting Type" picker, and every option list the meeting form reads.
     *
     * This used to return only `types` and `default`. The form also asks for
     * `templates`, `priorities`, `confidentiality`, `issue_severities` and
     * `issue_categories` — so on Purchase the agenda TEMPLATES never arrived and
     * the "Load template" button, which only renders when its type has one,
     * simply never appeared. config/purchase_meetings.php has had all of it the
     * whole time; nothing was returning it.
     *
     * Types and templates come through the shared catalog with Purchase's own
     * config as the baseline, so a type added on Purchase's Meeting Types
     * settings page (the shared controller is mounted there) shows up here too.
     */
    public function meetingTypes(Request $request, \App\Support\Shared\MeetingTypeCatalog $catalog)
    {
        $tenantId = (int) $request->user()->tenant_id;
        $base = 'purchase_meetings';

        return response()->json([
            'types'             => $catalog->types($tenantId, $base),
            'templates'         => $catalog->templates($tenantId, $base),
            // `default` kept alongside `default_type` — the shared engine emits
            // the latter, and this endpoint has always emitted the former.
            'default'           => \App\Support\Purchase\PurchaseMeetingTypeCatalog::DEFAULT,
            'default_type'      => config($base.'.default_type', 'kickoff'),
            'priorities'        => config($base.'.priorities', ['Low', 'Medium', 'High']),
            'meeting_priorities' => config($base.'.meeting_priorities', ['Low', 'Medium', 'High', 'Urgent']),
            'confidentiality'   => config($base.'.confidentiality', ['Public', 'Internal', 'Confidential', 'Restricted']),
            'issue_severities'  => config($base.'.issue_severities', ['Low', 'Medium', 'High', 'Critical']),
            'issue_categories'  => config($base.'.issue_categories', []),
            'decision_statuses' => config($base.'.decision_statuses', ['Active', 'Superseded', 'Rescinded']),
        ]);
    }

    public function index(Request $request)
    {
        return response()->json(
            $this->service->list($request->user()->tenant_id, $request->only(['status', 'purchase_vendor_id', 'search', 'awaiting_ack']))
        );
    }

    public function store(StorePurchaseKickoffRequest $request, PurchaseKickoffContentService $content)
    {
        $meeting = $this->service->schedule($request->validated(), $request->user());

        // The meeting form posts its agenda, actions, decisions and issues in
        // the SAME request. Without this the meeting would save and everything
        // typed into it would be dropped without a word.
        if ($content->hasContent($request->all())) {
            $meeting = $content->sync($meeting, $request->all(), $request->user());
        }

        return response()->json($meeting, 201);
    }

    public function show(Request $request, PurchaseKickoffMeeting $kickoff)
    {
        $this->assertTenant($request, $kickoff);

        $meeting = $this->service->find($kickoff->id, $request->user()->tenant_id);

        // The join link is overlaid by the gate rather than left as the model
        // wrote it: a staff attendee who is not the organiser marks attendance
        // like anybody else, and a link sitting in this payload would make that
        // optional. The organiser and an admin see it unchanged — see
        // MeetingAttendanceGate.
        return response()->json(array_merge(
            $meeting->toArray(),
            app(\App\Services\Shared\MeetingAttendanceGate::class)->stateFor($meeting, $request->user()),
        ));
    }

    /**
     * Generate (or regenerate) the online meeting link.
     *
     * Purchase meetings live in their own table, so they need their own route:
     * posting a Purchase meeting id to the shared engine's endpoint is what
     * produced "No query results for model [App\Models\Shared\KickoffMeeting]".
     */
    public function generateLink(Request $request, PurchaseKickoffMeeting $kickoff, OnlineMeetingService $meetings)
    {
        $this->assertTenant($request, $kickoff);

        $data = $request->validate([
            'platform' => ['nullable', 'string', Rule::in(OnlineMeetingService::ACCEPTED)],
        ]);

        $link = $meetings->createMeeting($kickoff, $data['platform'] ?? null);

        return response()->json([
            'meeting' => $kickoff->fresh(),
            'link'    => $link,
        ]);
    }

    /** The stored link, if this meeting has one. */
    public function link(Request $request, PurchaseKickoffMeeting $kickoff, OnlineMeetingService $meetings,
        \App\Services\Shared\MeetingAttendanceGate $gate)
    {
        $this->assertTenant($request, $kickoff);

        $data = $meetings->getLinkData($kickoff);
        if (! $data) {
            return response()->json(null);
        }

        // Same gate as the meeting payload. This endpoint is the other way into
        // the link, and leaving it open would have made the first one decorative.
        return response()->json(array_merge($data, $gate->stateFor($kickoff, $request->user())));
    }

    /**
     * Mark attendance, and get the link in return.
     *
     * The staff half of what the portal already does. Without it an internal
     * attendee could never be recorded as present — the register would carry the
     * vendors who marked attendance in the portal and nobody from our side,
     * which reads as a meeting the vendor attended alone.
     */
    public function markAttendance(Request $request, PurchaseKickoffMeeting $kickoff,
        \App\Services\Shared\MeetingAttendanceGate $gate)
    {
        $this->assertTenant($request, $kickoff);

        $user = $request->user();

        // Staff are not always on the roster either — the organiser adds the
        // people they expect to speak, not everyone who attends. The identity is
        // the authenticated account, so nothing is guessed.
        return response()->json($gate->mark($kickoff, $user, $request, [
            'name' => $user->name,
            'email' => $user->email,
            'side' => 'internal',
        ]));
    }

    /**
     * The organiser's verdict on who actually attended.
     *
     * Marking attendance in the CRM is a claim — it is also how the person got
     * the joining link at all — and the CRM cannot see a call held on Google
     * Meet, Zoom or Teams. So the organiser decides, and the decision is stored
     * BESIDE the claim rather than over it: "punched CRM attendance but did not
     * join the call" is only writable if both halves survive.
     *
     * Body: rows[] of { id, verdict, verdict_from?, verdict_to?, verdict_note? }
     * Verdict is one of Fully_Present | Partial_Absent | Complete_Absent, or
     * null to take a decision back to unreviewed. Partial requires the window.
     *
     * Authority is the organiser's or an admin's — enforced in the service, not
     * here, because both engines route to it.
     */
    public function attendanceReview(Request $request, PurchaseKickoffMeeting $kickoff,
        \App\Services\Shared\MeetingAttendanceReview $review)
    {
        $this->assertTenant($request, $kickoff);

        $data = $request->validate([
            'rows' => 'required|array|min:1',
            'rows.*.id' => 'required|integer',
            'rows.*.verdict' => 'nullable|string|in:'.implode(',', \App\Support\Shared\AttendanceVerdict::ALL),
            'rows.*.verdict_from' => 'nullable|date',
            'rows.*.verdict_to' => 'nullable|date',
            'rows.*.verdict_note' => 'nullable|string|max:2000',
        ]);

        $counts = $review->review($kickoff, $data['rows'], $request->user());

        return response()->json([
            'counts' => $counts,
            'register' => $review->register($kickoff->fresh()),
        ]);
    }

    /** The register as the review screen reads it: the claim and the verdict, side by side. */
    public function attendanceRegister(Request $request, PurchaseKickoffMeeting $kickoff,
        \App\Services\Shared\MeetingAttendanceReview $review)
    {
        $this->assertTenant($request, $kickoff);

        return response()->json([
            'register' => $review->register($kickoff),
            'may_review' => $review->mayReview($kickoff, $request->user()),
        ]);
    }

    public function update(UpdatePurchaseKickoffRequest $request, PurchaseKickoffMeeting $kickoff,
        PurchaseKickoffContentService $content)
    {
        $this->assertTenant($request, $kickoff);

        $meeting = $this->service->update($kickoff, $request->validated(), $request->user());

        // Edit reuses the whole create form, so it posts the same nested shape.
        // Only collections actually PRESENT are synced — the detail page saves
        // one section at a time and must not wipe the others.
        if ($content->hasContent($request->all())) {
            $meeting = $content->sync($meeting, $request->all(), $request->user());
        }

        return response()->json($meeting);
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


    /**
     * Notes typed in the live meeting room (agenda notes + minutes).
     *
     * The room shows the video and this meeting record on one screen, so the
     * agenda and the roster are in front of the note-taker instead of in
     * another tab. It autosaves, which is why this is separate from `update`:
     * update is a form submission with its own validation and side effects
     * (re-notifying the roster, re-deriving the duration), and none of that
     * should fire every few seconds while people are talking.
     */
    public function saveRoomNotes(Request $request, PurchaseKickoffMeeting $kickoff, MeetingRoomNotes $notes)
    {
        $this->assertTenant($request, $kickoff);

        $request->validate([
            'minutes'             => 'nullable|string|max:20000',
            'agenda'              => 'nullable|array',
            'agenda.*.id'         => 'required|integer',
            'agenda.*.discussion' => 'nullable|string|max:5000',
            'agenda.*.decision'   => 'nullable|string|max:5000',
        ]);

        return response()->json($notes->save(
            $kickoff,
            $request->input('minutes'),
            $request->input('agenda', []),
            $request->has('minutes'),
        ));
    }

    /**
     * Who is in the call right now.
     *
     * The room posts this every few seconds while the meeting runs. It is a
     * snapshot of the whole room rather than one arrival or departure at a
     * time, which is what makes it safe to repeat: see MeetingPresence for why
     * events were the wrong shape here.
     *
     * `ended` closes the record off — the chair left, or the call finished.
     */
    public function roomPresence(Request $request, PurchaseKickoffMeeting $kickoff, MeetingPresence $presence)
    {
        $this->assertTenant($request, $kickoff);

        $data = $request->validate([
            'in_call' => 'present|array|max:200',
            'in_call.*.key' => 'nullable|string|max:191',
            'in_call.*.name' => 'nullable|string|max:191',
            'in_call.*.self' => 'nullable|boolean',
            'ended' => 'nullable|boolean',
        ]);

        return response()->json($presence->reconcile(
            $kickoff,
            $data['in_call'] ?? [],
            (bool) ($data['ended'] ?? false),
            $request->user(),
        ));
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

    /* ── Labelled supporting documents (multiple upload) ────────────────── */

    public function documents(Request $request, PurchaseKickoffMeeting $kickoff)
    {
        $this->assertTenant($request, $kickoff);
        $actionId = $request->integer('action_item_id') ?: null;

        $list = PurchaseKickoffDocument::where('purchase_kickoff_meeting_id', $kickoff->id)
            ->when($actionId, fn ($q) => $q->where('purchase_mom_action_item_id', $actionId),
                fn ($q) => $q->whereNull('purchase_mom_action_item_id'))
            ->with('uploader:id,name')->orderByDesc('id')->get();

        return response()->json(['data' => $list]);
    }

    public function uploadDocuments(Request $request, PurchaseKickoffMeeting $kickoff)
    {
        $this->assertTenant($request, $kickoff);
        $data = $request->validate([
            'files'   => 'required|array|min:1',
            'files.*' => 'file|mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg|max:10240',
            'labels'   => 'nullable|array',
            'labels.*' => 'nullable|string|max:160',
            'action_item_id' => 'nullable|integer',
        ]);
        $actionId = $data['action_item_id'] ?? null;

        $this->service->uploadDocuments($kickoff, $request->file('files', []), $request->input('labels', []), $request->user(), $actionId);

        $list = PurchaseKickoffDocument::where('purchase_kickoff_meeting_id', $kickoff->id)
            ->when($actionId, fn ($q) => $q->where('purchase_mom_action_item_id', $actionId),
                fn ($q) => $q->whereNull('purchase_mom_action_item_id'))
            ->with('uploader:id,name')->orderByDesc('id')->get();

        return response()->json(['data' => $list], 201);
    }

    public function deleteDocument(Request $request, PurchaseKickoffMeeting $kickoff, PurchaseKickoffDocument $document)
    {
        $this->assertTenant($request, $kickoff);
        $this->service->deleteDocument($kickoff, $document, $request->user());

        return response()->json(['message' => 'Document removed']);
    }

    public function downloadDocument(Request $request, PurchaseKickoffMeeting $kickoff, PurchaseKickoffDocument $document)
    {
        $this->assertTenant($request, $kickoff);
        abort_unless((int) $document->purchase_kickoff_meeting_id === (int) $kickoff->id, 404, 'Document not found.');
        abort_unless(
            $document->path && Storage::disk('purchase_kickoff_docs')->exists($document->path),
            404, 'Document file is missing.'
        );

        return Storage::disk('purchase_kickoff_docs')->response(
            $document->path, $document->original_name, [],
            $request->boolean('inline') ? 'inline' : 'attachment'
        );
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

        // Deliberately NOT marked viewed here. This is the administrator's own
        // download, and mom_viewed_at is shown on the meeting as "Viewed by
        // vendor" — stamping it from this route made the tracker assert the
        // vendor had read minutes that only the sender had opened. The stamp is
        // set where the vendor actually reads them, in the portal.

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

        return response()->json($this->service->distributeMom($kickoff, $request->user()));
    }

    public function destroy(Request $request, PurchaseKickoffMeeting $kickoff)
    {
        $this->assertTenant($request, $kickoff);

        $this->service->delete($kickoff, $request->user());

        return response()->json(['message' => 'Deleted']);
    }

    /* ── Cross-meeting registers ──────────────────────────────────────────
     *
     * Purchase could only ever read decisions, issues and actions inside the
     * one meeting that produced them. These read ACROSS meetings, which is the
     * only view that answers "what is still open?" — the question that makes a
     * meeting series a governance record rather than a pile of minutes.
     *
     * Parameter names match the shared registers exactly so the same UI drives
     * both. `project_id` is accepted and ignored: a Purchase meeting is scoped
     * to a vendor and carries no project, and rejecting the parameter would
     * break the shared filter bar for no gain.
     */

    public function decisionRegister(Request $request, PurchaseMeetingRegisterService $registers)
    {
        return response()->json($registers->decisions(
            (int) $request->user()->tenant_id,
            $request->only(['status', 'vendor', 'meeting_id', 'search', 'from', 'to']),
        ));
    }

    public function issueRegister(Request $request, PurchaseMeetingRegisterService $registers)
    {
        return response()->json($registers->issues(
            (int) $request->user()->tenant_id,
            $request->only(['status', 'severity', 'category', 'vendor', 'meeting_id', 'search', 'from', 'to']),
        ));
    }

    public function actionRegister(Request $request, PurchaseMeetingRegisterService $registers)
    {
        return response()->json($registers->actions(
            (int) $request->user()->tenant_id,
            $request->only(['status', 'priority', 'vendor', 'meeting_id', 'search', 'from', 'to']),
        ));
    }

    /** The filter options the three registers offer. */
    public function registerOptions(Request $request, PurchaseMeetingRegisterService $registers)
    {
        return response()->json($registers->options((int) $request->user()->tenant_id));
    }

    /* ── Participant pickers ──────────────────────────────────────────────
     *
     * The meeting form needs people to invite. Purchase had no picker at all,
     * so attendees could only be typed in as free text — which is why the
     * participant tables hold names rather than ids.
     */

    /** Internal staff who can chair, coordinate or attend. */
    public function staff(Request $request)
    {
        return response()->json(
            \App\Models\User::where('tenant_id', $request->user()->tenant_id)
                ->whereIn('role', ['admin', 'staff'])
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'designation'])
        );
    }

    /**
     * Vendors available as a meeting subject.
     *
     * Scoped to this tenant's PURCHASE vendors — the shared engine's picker
     * lists the shared `vendors` table, whose ids are unrelated to these, so
     * reusing it would attach a Purchase meeting to another module's company.
     */
    public function vendors(Request $request)
    {
        return response()->json(
            \App\Models\Purchase\PurchaseVendor::where('tenant_id', $request->user()->tenant_id)
                ->orderBy('company_name')
                ->get(['id', 'company_name', 'purchase_vendor_code', 'status', 'category'])
        );
    }

    /**
     * Every meeting held for one vendor, newest first — the vendor's meeting
     * history, and the rollup the vendor workspace links to.
     */
    public function vendorStatus(Request $request, PurchaseVendorLiveStatusService $status)
    {
        $data = $request->validate([
            'vendor_id' => 'required|integer',
            // The meeting being edited, so it is not counted as its own history.
            'exclude_meeting_id' => 'nullable|integer',
        ]);

        return response()->json($status->snapshot(
            (int) $request->user()->tenant_id,
            (int) $data['vendor_id'],
            isset($data['exclude_meeting_id']) ? (int) $data['exclude_meeting_id'] : null,
        ));
    }

    /**
     * Preview what a new meeting could carry forward. Read-only — the writing
     * half is POST /kickoff/{id}/carry-forward, which is a different question.
     */
    public function carryForwardPreview(Request $request)
    {
        $data = $request->validate([
            // `subject_type` is accepted for shape-compatibility with the shared
            // engine's picker and ignored: a Purchase meeting's subject is always
            // a Purchase vendor.
            'subject_type' => 'nullable|string',
            'subject_id' => 'required|integer',
            'exclude_meeting_id' => 'nullable|integer',
        ]);

        return response()->json($this->service->carryForwardPreview(
            (int) $request->user()->tenant_id,
            (int) $data['subject_id'],
            isset($data['exclude_meeting_id']) ? (int) $data['exclude_meeting_id'] : null,
        ));
    }

    /**
     * Meetings for a vendor (or the whole tenant), newest first.
     *
     * The create screen shows it so a recurring meeting is planned against the
     * last one instead of from scratch.
     */
    public function history(Request $request)
    {
        return response()->json($this->service->vendorHistory(
            (int) $request->user()->tenant_id,
            $request->filled('vendor_id') ? (int) $request->query('vendor_id') : null,
        ));
    }

    /**
     * Projects and customers are subjects the SHARED meeting engine supports.
     * A Purchase meeting is scoped to a vendor and has neither, so these answer
     * with an empty list rather than 404: the shared meeting form asks for both
     * on mount, and a 404 there would surface as an error toast on a screen
     * that is working exactly as intended.
     */
    public function projects(Request $request)
    {
        return response()->json([]);
    }

    public function customers(Request $request)
    {
        return response()->json([]);
    }

    /**
     * Push a MOM action into the Task module as a real Task.
     *
     * Guarded against a second push: the action carries the task id, so a
     * double click cannot mint two tasks for one action.
     */
    public function pushActionTask(Request $request, PurchaseKickoffMeeting $kickoff, PurchaseMomActionItem $action)
    {
        $this->assertTenant($request, $kickoff);
        abort_unless((int) $action->purchase_kickoff_meeting_id === (int) $kickoff->id, 404, 'Action not found on this meeting.');

        return response()->json($this->service->pushActionToTask($action, $request->user()));
    }

    /* ── AI (§18) ─────────────────────────────────────────────────────────
     *
     * Both use the SHARED MeetingAIService — the prompt and its
     * "use only the facts, invent nothing" guardrail are the part that must
     * not drift between two modules, so they are not duplicated here.
     */

    public function aiSuggestAgenda(Request $request, \App\Services\Shared\MeetingAIService $ai)
    {
        $data = $request->validate([
            'meeting_type' => 'nullable|string|max:60',
            'subject_type' => 'nullable|string|max:40',
            'subject_id' => 'nullable|integer',
        ]);

        return response()->json($ai->suggestAgenda(
            (int) $request->user()->tenant_id,
            $data['meeting_type'] ?? config('meetings.default_type', 'kickoff'),
            $data['subject_type'] ?? null,
            $data['subject_id'] ?? null,
        ));
    }

    public function aiSummary(Request $request, PurchaseKickoffMeeting $kickoff, \App\Services\Shared\MeetingAIService $ai)
    {
        $this->assertTenant($request, $kickoff);

        $kickoff->loadMissing(['actionItems', 'momDecisions', 'momIssues']);

        return response()->json($ai->summariseFacts([
            'title' => $kickoff->title,
            'type' => $kickoff->meeting_type_label,
            'decisions' => $kickoff->momDecisions->map(fn ($d) => $d->decision)->all(),
            'actions' => $kickoff->actionItems->map(fn ($a) => trim(strip_tags((string) $a->description))
                .($a->responsible_names ? ' — '.$a->responsible_names : ''))->all(),
            'issues' => $kickoff->momIssues->map(fn ($i) => $i->title.' ('.$i->severity.')')->all(),
        ]));
    }

    private function assertTenant(Request $request, PurchaseKickoffMeeting $kickoff): void
    {
        abort_unless((int) $kickoff->tenant_id === (int) $request->user()->tenant_id, 404, 'Kickoff meeting not found');
    }
}
