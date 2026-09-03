<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\HrAdvance;
use App\Models\Hr\HrAdvanceSettlement;
use App\Models\Shared\Attachment;
use App\Services\Hr\AdvanceService;
use App\Services\Hr\AdvanceTierService;
use App\Services\Hr\RequestThreadService;
use App\Services\Shared\AttachmentService;
use App\Support\Hr\AdvanceStage;
use Illuminate\Http\Request;

/**
 * Advances, admin side.
 *
 * Every route is gated by `hr.manage` on the route GROUP, for the reason the
 * reimbursement controller records: a check inside each method is a check
 * somebody eventually forgets to add, and index() lists the whole tenant.
 *
 * `hr.manage` gets you into the queue; it does not get you a rung. Which of the
 * three tiers may act on a given request is AdvanceTierService's decision, made
 * per request rather than per role, so being allowed to see the queue and being
 * allowed to approve this particular advance stay separate questions.
 */
class AdvanceController extends Controller
{
    public function __construct(
        private AdvanceService $advances,
        private AdvanceTierService $tiers,
        private RequestThreadService $thread,
        private AttachmentService $attachments,
    ) {
    }

    public function index(Request $request)
    {
        $data = $request->validate([
            'status'      => 'nullable|' . AdvanceStage::rule(),
            'employee_id' => 'nullable|integer',
            'tier'        => 'nullable|' . AdvanceStage::tierRule(),
            'from'        => 'nullable|date',
            'to'          => 'nullable|date',
        ]);

        // A line manager sees their own reports; oversight roles see everything.
        $rows = $this->tiers->scopeQueue(
            HrAdvance::where('tenant_id', $request->user()->tenant_id),
            $request->user()
        )
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($data['employee_id'] ?? null, fn ($q, $e) => $q->where('employee_id', $e))
            ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($data['to'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->with('employee:id,name,employee_code,department,user_id,reporting_manager_id')
            ->withCount('attachments')
            // Anything still waiting on a person first; finished ones are history.
            ->orderByRaw("CASE WHEN status IN ('" . implode("','", [
                AdvanceStage::PENDING, AdvanceStage::MANAGER_APPROVED,
                AdvanceStage::ACCOUNTS_APPROVED, AdvanceStage::APPROVED,
                AdvanceStage::ON_HOLD, AdvanceStage::SETTLEMENT_SUBMITTED,
            ]) . "') THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->get();

        // Filtering by tier after the fetch: "whose turn is it" is derived from
        // the status and, for a held request, from held_from — not a column that
        // can be indexed, and the queue is a working list rather than a report.
        if ($tier = $data['tier'] ?? null) {
            $rows = $rows->filter(fn ($a) => $a->next_tier === $tier)->values();
        }

        // Whether THIS user can act is per-request, so it is answered per row
        // rather than left for the client to guess from a role.
        $rows->each(function ($a) use ($request) {
            $a->setAttribute('my_refusal', $this->tiers->refusalReason($a, $request->user()));
            // Threshold-aware: the model's own next_tier ignores the amount limits,
            // so a small advance would otherwise be shown waiting on a rung it
            // does not need.
            $a->setAttribute('next_tier', $this->tiers->nextTierFor($a));
        });

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function show(Request $request, int $id)
    {
        $advance = $this->find($request, $id);
        $actor   = $request->user();
        $refusal = $this->tiers->refusalReason($advance, $actor);

        return response()->json([
            'status' => 'success',
            'data'   => [
                'advance' => $advance->load([
                    'employee:id,name,employee_code,department,user_id,reporting_manager_id',
                    'attachments',
                    'settlements.attachments',
                ]),
                // asEmployee: false — an admin also sees internal notes.
                'thread'  => $this->thread->forSubject($advance, asEmployee: false),
                'ladder'  => $this->ladder($advance),
                // What the employee is already carrying. Granting a second
                // advance against an unsettled first should be a decision, not
                // an accident — the old screen could not show this at all.
                'outstanding' => $advance->employee
                    ? $this->advances->outstandingFor($advance->employee)
                    : null,
                'can' => [
                    // One reason, used for every action, because they share a gate.
                    'act'      => $refusal === null,
                    'reason'   => $refusal,
                    'disburse' => $advance->status === AdvanceStage::APPROVED,
                    'note'     => true,
                ],
            ],
        ]);
    }

    public function approve(Request $request, int $id)
    {
        $data = $request->validate([
            'amount' => 'nullable|numeric|min:0.01',
            // Required only when the amount actually changes, which the service
            // decides — it knows the figure currently in force.
            'reason' => 'nullable|string|max:1000',
        ]);

        $advance = $this->advances->approve(
            $this->find($request, $id),
            $request->user(),
            isset($data['amount']) ? (float) $data['amount'] : null,
            $data['reason'] ?? null
        );

        return $this->acted($advance, AdvanceStage::label((string) $advance->status) . '.');
    }

    public function decline(Request $request, int $id)
    {
        $data = $request->validate(['reason' => 'required|string|max:1000']);

        return $this->acted(
            $this->advances->decline($this->find($request, $id), $request->user(), $data['reason']),
            'Advance declined.'
        );
    }

    public function hold(Request $request, int $id)
    {
        $data = $request->validate([
            'reason'          => 'required|string|max:1000',
            'proposed_amount' => 'nullable|numeric|min:0.01',
        ]);

        return $this->acted(
            $this->advances->hold(
                $this->find($request, $id),
                $request->user(),
                $data['reason'],
                isset($data['proposed_amount']) ? (float) $data['proposed_amount'] : null
            ),
            'Put on hold. The employee has been asked to respond.'
        );
    }

    public function disburse(Request $request, int $id)
    {
        $data = $request->validate([
            'mode'      => 'required|in:' . implode(',', AdvanceService::MODES),
            // Requiredness depends on the mode, which the service enforces — a
            // rule stated in two places is a rule that drifts.
            'reference' => 'nullable|string|max:100',
            'amount'    => 'nullable|numeric|min:0.01',
        ]);

        return $this->acted(
            $this->advances->disburse(
                $this->find($request, $id),
                $request->user(),
                $data['mode'],
                $data['reference'] ?? null,
                isset($data['amount']) ? (float) $data['amount'] : null
            ),
            'Disbursement recorded.'
        );
    }

    public function note(Request $request, int $id)
    {
        $data = $request->validate(['body' => 'required|string|max:2000']);

        $advance = $this->find($request, $id);
        $this->thread->note($advance, $request->user(), $data['body']);

        return response()->json([
            'status'  => 'success',
            'message' => 'Note added. The employee cannot see it.',
            'data'    => ['thread' => $this->thread->forSubject($advance, asEmployee: false)],
        ]);
    }

    /* ── settlements ─────────────────────────────────────────────────── */

    /** Everything waiting to be reviewed, across the tenant. */
    public function settlements(Request $request)
    {
        // Scoped the same way as the request queue, through the advance the
        // settlement belongs to — a manager must not read their non-reports' bills.
        $visible = $this->tiers->scopeQueue(
            HrAdvance::where('tenant_id', $request->user()->tenant_id), $request->user()
        )->select('id');

        $rows = HrAdvanceSettlement::where('tenant_id', $request->user()->tenant_id)
            ->whereIn('advance_id', $visible)
            ->where('status', HrAdvanceSettlement::PENDING)
            ->with(['advance:id,reference,employee_id,purpose,advance_type,disbursed_amount', 'advance.employee:id,name,employee_code', 'attachments'])
            ->orderBy('id')
            ->get();

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function acceptSettlement(Request $request, int $settlementId)
    {
        $data = $request->validate(['remarks' => 'nullable|string|max:1000']);

        $settlement = $this->advances->acceptSettlement(
            $this->findSettlement($request, $settlementId), $request->user(), $data['remarks'] ?? null
        );

        return response()->json(['status' => 'success', 'message' => 'Settlement accepted.', 'data' => $settlement]);
    }

    public function rejectSettlement(Request $request, int $settlementId)
    {
        $data = $request->validate(['remarks' => 'required|string|max:1000']);

        $settlement = $this->advances->rejectSettlement(
            $this->findSettlement($request, $settlementId), $request->user(), $data['remarks']
        );

        return response()->json([
            'status'  => 'success',
            'message' => 'Sent back. The employee can submit another settlement.',
            'data'    => $settlement,
        ]);
    }

    /* ── files ───────────────────────────────────────────────────────── */

    /**
     * A supporting document, or a bill from one of this advance's settlements.
     *
     * One route for both because a bill is only ever reached through its
     * advance. The lookup is scoped to that advance either way, so an id from
     * another tenant's request does not resolve.
     */
    public function attachment(Request $request, int $id, int $attachmentId)
    {
        $advance = $this->find($request, $id);

        $file = $this->fileOn($advance, $attachmentId);

        $f = $this->attachments->download($file);

        return response()->download($f['path'], $f['filename'], ['Content-Type' => $f['mime']]);
    }

    /* ── internals ───────────────────────────────────────────────────── */

    /** The three rungs and where this request has got to, for the client. */
    private function ladder(HrAdvance $advance): array
    {
        $next = $this->tiers->nextTierFor($advance);
        // Only the rungs this AMOUNT requires. Drawing all three for an advance
        // that needs one would promise signatures nobody is waiting for.
        $ladder = $this->tiers->ladderFor($advance);

        $done = array_flip(array_slice(
            $ladder,
            0,
            $next === null ? count($ladder) : (int) array_search($next, $ladder, true)
        ));

        return array_map(fn ($tier) => [
            'tier'    => $tier,
            'done'    => isset($done[$tier]),
            'current' => $tier === $next,
        ], $ladder);
    }

    private function find(Request $request, int $id): HrAdvance
    {
        return HrAdvance::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
    }

    private function findSettlement(Request $request, int $id): HrAdvanceSettlement
    {
        return HrAdvanceSettlement::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
    }

    private function fileOn(HrAdvance $advance, int $attachmentId): Attachment
    {
        $file = $advance->attachments()->find($attachmentId);

        if (! $file) {
            $settlementIds = $advance->settlements()->pluck('id');

            $file = Attachment::where('attachable_type', HrAdvanceSettlement::class)
                ->whereIn('attachable_id', $settlementIds)
                ->whereKey($attachmentId)
                ->first();
        }

        abort_unless($file, 404);

        return $file;
    }

    private function acted(HrAdvance $advance, string $message)
    {
        return response()->json([
            'status'  => 'success',
            'message' => $message,
            'data'    => [
                'advance' => $advance,
                'thread'  => $this->thread->forSubject($advance, asEmployee: false),
            ],
        ]);
    }
}
