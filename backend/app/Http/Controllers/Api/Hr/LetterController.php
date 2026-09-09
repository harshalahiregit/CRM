<?php

namespace App\Http\Controllers\Api\Hr;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Hr\HrEmployee;
use App\Services\Hr\LetterService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Relieving, experience and salary revision letters.
 *
 * These leave the building — a relieving letter is read by somebody's next
 * employer — so the guards that decide whether one may be issued live in the
 * service, where they hold however the letter is asked for.
 */
class LetterController extends Controller
{
    public function __construct(private LetterService $letters)
    {
    }

    /** Generate and return the PDF inline. */
    public function download(Request $request, int $employeeId, string $type)
    {
        $this->assertCanManage($request);

        if (! in_array($type, LetterService::TYPES, true)) {
            throw new BusinessException('Unknown letter type.', 404);
        }

        $employee = $this->employee($request, $employeeId);

        $built = $this->letters->download($type, $employee, $this->tenant($request));

        return response($built['contents'], 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$built['filename'].'"',
        ]);
    }

    /**
     * Which letters can be issued for this person right now, and why not.
     *
     * The reason matters more than the flag: "not yet" and "never" look the
     * same on a disabled button, and HR needs to know whether they are waiting
     * on clearance or looking at the wrong employee.
     */
    public function available(Request $request, int $employeeId)
    {
        $employee = $this->employee($request, $employeeId);
        $tenantId = $this->tenant($request);

        $out = [];
        foreach (LetterService::TYPES as $type) {
            try {
                // Built rather than guessed at: the only reliable answer to
                // "can this be issued?" is the same code that issues it.
                $this->letters->generate($type, $employee, $tenantId);
                $out[] = ['type' => $type, 'available' => true, 'reason' => null];
            } catch (BusinessException $e) {
                $out[] = ['type' => $type, 'available' => false, 'reason' => $e->getMessage()];
            }
        }

        return response()->json(['data' => $out]);
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    private function employee(Request $request, int $id): HrEmployee
    {
        $employee = HrEmployee::where('tenant_id', $this->tenant($request))->find($id);

        if (! $employee) {
            throw new BusinessException('Employee not found', 404);
        }

        return $employee;
    }

    private function assertCanManage(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to issue letters');
    }
}
