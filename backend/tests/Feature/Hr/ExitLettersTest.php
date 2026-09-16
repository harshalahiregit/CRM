<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeSalary;
use App\Models\Hr\HrExitClearance;
use App\Models\Hr\HrExitRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\LetterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Relieving, experience and salary revision letters.
 *
 * The exit PROCESS was complete — request, approval, clearance, settlement,
 * interview — and produced no document, so the one artefact a departing person
 * actually needs had to be typed by hand.
 *
 * What is asserted here is mostly REFUSAL, because these letters leave the
 * building. A relieving letter states that somebody has been released and their
 * dues are settled; issuing one before clearance means putting that in writing
 * to a third party who will rely on it, and no later edit un-sends the PDF.
 */
class ExitLettersTest extends TestCase
{
    use RefreshDatabase;

    private int $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'Summit', 'slug' => 'letters', 'status' => 'active']);
        $this->tenantId = $tenant->id;

        Sanctum::actingAs(User::create([
            'tenant_id' => $tenant->id, 'name' => 'HR', 'email' => 'hr@letters.test',
            'password' => Hash::make('x'), 'role' => 'admin', 'status' => 'active',
        ]));
    }

    private function person(string $code = 'SD01', string $joined = '2021-04-01'): HrEmployee
    {
        return HrEmployee::create([
            'tenant_id' => $this->tenantId, 'employee_code' => $code, 'name' => 'Priya Sharma',
            'department' => 'Operations', 'designation' => 'Senior Analyst',
            'joining_date' => $joined, 'status' => 'Active',
        ]);
    }

    private function exit(HrEmployee $e, string $status, string $lwd = '2026-06-30'): HrExitRequest
    {
        // `exit_type_id` is NOT NULL — an exit always has a kind, because the
        // kind decides whether notice, clearance and an interview are required.
        $type = \App\Models\Hr\HrExitType::firstOrCreate(
            ['tenant_id' => $this->tenantId, 'code' => 'RES'],
            ['name' => 'Resignation', 'notice_required' => true, 'default_notice_days' => 60,
             'clearance_required' => true, 'fnf_required' => true, 'is_active' => true]
        );

        return HrExitRequest::create([
            'tenant_id' => $this->tenantId, 'employee_id' => $e->id,
            'exit_type_id' => $type->id,
            'request_date' => '2026-05-01', 'last_working_date' => $lwd,
            'reason' => 'Personal', 'status' => $status,
        ]);
    }

    private function clearance(HrEmployee $e, HrExitRequest $exit, string $status): void
    {
        HrExitClearance::create([
            'tenant_id' => $this->tenantId, 'exit_request_id' => $exit->id,
            'employee_id' => $e->id, 'status' => $status,
        ]);
    }

    private function svc(): LetterService
    {
        return app(LetterService::class);
    }

    private function letter(HrEmployee $e, string $type)
    {
        return $this->getJson("/api/hr/employees/{$e->id}/letters/{$type}");
    }

    /* ── Relieving ────────────────────────────────────────────────────── */

    public function test_a_relieving_letter_needs_an_approved_exit(): void
    {
        $e = $this->person();

        $this->letter($e, 'relieving')
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'This employee has no approved exit. Relieving and experience letters certify that somebody has left, so there has to be an approved exit to certify.']);
    }

    /**
     * The one that matters: no relieving letter before clearance.
     *
     * The letter says all dues are settled. Issuing it while a laptop is
     * unreturned puts the company's signature on a statement that is not true.
     */
    public function test_a_relieving_letter_is_refused_until_clearance_is_complete(): void
    {
        $e = $this->person();
        $exit = $this->exit($e, HrExitRequest::APPROVED);
        $this->clearance($e, $exit, HrExitClearance::IN_PROGRESS);

        $this->letter($e, 'relieving')
            ->assertStatus(422)
            ->assertSee('cannot be issued before that is true', escape: false);
    }

    public function test_a_relieving_letter_is_issued_once_clearance_completes(): void
    {
        $e = $this->person();
        $exit = $this->exit($e, HrExitRequest::APPROVED);
        $this->clearance($e, $exit, HrExitClearance::COMPLETED);

        $res = $this->letter($e, 'relieving')->assertOk();

        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('relieving_letter_SD01.pdf', $res->headers->get('Content-Disposition'));
        // A real PDF, not an error page rendered with a 200.
        $this->assertStringStartsWith('%PDF', $res->getContent());
    }

    /* ── Experience ───────────────────────────────────────────────────── */

    /** An experience letter dated before somebody left says they left early. */
    public function test_an_experience_letter_is_refused_before_the_last_working_day(): void
    {
        $e = $this->person();
        $this->exit($e, HrExitRequest::APPROVED, lwd: now()->addMonth()->toDateString());

        $this->letter($e, 'experience')
            ->assertStatus(422)
            ->assertSee('cannot be dated before somebody has actually left', escape: false);
    }

    public function test_an_experience_letter_states_the_service_period(): void
    {
        $e = $this->person(joined: '2021-04-01');
        $this->exit($e, HrExitRequest::APPROVED, lwd: '2026-06-30');

        $letter = $this->svc()->generate('experience', $e, $this->tenantId)['letter'];

        $this->assertSame('01 April 2021', $letter['rows']['Date of Joining']);
        $this->assertSame('30 June 2026', $letter['rows']['Date of Leaving']);
        // Stated the way a certificate states it, not as a day count.
        $this->assertSame('5 years, 2 months', $letter['rows']['Total Service']);
    }

    /** Clearance is NOT required for an experience letter — it certifies dates, not dues. */
    public function test_an_experience_letter_does_not_wait_on_clearance(): void
    {
        $e = $this->person();
        $exit = $this->exit($e, HrExitRequest::APPROVED);
        $this->clearance($e, $exit, HrExitClearance::IN_PROGRESS);

        $this->letter($e, 'experience')->assertOk();
    }

    /* ── Appraisal ────────────────────────────────────────────────────── */

    private function salary(HrEmployee $e, float $ctc, string $from, ?string $reason = null): HrEmployeeSalary
    {
        return HrEmployeeSalary::create([
            'tenant_id' => $this->tenantId, 'employee_id' => $e->id,
            'effective_from' => $from, 'reason' => $reason,
            'annual_ctc' => $ctc, 'monthly_ctc' => $ctc / 12,
            'gross_salary' => $ctc / 12, 'total_benefits' => 0, 'total_deductions' => 0,
            'net_salary' => $ctc / 12, 'status' => HrEmployeeSalary::ACTIVE,
        ]);
    }

    public function test_an_appraisal_letter_needs_something_to_compare_against(): void
    {
        $e = $this->person();
        $this->salary($e, 600000, '2026-04-01');

        $this->letter($e, 'appraisal')
            ->assertStatus(422)
            ->assertSee('no earlier salary on record', escape: false);
    }

    public function test_an_appraisal_letter_states_both_figures_and_the_increase(): void
    {
        $e = $this->person();
        $this->salary($e, 600000, '2025-04-01')->update(['status' => HrEmployeeSalary::INACTIVE]);
        $this->salary($e, 750000, '2026-04-01', 'Annual review');

        $letter = $this->svc()->generate('appraisal', $e, $this->tenantId)['letter'];

        $this->assertSame('₹600,000.00', $letter['rows']['Previous Annual CTC']);
        $this->assertSame('₹750,000.00', $letter['rows']['Revised Annual CTC']);
        $this->assertSame('₹150,000.00 (25%)', $letter['rows']['Increase']);
        $this->assertSame('Annual review', $letter['rows']['Reason']);
    }

    /* ── Availability + isolation ─────────────────────────────────────── */

    /**
     * "Not yet" and "never" look the same on a disabled button.
     *
     * HR needs to know whether they are waiting on clearance or looking at the
     * wrong person, so the reason travels with the flag.
     */
    public function test_availability_explains_why_each_letter_cannot_be_issued(): void
    {
        $e = $this->person();

        $rows = collect($this->getJson("/api/hr/employees/{$e->id}/letters")->assertOk()->json('data'))
            ->keyBy('type');

        $this->assertFalse($rows['relieving']['available']);
        $this->assertStringContainsString('no approved exit', $rows['relieving']['reason']);
        $this->assertFalse($rows['appraisal']['available']);
        $this->assertStringContainsString('no active salary', $rows['appraisal']['reason']);
    }

    public function test_another_tenants_employee_is_not_reachable(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'letters-2', 'status' => 'active']);
        $theirs = HrEmployee::create([
            'tenant_id' => $other->id, 'employee_code' => 'X1', 'name' => 'Someone',
            'department' => 'Ops', 'designation' => 'Staff', 'joining_date' => '2020-01-01', 'status' => 'Active',
        ]);

        $this->getJson("/api/hr/employees/{$theirs->id}/letters/experience")->assertStatus(404);
    }

    public function test_an_unknown_letter_type_is_refused(): void
    {
        $e = $this->person();

        $this->getJson("/api/hr/employees/{$e->id}/letters/promotion")->assertStatus(404);
    }
}
