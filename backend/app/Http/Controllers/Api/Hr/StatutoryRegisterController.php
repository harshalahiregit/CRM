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

        return response()->json(['status' => 'success', 'data' => $this->bank->forRun($run)]);
    }

    public function bankAdviceCsv(Request $request, int $runId)
    {
        $run = $this->run($request, $runId);

        $name = sprintf('salary-advice-%02d-%d.csv', $run->payroll_month, $run->payroll_year);

        return response($this->bank->csv($run), 200, [
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

    private function run(Request $request, int $runId): HrPayrollRun
    {
        return HrPayrollRun::where('tenant_id', (int) $request->user()->tenant_id)
            ->findOrFail($runId);
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
                'register' => $this->registers->{$kind}($run),
            ],
        ]);
    }
}
