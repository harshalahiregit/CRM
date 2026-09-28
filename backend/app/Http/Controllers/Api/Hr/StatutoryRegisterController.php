<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\HrPayrollRun;
use App\Services\Hr\Payroll\BankAdviceService;
use App\Services\Hr\Payroll\StatutoryRegisterService;
use Illuminate\Http\Request;

/**
 * The PF, ESIC, PT and LWF registers for one payroll run.
 *
 * Separate from PayrollReportController on purpose. Those are analysis — spend by
 * department, trends, a summary somebody reads on screen. These are FILINGS: the
 * figures are typed into a government portal and the layout is the one the
 * accountant already recognises, so they must not drift to suit a dashboard.
 *
 * Gated exactly as PayrollReportController is, and for a stronger reason: these
 * carry more than the analysis does. The bank advice lists every employee's
 * account number, IFSC and net pay, and the PF/ESIC registers carry UAN and
 * insurance numbers. Being inside the tenant was the only thing asked of a
 * caller here, so any authenticated account — including a client or vendor
 * portal login, which is a row in `users` too — could read the payroll of the
 * whole company. The sibling controller handling the LESS sensitive figures had
 * been gated since it shipped; this one was simply missed.
 */
class StatutoryRegisterController extends Controller
{
    public function __construct(
        private StatutoryRegisterService $registers,
        private BankAdviceService $bank,
    ) {
    }

    /**
     * The salary transfer advice, and the CSV a bank portal takes.
     *
     * Anybody who cannot be paid by transfer is returned WITH THE REASON rather
     * than dropped, so the figure on screen reads "42 paid, 3 not" instead of a
     * total nobody can reconcile against headcount.
     */
    public function bankAdvice(Request $request, int $runId)
    {
        $run = $this->run($request, $runId);

        return response()->json(['status' => 'success', 'data' => $this->bank->forRun($run, $request->user())]);
    }

    public function bankAdviceCsv(Request $request, int $runId)
    {
        $run = $this->run($request, $runId);

        $name = sprintf('salary-advice-%02d-%d.csv', $run->payroll_month, $run->payroll_year);

        return response($this->bank->csv($run, $request->user()), 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
        ]);
    }

    public function pf(Request $request, int $runId)
    {
        return $this->register($request, $runId, 'pf');
    }

    public function esic(Request $request, int $runId)
    {
        return $this->register($request, $runId, 'esic');
    }

    public function pt(Request $request, int $runId)
    {
        return $this->register($request, $runId, 'pt');
    }

    public function lwf(Request $request, int $runId)
    {
        return $this->register($request, $runId, 'lwf');
    }

    /**
     * The single door every register goes through.
     *
     * Put here rather than repeated in each of the six methods, because the
     * method somebody adds next is the one that would be forgotten — the same
     * reason JobPostingService gates inside transition() instead of in each
     * caller. bankAdvice() and bankAdviceCsv() call this directly; pf/esic/pt/lwf
     * reach it through register().
     *
     * The gate runs BEFORE the lookup on purpose: refusing after findOrFail
     * would answer 404 for a run that does not exist and 403 for one that does,
     * which tells an unauthorised caller how many payroll runs you have.
     */
    private function run(Request $request, int $runId): HrPayrollRun
    {
        $this->gate($request);

        return HrPayrollRun::where('tenant_id', (int) $request->user()->tenant_id)
            ->findOrFail($runId);
    }

    /** Identical to PayrollReportController::gate — same authority, same wording. */
    private function gate(Request $request): void
    {
        abort_unless(
            $request->user()->canManageHrQueue(),
            403,
            'You are not authorised to view statutory registers'
        );
    }

    private function register(Request $request, int $runId, string $kind)
    {
        $run = $this->run($request, $runId);

        return response()->json([
            'status' => 'success',
            'data'   => [
                'run'      => [
                    'id'     => $run->id,
                    'month'  => $run->payroll_month,
                    'year'   => $run->payroll_year,
                    'status' => $run->status ?? null,
                ],
                // Two questions, answered separately and in order. run() has
                // already asked whether this caller may open a register at all
                // (the gate from the earlier security pass, unchanged). The
                // actor passed here answers the second one — which employees
                // may appear on it.
                'register' => $this->registers->{$kind}($run, $request->user()),
            ],
        ]);
    }
}
