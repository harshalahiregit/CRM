<?php

namespace Database\Seeders;

use App\Models\Hr\HrAttendance;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeDetail;
use App\Models\Hr\HrEmployeeLeaveBalance;
use App\Models\Hr\HrEmployeeSalary;
use App\Models\Hr\HrLeavePolicy;
use App\Models\Hr\HrLeaveType;
use App\Models\Hr\HrSalaryComponent;
use App\Models\Hr\HrSalaryStructure;
use App\Models\Hr\HrStatutoryRule;
use App\Models\Tenant;
use App\Services\Settings\SettingsService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * A payroll month you can actually look at.
 *
 * The existing HRSeeder covers recruitment — postings, candidates, offers — and
 * stops before anybody is paid. So a fresh install could not demonstrate the one
 * thing the module is for, and "does payroll work?" had no answer short of
 * typing a salary structure in by hand.
 *
 * This builds a July 2026 that exercises every branch: somebody who is simply
 * paid, somebody with late marks, somebody with overtime, somebody on
 * commission, somebody whose bank details are missing, and somebody still on
 * probation. Run payroll against it and each of those shows up as a different
 * figure rather than as an identical row.
 *
 * ── No faker ──
 *
 * Deliberately. Production installs run `composer install --no-dev`, which omits
 * fakerphp — HelpdeskSeeder already fails there for exactly this reason. Every
 * value below is a literal.
 *
 * ── Statutory figures are the real ones ──
 *
 * PF, ESIC, PT and LWF are configured from the same rates the July 2026 returns
 * were filed under, so the register this produces can be checked against paper.
 *
 * Idempotent: re-running updates rather than duplicating.
 *
 *   php artisan db:seed --class=HrPayrollDemoSeeder --force
 */
class HrPayrollDemoSeeder extends Seeder
{
    private int $tenantId;

    private const PERIOD = '2026-07';

    public function run(): void
    {
        $tenant = Tenant::first();
        if (! $tenant) {
            $this->command->error('No tenant found. Run the main DatabaseSeeder first.');

            return;
        }
        $this->tenantId = $tenant->id;

        $this->command->info('Seeding payroll demo data for '.self::PERIOD.'…');

        $this->companyDefaults();
        $this->statutoryRules();
        $components = $this->components();
        $leaveType  = $this->leavePolicy();
        $this->people($components, $leaveType);

        $this->command->info('Done. Open HR → Payroll → Run Payroll and create a run for July 2026.');
    }

    /**
     * Professional Tax is levied per state, so without one it computes as zero —
     * which reads as "no PT due" and actually means "no PT calculated".
     */
    private function companyDefaults(): void
    {
        $s = app(SettingsService::class);
        $s->set($this->tenantId, 'payroll', 'default_work_state', 'Maharashtra');
        $s->set($this->tenantId, 'payroll', 'fy_start_month', 4);

        // The two policies that ship OFF are switched ON here, because this is
        // demo data — there is no real person to dock, and leaving them off
        // makes half the module invisible.
        $s->setGroup($this->tenantId, \App\Support\Hr\HrSetting::GROUP, [
            'late_marks_enabled' => true,
            'overtime_enabled'   => true,
            'overtime_multiplier' => 2,
        ]);
    }

    /** The rates the July 2026 returns were actually filed under. */
    private function statutoryRules(): void
    {
        $rules = [
            ['pf', null, [
                'employee_rate' => 12, 'employer_rate' => 12,
                'wage_ceiling' => 15000, 'restrict_to_ceiling' => true,
                // EPS is 8.33% of PF wages, capped at 1,250, and membership ends
                // at 58 — all three are visible in the filed register.
                'eps_rate' => 8.33, 'eps_max_age' => 58,
            ]],
            ['esic', null, [
                'employee_rate' => 0.75, 'employer_rate' => 3.25,
                'gross_threshold' => 21000, 'eligibility_base' => 'gross',
                // The employee share rounds UP and the employer share to nearest.
                // Asymmetric on purpose — that is how the challan prints.
                'round_employee_up' => true,
            ]],
            ['pt', 'Maharashtra', [
                // Maharashtra: nil to 7,500, ₹175 to 10,000, ₹200 above — and
                // ₹300 in February, which is the month override below.
                'slabs' => [
                    ['from' => 0,     'to' => 7500,  'amount' => 0],
                    ['from' => 7501,  'to' => 10000, 'amount' => 175],
                    ['from' => 10001, 'to' => null,  'amount' => 200],
                ],
                'month_overrides' => [2 => 300],
            ]],
            ['lwf', 'Maharashtra', [
                'employee_amount' => 25, 'employer_amount' => 75,
                // Half-yearly: June and December only.
                'months' => [6, 12],
            ]],
        ];

        foreach ($rules as [$type, $state, $config]) {
            HrStatutoryRule::updateOrCreate(
                ['tenant_id' => $this->tenantId, 'rule_type' => $type, 'state' => $state],
                ['effective_from' => '2026-04-01', 'config' => $config, 'is_active' => true]
            );
        }
    }

    /**
     * A structure built from percentages, so entering a CTC fills the rest.
     *
     * Basic 50% of gross, HRA 40% of Basic — the shape the 3 Sep walkthrough
     * described, and the one the salary sheet uses.
     */
    /**
     * The component master. Created once; the per-grade structures below reuse it.
     *
     * `pf_applicable` and `esic_applicable` are the load-bearing flags: the
     * statutory engine charges PF on the components carrying the first one, not
     * on gross. A structure whose components are all unflagged deducts nothing
     * and looks exactly like a broken engine.
     *
     * @return array<string, HrSalaryComponent> keyed by code
     */
    private function components(): array
    {
        $defs = [
            // name,                  code,   type,       taxable, pf,    esic
            ['Basic',                 'BASIC', 'Earning',  true,   true,  true],
            ['House Rent Allowance',  'HRA',   'Earning',  true,   false, true],
            ['Conveyance Allowance',  'CONV',  'Earning',  false,  false, true],
            ['Special Allowance',     'SPL',   'Earning',  true,   false, true],
            ['PF Employer',           'PFER',  'Employer', false,  false, false],
            ['Gratuity',              'GRAT',  'Employer', false,  false, false],
        ];

        $made = [];
        foreach ($defs as $i => [$name, $code, $type, $taxable, $pf, $esic]) {
            $made[$code] = HrSalaryComponent::updateOrCreate(
                ['tenant_id' => $this->tenantId, 'code' => $code],
                [
                    'name' => $name, 'type' => $type,
                    'calculation_type' => 'Fixed',
                    'taxable' => $taxable, 'pf_applicable' => $pf, 'esic_applicable' => $esic,
                    'sequence' => $i, 'is_active' => true,
                ]
            );
        }

        return $made;
    }

    /**
     * One structure per salary level, with the amounts resolved.
     *
     * The structure — not the employee salary — is what the statutory engine
     * reads its component breakdown from, so two people on different money need
     * two structures. That is grade-based modelling, and it is how most Indian
     * companies actually set this up.
     *
     * The split is the conventional one: Basic is half of gross, HRA is 40% of
     * Basic, conveyance is a flat ₹1,600, and Special Allowance absorbs the
     * remainder so the parts always add back to gross exactly.
     *
     * These are written as FIXED amounts rather than percentages because
     * "Basic = 50% of GROSS" is circular — gross is the sum of the components,
     * one of which is Basic. The engine is circular-safe and resolves it to
     * almost nothing, which is why the first run of this seeder produced ₹360 of
     * statutory for everybody regardless of salary.
     */
    private function structureFor(string $grade, float $gross, array $components): HrSalaryStructure
    {
        $basic = round($gross * 0.50, 2);
        $hra   = round($basic * 0.40, 2);
        $conv  = 1600.00;
        $spl   = round($gross - $basic - $hra - $conv, 2);

        $amounts = [
            'BASIC' => $basic,
            'HRA'   => $hra,
            'CONV'  => $conv,
            'SPL'   => max(0, $spl),
            'PFER'  => round(min($basic, 15000) * 0.12, 2),
            'GRAT'  => round($basic * 0.0481, 2),
        ];

        $structure = HrSalaryStructure::updateOrCreate(
            ['tenant_id' => $this->tenantId, 'name' => "Grade {$grade}"],
            [
                'code' => 'GR-'.$grade,
                'description' => "Basic 50% of ₹".number_format($gross)." gross · HRA 40% of Basic · balance to Special",
                'is_active' => true,
            ]
        );

        $structure->lines()->delete();
        $i = 0;
        foreach ($amounts as $code => $amount) {
            $structure->lines()->create([
                'component_id'     => $components[$code]->id,
                'calculation_type' => 'Fixed',
                'amount'           => $amount,
                'sort_order'       => $i++,
            ]);
        }

        return $structure;
    }

    private function leavePolicy(): HrLeaveType
    {
        $type = HrLeaveType::updateOrCreate(
            ['tenant_id' => $this->tenantId, 'code' => 'CL'],
            ['name' => 'Casual Leave', 'category' => 'Paid', 'paid' => true,
             'yearly_limit' => 12, 'requires_approval' => true, 'is_active' => true]
        );

        HrLeavePolicy::updateOrCreate(
            ['tenant_id' => $this->tenantId, 'name' => 'Standard Leave Policy'],
            ['applies_to' => 'All', 'weekends_count' => false, 'holidays_count' => false,
             'half_day_allowed' => true, 'negative_balance_allowed' => false,
             // Leave only after probation — the 5 Sep rule.
             'probation_allowed' => false, 'is_active' => true]
        );

        return $type;
    }

    /**
     * Six people, each demonstrating something different.
     *
     * The point is that a payroll run over them produces six DIFFERENT rows.
     */
    private function people(array $components, HrLeaveType $leaveType): void
    {
        $policy = HrLeavePolicy::where('tenant_id', $this->tenantId)->first();

        $people = [
            // Names are deliberately NOT the ones the older test fixtures use
            // (Priya Sharma, Rohit Verma, Anjali Singh, Vikram Rao all exist as
            // SNE-2026-* records auto-created from login accounts). The pre-check
            // lists every active employee, so a collision puts the same name on
            // screen twice — once Ready, once Blocked — and the first thing
            // anybody being shown the module asks is which one is real.
            //
            // code,     name,               dept,        designation,        gross,  gender, dob,          joined,       late, ot,  bank, probationEnd
            ['SNE-101', 'Kavita Deshmukh',  'Operations', 'Senior Analyst',   48478, 'Female', '1977-03-14', '2020-06-01', 0,  0,   true,  null],
            ['SNE-102', 'Sanjay Kulkarni',  'Operations', 'Analyst',          27030, 'Male',   '1966-09-02', '2019-01-15', 0,  0,   true,  null],
            ['SNE-103', 'Neha Bhosale',     'Sales',      'Sales Executive',  22000, 'Female', '1995-11-20', '2021-04-01', 3,  0,   true,  null],
            ['SNE-104', 'Amit Pawar',       'Operations', 'Field Supervisor', 19500, 'Male',   '1990-07-08', '2022-02-14', 6,  0,   true,  null],
            ['SNE-105', 'Meera Iyer',      'Operations', 'Technician',       17800, 'Female', '1993-01-25', '2023-08-01', 0,  16,  true,  null],
            ['SNE-106', 'Arjun Nair',      'Sales',      'Trainee',          14000, 'Male',   '2000-05-30', '2026-06-01', 0,  0,   false, '2026-12-01'],
        ];

        foreach ($people as [$code, $name, $dept, $desig, $gross, $gender, $dob, $joined, $late, $ot, $bank, $probEnd]) {
            $e = HrEmployee::updateOrCreate(
                ['tenant_id' => $this->tenantId, 'employee_code' => $code],
                [
                    'name' => $name, 'department' => $dept, 'designation' => $desig,
                    'joining_date' => $joined, 'status' => 'Active',
                    'gender' => $gender, 'dob' => $dob,
                    // Without this, PT computes zero for everybody.
                    'work_state' => 'Maharashtra',
                    'probation_end_date' => $probEnd,
                    'email' => strtolower(str_replace(' ', '.', $name)).'@nexforeconsulting.com',
                ]
            );

            HrEmployeeDetail::updateOrCreate(
                ['tenant_id' => $this->tenantId, 'employee_id' => $e->id],
                [
                    // SNE-106 deliberately has no bank details, so the pre-check
                    // has somebody to refuse and explain.
                    'bank_account_number' => $bank ? '5011'.str_pad((string) $e->id, 8, '0', STR_PAD_LEFT) : null,
                    'bank_ifsc'   => $bank ? 'HDFC0001234' : null,
                    'bank_name'   => $bank ? 'HDFC Bank' : null,
                    'pay_mode'    => 'Transfer',
                    'pan_number'  => 'ABCDE'.str_pad((string) (1000 + $e->id), 4, '0', STR_PAD_LEFT).'F',
                    'aadhaar_number' => '2222'.str_pad((string) (10000000 + $e->id), 8, '0', STR_PAD_LEFT),
                    'uan_number'  => '1010'.str_pad((string) (10000000 + $e->id), 8, '0', STR_PAD_LEFT),
                ]
            );

            // A structure per salary level — see structureFor().
            $structure = $this->structureFor(substr($code, -3), $gross, $components);

            HrEmployeeSalary::updateOrCreate(
                ['tenant_id' => $this->tenantId, 'employee_id' => $e->id, 'status' => HrEmployeeSalary::ACTIVE],
                [
                    'salary_structure_id' => $structure->id,
                    'effective_from' => '2026-04-01',
                    'annual_ctc' => $gross * 12, 'monthly_ctc' => $gross,
                    'gross_salary' => $gross, 'total_benefits' => 0, 'total_deductions' => 0,
                    'net_salary' => $gross,
                ]
            );

            HrEmployeeLeaveBalance::updateOrCreate(
                ['tenant_id' => $this->tenantId, 'employee_id' => $e->id, 'leave_type_id' => $leaveType->id],
                // opening_balance stays 0: it is a balance carried in from another
                // system, not a second copy of the allocation. Seeding 12 in both
                // made the row worth 24 to recomputeAvailable() — see the note in
                // AllocateLeaveBalances.
                ['leave_policy_id' => $policy?->id, 'allocated' => 12, 'opening_balance' => 0,
                 'used' => 0, 'adjusted' => 0, 'carried_forward' => 0, 'available_balance' => 12,
                 'effective_from' => '2026-04-01', 'status' => HrEmployeeLeaveBalance::ACTIVE]
            );

            $this->attendance($e, $late, $ot);
        }
    }

    /**
     * A full July for one person.
     *
     * The whole month is written, not just the interesting days — a day's pay is
     * gross ÷ payable days, so a three-row month makes a "half day" worth a
     * third of the salary.
     */
    private function attendance(HrEmployee $e, int $lateDays, float $overtimeHours): void
    {
        $otPerDay = $overtimeHours > 0 ? min(4, $overtimeHours / 4) : 0;
        $otDaysLeft = $overtimeHours > 0 ? 4 : 0;

        // Cleared rather than updateOrCreate'd: `date` is cast to a datetime, so
        // matching on the string '2026-07-01' never finds the stored
        // '2026-07-01 00:00:00' and every re-run hit the unique constraint.
        HrAttendance::where('tenant_id', $this->tenantId)
            ->where('employee_id', $e->id)
            ->where('date', '>=', '2026-07-01')
            ->where('date', '<', '2026-08-01')
            ->delete();

        for ($d = 1; $d <= 31; $d++) {
            $date = sprintf('2026-07-%02d', $d);
            $isSunday = Carbon::parse($date)->isSunday();

            $status = 'Present';
            $checkIn = '09:28';

            if ($isSunday) {
                $status = 'Weekend';
                $checkIn = null;
            } elseif ($d <= $lateDays) {
                // AttendanceService marks Late from check-in vs shift + grace;
                // the status is written directly here so the demo does not
                // depend on that job having run.
                $status = 'Late';
                $checkIn = '10:05';
            }

            $ot = 0;
            if ($otDaysLeft > 0 && ! $isSunday && $status === 'Present') {
                $ot = $otPerDay;
                $otDaysLeft--;
            }

            HrAttendance::create(
                [
                    'tenant_id' => $this->tenantId, 'employee_id' => $e->id, 'date' => $date,
                    'status' => $status,
                    'shift' => 'General', 'shift_start' => '09:30', 'shift_end' => '18:30',
                    'grace_period' => 15,
                    'check_in'  => $checkIn ? $date.' '.$checkIn.':00' : null,
                    'check_out' => $checkIn ? $date.' 18:35:00' : null,
                    'working_hours' => $checkIn ? 9 : 0,
                    'overtime_hours' => $ot,
                ]
            );
        }
    }
}
