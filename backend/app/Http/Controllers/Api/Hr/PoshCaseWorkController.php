<?php

namespace App\Http\Controllers\Api\Hr;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Hr\HrPoshCase;
use App\Models\Shared\Attachment;
use App\Services\Hr\Posh\PoshAccessResolver;
use App\Services\Hr\Posh\PoshCaseAuthority;
use App\Services\Hr\Posh\PoshCaseReadAuditor;
use App\Services\Hr\Posh\PoshCaseService;
use App\Services\Hr\Posh\PoshFindingService;
use App\Services\Hr\Posh\PoshInquiryService;
use App\Services\Hr\Posh\PoshReconstitutionService;
use App\Services\Hr\RequestThreadService;
use App\Services\Shared\AttachmentService;
use App\Support\Hr\StaffPermission;
use Illuminate\Http\Request;

/**
 * Everything a case member does with a case.
 *
 * ONE CONTROLLER, because every route here shares one opening move: resolve
 * the case through PoshAccessResolver, which answers its single 404 for
 * anybody who is not an active member. Splitting these across four
 * controllers would mean four copies of that line, and the day one of them
 * was written differently would be the day the boundary leaked.
 *
 * TWO GATES, ALWAYS IN THIS ORDER. Membership first, through the resolver.
 * Then, for anything that runs the case rather than reads it, can_manage_case
 * through PoshCaseAuthority. The second never substitutes for the first.
 *
 * INTAKE IS THE ONE EXCEPTION, and deliberately so: there is no case yet to be
 * a member of. It is gated on the hr_posh_intake capability, which is not
 * admin, not hr_settings, not the HR queue, and grants no access to anything
 * it creates.
 */
class PoshCaseWorkController extends Controller
{
    public function __construct(
        private PoshAccessResolver $access,
        private PoshCaseAuthority $authority,
        private PoshCaseReadAuditor $reads,
        private PoshCaseService $cases,
        private PoshInquiryService $inquiry,
        private PoshFindingService $findings,
        private PoshReconstitutionService $reconstitution,
        private RequestThreadService $thread,
        private AttachmentService $attachments,
    ) {
    }

    /* ── intake ───────────────────────────────────────────────────────── */

    /**
     * Raise a case.
     *
     * The creator gets no membership and therefore cannot read what they just
     * created. That is the intended behaviour, not an oversight: logging a
     * complaint and being entitled to read it are different things,
     * particularly when the complaint concerns a colleague.
     */
    public function store(Request $request)
    {
        $this->canIntake($request);

        $data = $request->validate([
            'committee_id'            => 'required|integer',
            'narrative'               => 'required|string',
            'complainant_type'        => 'nullable|in:employee,token',
            'complainant_employee_id' => 'nullable|integer',
            'complainant_label'       => 'nullable|string|max:150',
            'respondent_employee_id'  => 'nullable|integer',
            'respondent_label'        => 'nullable|string|max:150',
            'incident_at'             => 'nullable|date',
            'incident_place'          => 'nullable|string|max:200',
            'complaint_received_at'   => 'nullable|date',
        ]);

        $case = $this->cases->create($this->tenant($request), $data, $request->user());

        // The reference only. The person who raised it cannot read it back,
        // so returning its contents would be the one disclosure this whole
        // design is arranged to prevent.
        return response()->json([
            'data' => ['id' => $case->id, 'reference' => $case->reference],
        ], 201);
    }

    /* ── status ───────────────────────────────────────────────────────── */

    public function acknowledge(Request $request, int $id)
    {
        $case = $this->resolve($request, $id);

        return response()->json(['data' => [
            'acknowledged_at' => optional($this->cases->acknowledge($case, $request->user())->acknowledged_at)->toIso8601String(),
        ]]);
    }

    public function withdraw(Request $request, int $id)
    {
        $case = $this->resolve($request, $id);
        $data = $request->validate(['reason' => 'required|string|max:2000']);

        $case = $this->cases->withdraw($case, $request->user(), $data['reason']);

        return response()->json(['data' => ['status' => $case->status]]);
    }

    public function close(Request $request, int $id)
    {
        $case = $this->resolve($request, $id);
        $data = $request->validate(['note' => 'nullable|string|max:2000']);

        $case = $this->cases->close($case, $request->user(), $data['note'] ?? null);

        return response()->json(['data' => ['status' => $case->status]]);
    }

    /* ── thread ───────────────────────────────────────────────────────── */

    public function thread(Request $request, int $id)
    {
        $case = $this->resolve($request, $id);
        $this->audit($case, $request, PoshCaseReadAuditor::SURFACE_THREAD);

        // asEmployee: false — everybody who can reach this is a committee
        // member, so internal notes are theirs to read. The restricted
        // complainant view is a separate surface and passes true.
        return response()->json([
            'data' => $this->thread->forSubject($case, asEmployee: false)->map(fn ($m) => [
                'id'         => $m->id,
                'kind'       => $m->kind,
                'event_type' => $m->event_type,
                'body'       => $m->body,
                'author'     => $m->author?->name,
                'created_at' => optional($m->created_at)->toIso8601String(),
            ])->values()->all(),
        ]);
    }

    /** A message — what will one day be visible to the complainant. */
    public function message(Request $request, int $id)
    {
        $case = $this->resolve($request, $id);
        $data = $request->validate(['body' => 'required|string|max:5000']);

        $this->assertOpen($case);
        $this->thread->message($case, $request->user(), $data['body']);

        return response()->json(['message' => 'Added']);
    }

    /** A note — committee only, now and permanently. */
    public function note(Request $request, int $id)
    {
        $case = $this->resolve($request, $id);
        $data = $request->validate(['body' => 'required|string|max:5000']);

        $this->assertOpen($case);
        $this->thread->note($case, $request->user(), $data['body']);

        return response()->json(['message' => 'Added']);
    }

    /* ── attachments ──────────────────────────────────────────────────── */

    public function attachments(Request $request, int $id)
    {
        $case = $this->resolve($request, $id);
        $this->audit($case, $request, PoshCaseReadAuditor::SURFACE_ATTACHMENTS);

        return response()->json([
            'data' => $case->attachments()->get()->map(fn (Attachment $a) => [
                'id' => $a->id, 'name' => $a->name, 'mime' => $a->mime, 'size' => $a->size,
                'uploaded_at' => optional($a->created_at)->toIso8601String(),
            ])->values()->all(),
        ]);
    }

    public function upload(Request $request, int $id)
    {
        $case = $this->resolve($request, $id);
        $this->assertOpen($case);

        $request->validate(['file' => 'required|file|max:20480']);

        $file = $this->attachments->upload(
            HrPoshCase::class, $case->id, (int) $case->tenant_id,
            $request->file('file'), null, [], $request->user()
        );

        $case->recordAudit('POSH Evidence Added', $request->user(), $file->name);

        return response()->json(['data' => ['id' => $file->id, 'name' => $file->name]], 201);
    }

    /**
     * Serve one file.
     *
     * The case is resolved FIRST, and the attachment is then looked up
     * **within that case**. AttachmentService performs no authorisation of its
     * own, so fetching the file by id and checking afterwards would let a
     * member of case A read an attachment belonging to case B — the nested
     * IDOR this ordering exists to prevent.
     */
    public function download(Request $request, int $id, int $attachmentId)
    {
        $case = $this->resolve($request, $id);

        $file = $case->attachments()->whereKey($attachmentId)->first();

        if (! $file) {
            // Same 404 as an unreachable case: whether the file exists
            // elsewhere is not this caller's business.
            throw new BusinessException('Case not found', 404);
        }

        $this->audit($case, $request, PoshCaseReadAuditor::SURFACE_DOWNLOAD);

        $served = $this->attachments->download($file);

        return response()->download($served['path'], $served['filename'], ['Content-Type' => $served['mime']]);
    }

    /* ── inquiry ──────────────────────────────────────────────────────── */

    public function inquiry(Request $request, int $id)
    {
        $case = $this->resolve($request, $id);
        $this->audit($case, $request, PoshCaseReadAuditor::SURFACE_INQUIRY);

        return response()->json(['data' => $this->inquiry->inspect($case)]);
    }

    public function openInquiry(Request $request, int $id)
    {
        $case = $this->resolve($request, $id);

        return response()->json(['data' => $this->inquiry->open($case, $request->user())]);
    }

    public function decide(Request $request, int $id)
    {
        $case = $this->resolve($request, $id);

        $data = $request->validate([
            'decision' => 'required|string|max:20',
            'remarks'  => 'nullable|string|max:2000',
        ]);

        return response()->json([
            'data' => $this->inquiry->decide($case, $request->user(), $data['decision'], $data['remarks'] ?? null),
        ]);
    }

    /* ── findings ─────────────────────────────────────────────────────── */

    public function findings(Request $request, int $id)
    {
        $case = $this->resolve($request, $id);
        $this->audit($case, $request, PoshCaseReadAuditor::SURFACE_FINDINGS);

        return response()->json(['data' => $this->findings->show($case)]);
    }

    public function saveFindings(Request $request, int $id)
    {
        $case = $this->resolve($request, $id);

        $data = $request->validate([
            'summary'        => 'sometimes|nullable|string|max:20000',
            'recommendation' => 'sometimes|nullable|string|max:20000',
        ]);

        return response()->json(['data' => $this->findings->save($case, $request->user(), $data)]);
    }

    public function recordFindings(Request $request, int $id)
    {
        $case = $this->resolve($request, $id);

        return response()->json(['data' => $this->findings->record($case, $request->user())]);
    }

    public function publishFindings(Request $request, int $id)
    {
        $case = $this->resolve($request, $id);

        return response()->json(['data' => $this->findings->publish($case, $request->user())]);
    }

    /* ── reconstitution ───────────────────────────────────────────────── */

    /**
     * Change who is on the case.
     *
     * The ONE route here not gated on case membership. It is hr_settings
     * authority, and it deliberately returns the roster and nothing else — the
     * person repairing a stalled committee has no business reading the
     * complaint, and this response is where that would leak if it were going
     * to.
     */
    public function reconstitute(Request $request, int $id)
    {
        $this->canConfigure($request);

        $case = HrPoshCase::where('tenant_id', $this->tenant($request))->find($id);

        if (! $case) {
            throw new BusinessException('Case not found', 404);
        }

        $data = $request->validate([
            'reason'            => 'required|string|max:2000',
            'members'           => 'required|array|min:1',
            'members.*.user_id' => 'required|integer',
            'members.*.role_key' => 'required|string|max:60',
        ]);

        $roster = $this->reconstitution->reconstitute(
            $case, $data['members'], $request->user(), $data['reason']
        );

        // Reference and roster. No narrative, no complainant, no respondent,
        // no evidence, and no read is audited because none happened.
        return response()->json(['data' => [
            'reference' => $case->reference,
            'members'   => $roster,
        ]]);
    }

    /* ── internals ────────────────────────────────────────────────────── */

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    /** Membership, or the single 404. Every case route opens with this. */
    private function resolve(Request $request, int $id): HrPoshCase
    {
        return $this->access->find($this->tenant($request), $id, $request->user());
    }

    private function audit(HrPoshCase $case, Request $request, string $surface): void
    {
        $this->reads->record($case, $request->user(), $surface, $request->ip());
    }

    /** A finished case takes no more content. */
    private function assertOpen(HrPoshCase $case): void
    {
        if (in_array($case->status, [HrPoshCase::STATUS_CLOSED, HrPoshCase::STATUS_WITHDRAWN], true)) {
            throw new BusinessException('This case is finished and can no longer be added to.', 422);
        }
    }

    /**
     * The capability to RAISE a case.
     *
     * Its own module key, kept separate from everything else on purpose: it is
     * not admin, not hr_settings, not the HR queue and not can_manage_case.
     * Somebody who takes complaints needs none of those, and none of them
     * should imply this.
     *
     * The grid is read DIRECTLY rather than through StaffPermissionService::can(),
     * which lets any role === 'admin' through. That bypass is right for the
     * settings screens it was written for — an administrator locked out of the
     * screen that fixes a misconfiguration is worse than the misconfiguration.
     * It is wrong here: "admin does not imply this" is the requirement, and an
     * administrator granting themselves the capability is one explicit, audited
     * click. Nothing is lost by making them take it.
     */
    private function canIntake(Request $request): void
    {
        $grants = app(\App\Services\Auth\StaffPermissionService::class)
            ->grantsFor($request->user());

        $allowed = in_array(
            StaffPermission::VIEW_GLOBAL, $grants['hr_posh_intake'] ?? [], true
        );

        abort_unless($allowed, 403, 'You are not authorised to raise a POSH case');
    }

    private function canConfigure(Request $request): void
    {
        $user = $request->user();

        $allowed = $user->isAdmin()
            || app(\App\Services\Auth\StaffPermissionService::class)->can(
                $user, StaffPermission::VIEW_GLOBAL, 'hr_settings'
            );

        abort_unless($allowed, 403, 'You are not authorised to reconstitute a POSH case');
    }
}
