<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\HrAdvance;
use App\Models\Hr\HrAdvanceSettlement;
use App\Models\Hr\HrEmployee;
use App\Models\Shared\Attachment;
use App\Services\Hr\AdvanceService;
use App\Services\Hr\EmployeeIdentityService;
use App\Services\Hr\RequestThreadService;
use App\Services\Shared\AttachmentService;
use Illuminate\Http\Request;

/**
 * An employee's own advances.
 *
 * The same structural guarantee as the other `me` controllers: no employee_id is
 * accepted anywhere, so these can only ever reach the caller's own requests, and
 * a guessed id returns 404 rather than somebody else's money.
 *
 * The thread is read with asEmployee: true at every call site rather than
 * defaulted — a default is what eventually leaks an internal note.
 */
class MyAdvanceController extends Controller
{
    /** A bill or a supporting document is a photo or a PDF. */
    private const FILE_RULES = 'file|max:10240|mimes:pdf,png,jpg,jpeg,webp,heic';

    private const MAX_FILES = 10;

    public function __construct(
        private AdvanceService $advances,
        private RequestThreadService $thread,
        private EmployeeIdentityService $identity,
        private AttachmentService $attachments,
    ) {
    }

    public function index(Request $request)
    {
        $me = $this->me($request);

        return response()->json([
            'status' => 'success',
            'data'   => HrAdvance::where('tenant_id', $me->tenant_id)
                ->where('employee_id', $me->id)
                ->withCount('attachments')
                ->with('latestSettlement')
                ->orderByDesc('id')
                ->get(),
        ]);
    }

    /** What this employee is still carrying — shown before they ask for more. */
    public function outstanding(Request $request)
    {
        return response()->json([
            'status' => 'success',
            'data'   => $this->advances->outstandingFor($this->me($request)),
        ]);
    }

    public function store(Request $request)
    {
        $me = $this->me($request);

        $data = $request->validate([
            'purpose'                  => 'required|string|max:2000',
            'amount_requested'         => 'required|numeric|min:0.01',
            'advance_type'             => 'nullable|string|max:40',
            'category'                 => 'nullable|string|max:60',
            'project_site'             => 'nullable|string|max:120',
            // Money needed for something already past is a reimbursement, not an
            // advance — but that is a judgement for an approver, so it is only
            // the settlement date that is constrained here.
            'required_date'            => 'nullable|date',
            'expected_settlement_date' => 'nullable|date|after_or_equal:required_date',
            'files'                    => 'nullable|array|max:' . self::MAX_FILES,
            'files.*'                  => self::FILE_RULES,
        ]);

        $advance = $this->advances->request($me, $data, $request->user());

        $this->attach($request, $advance);

        return response()->json([
            'status'  => 'success',
            'message' => 'Advance requested.',
            'data'    => $advance->fresh()->load('attachments'),
        ], 201);
    }

    public function show(Request $request, int $id)
    {
        $advance = $this->own($request, $id);

        return response()->json([
            'status' => 'success',
            'data'   => [
                'advance' => $advance->load(['attachments', 'settlements.attachments']),
                'thread'  => $this->thread->forSubject($advance, asEmployee: true),
                // Derived on the server so the app and the CRM cannot disagree
                // about which buttons this employee should be seeing.
                'can'     => [
                    'reply'           => $advance->isOpen(),
                    'accept_proposal' => $advance->can_accept_proposal,
                    'cancel'          => in_array($advance->status, ['pending', 'on_hold'], true),
                    'settle'          => $advance->status === 'disbursed',
                ],
            ],
        ]);
    }

    public function reply(Request $request, int $id)
    {
        $advance = $this->own($request, $id);

        $data = $request->validate([
            'body'    => 'required|string|max:2000',
            'files'   => 'nullable|array|max:' . self::MAX_FILES,
            'files.*' => self::FILE_RULES,
        ]);

        $advance = $this->advances->reply($advance, $request->user(), $data['body']);

        // Attached to the REQUEST, so a replaced document sits beside the
        // original in one list rather than being buried inside a thread entry.
        $this->attach($request, $advance);

        return $this->view($advance->fresh(), 'Sent.');
    }

    public function accept(Request $request, int $id)
    {
        return $this->view(
            $this->advances->acceptProposal($this->own($request, $id), $request->user()),
            'Accepted. The request continues through the remaining approvals.'
        );
    }

    public function cancel(Request $request, int $id)
    {
        return $this->view(
            $this->advances->cancel($this->own($request, $id), $request->user()),
            'Request withdrawn.'
        );
    }

    /** Accounting for what was actually spent, with the bills. */
    public function settle(Request $request, int $id)
    {
        $advance = $this->own($request, $id);

        $data = $request->validate([
            'actual_expense' => 'required|numeric|min:0',
            'notes'          => 'nullable|string|max:2000',
            'files'          => 'nullable|array|max:' . self::MAX_FILES,
            'files.*'        => self::FILE_RULES,
        ]);

        $settlement = $this->advances->submitSettlement($advance, $request->user(), $data);

        // Bills belong to THIS settlement, so a re-submission carries its own
        // documents instead of overwriting the first set.
        foreach ((array) $request->file('files', []) as $file) {
            $this->attachments->upload(
                HrAdvanceSettlement::class, $settlement->id, (int) $settlement->tenant_id,
                $file, null, [], $request->user()
            );
        }

        return response()->json([
            'status'  => 'success',
            'message' => 'Settlement submitted for review.',
            'data'    => $settlement->fresh()->load('attachments'),
        ], 201);
    }

    public function attachment(Request $request, int $id, int $attachmentId)
    {
        $advance = $this->own($request, $id);

        $file = $advance->attachments()->find($attachmentId);

        if (! $file) {
            $file = Attachment::where('attachable_type', HrAdvanceSettlement::class)
                ->whereIn('attachable_id', $advance->settlements()->pluck('id'))
                ->whereKey($attachmentId)
                ->first();
        }

        abort_unless($file, 404);

        $f = $this->attachments->download($file);

        return response()->download($f['path'], $f['filename'], ['Content-Type' => $f['mime']]);
    }

    /* ── internals ───────────────────────────────────────────────────── */

    private function me(Request $request): HrEmployee
    {
        $employee = $this->identity->employeeFor($request->user());

        abort_unless($employee, 403, 'Your login is not linked to an employee record. Contact HR.');

        return $employee;
    }

    /** One of the caller's own, or nothing. */
    private function own(Request $request, int $id): HrAdvance
    {
        $me = $this->me($request);

        return HrAdvance::where('tenant_id', $me->tenant_id)
            ->where('employee_id', $me->id)
            ->findOrFail($id);
    }

    private function attach(Request $request, HrAdvance $advance): void
    {
        foreach ((array) $request->file('files', []) as $file) {
            $this->attachments->upload(
                HrAdvance::class, $advance->id, (int) $advance->tenant_id,
                $file, null, [], $request->user()
            );
        }
    }

    private function view(HrAdvance $advance, string $message)
    {
        return response()->json([
            'status'  => 'success',
            'message' => $message,
            'data'    => [
                'advance' => $advance,
                'thread'  => $this->thread->forSubject($advance, asEmployee: true),
            ],
        ]);
    }
}
