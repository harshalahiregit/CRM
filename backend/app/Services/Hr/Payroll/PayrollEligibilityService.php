<?php

namespace App\Services\Hr\Payroll;

use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeSalary;
use App\Support\Hr\WorkStates;
use App\Services\Settings\SettingsService;

/**
 * The pre-check: who can be paid this month, and what is stopping the rest.
 *
 * Payroll used to answer this by silence. `process()` selected every employee
 * with an active salary and anybody else simply did not appear — no row, no
 * warning, no difference between "this person is not due a salary" and "this
 * person's bank details are missing so we could not pay them". The brief was
 * explicit about the difference:
 *
 *     "अगर किसी कंसर्न पर्सन का बैंक डिटेल मिसिंग है या कोई मैंडेट इन मिसिंग है
 *      तो वहां पर बता देगा — प्लीज कंप्लीट दिस यूजर्स प्रोफाइल फर्स्ट"
 *
 * ── Why these five checks and not others ──
 *
 * Each one is a reason the money cannot move, not a reason the record is untidy.
 * A missing blood group does not stop a transfer; a missing IFSC does.
 *
 *   active salary   — nothing to compute from
 *   bank details    — where would it go
 *   PAN             — TDS is filed against it; a wrong or absent PAN means the
 *                     deduction cannot be credited to the employee and they pay
 *                     the tax twice
 *   Aadhaar         — required for the PF/ESIC filings the run produces
 *   work state      — PT is levied by a state. Without one the engine returns
 *                     zero, which LOOKS like "no PT due" and is actually
 *                     "no PT calculated". This is currently true of every
 *                     employee in the system, which is why PT reads ₹0.
 *
 * ── Which of these BLOCK is a setting, not a decision made here ──
 *
 * Whether a missing Aadhaar should hold up a month is a judgement about the
 * business, not about the code: it spoils a filing, it does not stop a
 * transfer. Baking the answer in means a workspace either tolerates bad filings
 * or cannot pay anybody, with no way to choose — and a control nobody can
 * satisfy gets worked around rather than met.
 *
 * So each requirement is switchable under Settings → Payroll. The defaults are
 * the conservative reading (bank and PAN block, Aadhaar and work state warn),
 * and anything switched off still reports as a warning rather than vanishing —
 * a requirement nobody is enforcing is still worth seeing.
 */
class PayrollEligibilityService
{
    public function __construct(private SettingsService $settings)
    {
    }

    /**
     * Every active employee with their readiness for this run.
     *
     * @return array<int, array> one entry per employee, blocked or not
     */
    public function assess(int $tenantId): array
    {
        $employees = HrEmployee::where('tenant_id', $tenantId)
            ->where('status', 'Active')
            ->with(['detail'])
            ->orderBy('name')
            ->get();

        $salaries = HrEmployeeSalary::where('tenant_id', $tenantId)
            ->where('status', HrEmployeeSalary::ACTIVE)
            ->pluck('id', 'employee_id');

        $companyState = WorkStates::normalize(
            $this->settings->get($tenantId, 'payroll', 'default_work_state')
        );

        // Which requirements hold up a run, and which merely report. See the
        // docblock: this is a judgement about the business, so it is theirs.
        $req = [
            'bank'       => (bool) $this->settings->get($tenantId, 'payroll', 'require_bank_for_payroll', true),
            'pan'        => (bool) $this->settings->get($tenantId, 'payroll', 'require_pan_for_payroll', true),
            'aadhaar'    => (bool) $this->settings->get($tenantId, 'payroll', 'require_aadhaar_for_payroll', false),
            'work_state' => (bool) $this->settings->get($tenantId, 'payroll', 'require_work_state_for_payroll', false),
        ];

        return $employees->map(function (HrEmployee $e) use ($salaries, $companyState, $req) {
            $d = $e->detail;

            $blocks = [];
            $warnings = [];

            // Routed through one helper so a requirement switched off still
            // REPORTS rather than disappearing — something nobody is enforcing
            // is still worth seeing on the screen.
            $flag = function (bool $required, string $message) use (&$blocks, &$warnings) {
                $required ? $blocks[] = $message : $warnings[] = $message;
            };

            if (! $salaries->has($e->id)) {
                // Never optional: there is nothing to compute from.
                $blocks[] = 'No active salary structure assigned';
            }

            // Somebody on hold is deliberately excluded, not broken — it is a
            // decision HR already made, so it reads as a block with a reason
            // rather than an error to go and fix.
            if ($e->hold_salary) {
                $blocks[] = 'Salary is on hold';
            }

            // Blacklisted is a stronger statement than deactivated and is never
            // a warning: it is a decision that this person is not re-engaged.
            if ($e->blacklisted) {
                $blocks[] = 'Employee is blacklisted'
                    .($e->blacklist_reason ? ' — '.$e->blacklist_reason : '');
            }

            $payMode = $d?->pay_mode ?: 'Transfer';
            if ($payMode === 'Transfer') {
                if (! $d?->bank_account_number) {
                    $flag($req['bank'], 'No bank account number');
                } elseif (! $d?->bank_ifsc) {
                    $flag($req['bank'], 'No IFSC code');
                }
            }

            if (! $d?->pan_number) {
                $flag($req['pan'], 'PAN not on record');
            }

            if (! $d?->aadhaar_number) {
                $flag($req['aadhaar'], 'Aadhaar not on record — PF and ESIC filings need it');
            }

            if (! WorkStates::normalize($e->work_state) && ! $companyState) {
                $flag($req['work_state'], 'Work state not set — Professional Tax will compute as zero');
            }

            return [
                'employee_id'    => $e->id,
                'employee_code'  => $e->employee_code,
                'name'           => $e->name,
                'department'     => $e->department,
                'designation'    => $e->designation,
                'pay_mode'       => $payMode,
                'blocked'        => $blocks !== [],
                // One reason is what the run row stores; the full list is what
                // the screen shows, so HR fixes everything in one pass instead
                // of rediscovering the next problem on the next attempt.
                'blocked_reason' => $blocks[0] ?? null,
                'blocks'         => $blocks,
                'warnings'       => $warnings,
            ];
        })->all();
    }

    /**
     * Headline counts for the pre-check card.
     *
     * @param  array  $assessment  the output of assess()
     */
    public function summarise(array $assessment): array
    {
        $blocked = array_filter($assessment, fn ($a) => $a['blocked']);
        $warned  = array_filter($assessment, fn ($a) => ! $a['blocked'] && $a['warnings'] !== []);

        return [
            'total'    => count($assessment),
            'ready'    => count($assessment) - count($blocked),
            'blocked'  => count($blocked),
            'warnings' => count($warned),
        ];
    }
}
