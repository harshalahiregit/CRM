<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\HrPayrollRun;
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
    public function __construct(private StatutoryRegisterService $registers)
    {
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

    private function register(Request $request, int $runId, string $kind)
    {
        $run = HrPayrollRun::where('tenant_id', (int) $request->user()->tenant_id)
            ->findOrFail($runId);

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
