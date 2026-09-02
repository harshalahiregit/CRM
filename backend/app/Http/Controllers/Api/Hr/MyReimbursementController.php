<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrReimbursement;
use App\Services\Hr\EmployeeIdentityService;
use App\Services\Hr\ReimbursementService;
use App\Services\Hr\RequestThreadService;
use App\Services\Shared\AttachmentService;
use Illuminate\Http\Request;

/**
 * The employee's own expense claims.
 *
 * Same structural guarantee as MyAttendanceController: no employee_id is
 * accepted anywhere, so these endpoints can only ever reach the caller's own
 * claims. A claim is found by id AND by owner, so a guessed id from another
 * person returns 404 rather than somebody else's receipts.
 *
 * The thread is read with asEmployee: true throughout — internal notes never
 * leave the admin side, and that flag is passed explicitly at every call rather
 * than defaulted, because a default is what eventually leaks them.
 */
class MyReimbursementController extends Controller
{
    /** A receipt is a photo or a PDF. Both are ordinary; neither is negotiable. */
    private const FILE_RULES = 'file|max:10240|mimes:pdf,png,jpg,jpeg,webp,heic';

    /** Enough for a bill, a payment screenshot and a boarding pass, with room to spare. */
    private const MAX_FILES = 10;

    public function __construct(
        private ReimbursementService $claims,
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
            'data'   => HrReimbursement::where('tenant_id', $me->tenant_id)
                ->where('employee_id', $me->id)
                ->withCount('attachments')
                ->orderByDesc('id')
                ->get(),
        ]);
    }

    public function store(Request $request)
    {
        $me = $this->me($request);

        $data = $request->validate([
            'title'          => 'required|string|max:200',
            'description'    => 'nullable|string|max:2000',
            'category'       => 'nullable|string|max:60',
            'expense_date'   => 'required|date|before_or_equal:today',
            'amount_claimed' => 'required|numeric|min:0.01',
            'files'          => 'nullable|array|max:' . self::MAX_FILES,
            'files.*'        => self::FILE_RULES,
        ]);

        $claim = $this->claims->submit($me, $data, $request->user());

        $this->attach($request, $claim);

        return response()->json([
            'status'  => 'success',
            'message' => 'Claim submitted.',
            'data'    => $claim->fresh()->load('attachments'),
        ], 201);
    }

    public function show(Request $request, int $id)
    {
        $claim = $this->own($request, $id);

        return response()->json([
            'status' => 'success',
            'data'   => [
                'claim'    => $claim->load('attachments'),
                'thread'   => $this->thread->forSubject($claim, asEmployee: true),
                // Derived on the server so the app and the CRM cannot disagree
                // about which buttons an employee should see.
                'can'      => [
                    'reply'           => ! $claim->isDecided(),
                    'accept_proposal' => $claim->can_accept_proposal,
                ],
            ],
        ]);
    }

    /** Answering a hold. This is what clears it. */
    public function reply(Request $request, int $id)
    {
        $claim = $this->own($request, $id);

        $data = $request->validate([
            'body'    => 'required|string|max:2000',
            'files'   => 'nullable|array|max:' . self::MAX_FILES,
            'files.*' => self::FILE_RULES,
        ]);

        $claim = $this->claims->reply($claim, $request->user(), $data['body']);

        // Attached to the CLAIM, not the message: a replaced receipt then sits
        // beside the original in one list rather than being buried inside a
        // thread entry, which is what makes "what did they first send us" answerable.
        $this->attach($request, $claim);

        return response()->json([
            'status'  => 'success',
            'message' => 'Sent.',
            'data'    => ['claim' => $claim->fresh(), 'thread' => $this->thread->forSubject($claim, asEmployee: true)],
        ]);
    }

    /** Taking the amount the admin proposed. */
    public function accept(Request $request, int $id)
    {
        $claim = $this->claims->acceptProposal($this->own($request, $id), $request->user());

        return response()->json([
            'status'  => 'success',
            'message' => 'Accepted. The claim has been approved at that amount.',
            'data'    => ['claim' => $claim, 'thread' => $this->thread->forSubject($claim, asEmployee: true)],
        ]);
    }

    /**
     * The bytes of one receipt on one of the caller's own claims.
     *
     * Attachment hides `path` and `disk`, and has no url accessor, so a stored
     * file is only ever reachable through a route like this one — which is what
     * keeps a guessed path from returning somebody else's receipt. The claim is
     * resolved through own() first, so the ownership check is the same one the
     * rest of this controller uses.
     */
    public function attachment(Request $request, int $id, int $attachmentId)
    {
        $claim = $this->own($request, $id);

        $file = $claim->attachments()->findOrFail($attachmentId);

        $f = $this->attachments->download($file);

        return response()->download($f['path'], $f['filename'], ['Content-Type' => $f['mime']]);
    }

    private function me(Request $request): HrEmployee
    {
        $employee = $this->identity->employeeFor($request->user());

        abort_unless($employee, 403, 'Your login is not linked to an employee record. Contact HR.');

        return $employee;
    }

    /**
     * A claim of the caller's own, or nothing.
     *
     * Scoped by employee as well as tenant, so a guessed id returns 404 rather
     * than another person's expenses.
     */
    private function own(Request $request, int $id): HrReimbursement
    {
        $me = $this->me($request);

        return HrReimbursement::where('tenant_id', $me->tenant_id)
            ->where('employee_id', $me->id)
            ->findOrFail($id);
    }

    private function attach(Request $request, HrReimbursement $claim): void
    {
        foreach ((array) $request->file('files', []) as $file) {
            $this->attachments->upload(
                HrReimbursement::class, $claim->id, (int) $claim->tenant_id,
                $file, null, [], $request->user()
            );
        }
    }
}
