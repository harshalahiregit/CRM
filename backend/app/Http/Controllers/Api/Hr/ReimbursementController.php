<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\HrReimbursement;
use App\Services\Hr\ReimbursementService;
use App\Services\Hr\RequestThreadService;
use App\Services\Shared\AttachmentService;
use App\Support\Hr\ReimbursementStatus;
use Illuminate\Http\Request;

/**
 * The admin side of expense claims.
 *
 * The action set is the same three at every point a claim is open — accept,
 * decline, hold — however it got there. A claim held twice and answered twice
 * offers exactly what a fresh one does; what differs is the thread above it,
 * which is why `show` returns the whole history rather than just the latest hold.
 *
 * EVERY route here is gated by the `hr.manage` middleware, applied to the group
 * rather than checked inside each method. The first draft gated the lookup helper
 * instead, which left index() — the method that lists every claim in the tenant —
 * open to any authenticated user, because it does not go through that helper.
 *
 * Gated on canManageHrQueue, consistent with the rest of the HR module. When the
 * permission grid starts being enforced these move to
 * `permission:hr_attendance,edit` or a reimbursement module of its own — the
 * grid is read but not yet consulted, and switching one module across at a time
 * is the point of introducing it that way.
 */
class ReimbursementController extends Controller
{
    public function __construct(
        private ReimbursementService $claims,
        private RequestThreadService $thread,
        private AttachmentService $attachments,
    ) {
    }

    public function index(Request $request)
    {
        $data = $request->validate([
            'status'      => 'nullable|' . ReimbursementStatus::rule(),
            'employee_id' => 'nullable|integer',
            'from'        => 'nullable|date',
            'to'          => 'nullable|date',
        ]);

        $claims = HrReimbursement::where('tenant_id', $request->user()->tenant_id)
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($data['employee_id'] ?? null, fn ($q, $e) => $q->where('employee_id', $e))
            ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('expense_date', '>=', $d))
            ->when($data['to'] ?? null, fn ($q, $d) => $q->whereDate('expense_date', '<=', $d))
            ->with('employee:id,name,employee_code,department')
            ->withCount('attachments')
            // Anything waiting on a person first; decided claims are history.
            ->orderByRaw("CASE WHEN status IN ('pending','on_hold') THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->get();

        return response()->json(['status' => 'success', 'data' => $claims]);
    }

    public function show(Request $request, int $id)
    {
        $claim = $this->find($request, $id);

        return response()->json([
            'status' => 'success',
            'data'   => [
                // decided_by is a user id; the NAME is what a person needs, and the
                // relation cannot simply be loaded because Eloquent would serialise
                // it over the decided_by column itself.
                'claim'  => tap($claim->load(['employee:id,name,employee_code,department', 'attachments']), function ($c) {
                    $c->setAttribute('decided_by_name', optional($c->decidedBy()->first())->name);
                }),
                // asEmployee: false — an admin sees internal notes as well.
                'thread' => $this->thread->forSubject($claim, asEmployee: false),
                'can'    => [
                    'approve' => ! $claim->isDecided(),
                    'decline' => ! $claim->isDecided(),
                    'hold'    => ! $claim->isDecided(),
                ],
            ],
        ]);
    }

    public function approve(Request $request, int $id)
    {
        $data = $request->validate([
            'amount' => 'nullable|numeric|min:0.01',
            // Required only when the amount actually changes, which the service
            // decides — it knows the current figure, and the client should not
            // have to work out whether its own input is a change.
            'reason' => 'nullable|string|max:1000',
        ]);

        $claim = $this->claims->approve(
            $this->find($request, $id),
            $request->user(),
            isset($data['amount']) ? (float) $data['amount'] : null,
            $data['reason'] ?? null
        );

        return $this->decided($claim, 'Claim approved.');
    }

    public function decline(Request $request, int $id)
    {
        $data = $request->validate(['reason' => 'required|string|max:1000']);

        $claim = $this->claims->decline($this->find($request, $id), $request->user(), $data['reason']);

        return $this->decided($claim, 'Claim declined.');
    }

    public function hold(Request $request, int $id)
    {
        $data = $request->validate([
            // Free text, always. A fixed list of hold reasons would be wrong
            // within a month — the interesting holds are the unanticipated ones.
            'reason'          => 'required|string|max:1000',
            // Optional. Its presence is what turns a question into a counter-offer
            // and what makes Accept appear for the employee.
            'proposed_amount' => 'nullable|numeric|min:0.01',
        ]);

        $claim = $this->claims->hold(
            $this->find($request, $id),
            $request->user(),
            $data['reason'],
            isset($data['proposed_amount']) ? (float) $data['proposed_amount'] : null
        );

        return $this->decided($claim, 'Claim put on hold. The employee has been asked to respond.');
    }

    /** An admin talking to other admins. The employee never sees these. */
    public function note(Request $request, int $id)
    {
        $data = $request->validate(['body' => 'required|string|max:2000']);

        $claim = $this->find($request, $id);
        $this->claims->note($claim, $request->user(), $data['body']);

        return response()->json([
            'status'  => 'success',
            'message' => 'Note added. The employee cannot see it.',
            'data'    => ['thread' => $this->thread->forSubject($claim, asEmployee: false)],
        ]);
    }

    /**
     * The bytes of one receipt. Reviewing a claim means opening what was sent.
     *
     * Scoped through find(), so it is tenant-scoped and gated by hr.manage like
     * everything else on this controller. The attachment is looked up THROUGH
     * the claim rather than by id alone — an id from another tenant's claim
     * must not resolve just because the caller can manage the HR queue here.
     */
    public function attachment(Request $request, int $id, int $attachmentId)
    {
        $claim = $this->find($request, $id);

        $file = $claim->attachments()->findOrFail($attachmentId);

        $f = $this->attachments->download($file);

        return response()->download($f['path'], $f['filename'], ['Content-Type' => $f['mime']]);
    }

    private function find(Request $request, int $id): HrReimbursement
    {
        return HrReimbursement::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
    }

    private function decided(HrReimbursement $claim, string $message)
    {
        return response()->json([
            'status'  => 'success',
            'message' => $message,
            'data'    => [
                'claim'  => $claim,
                'thread' => $this->thread->forSubject($claim, asEmployee: false),
            ],
        ]);
    }
}
