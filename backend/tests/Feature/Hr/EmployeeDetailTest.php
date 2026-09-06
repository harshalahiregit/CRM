<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeDetail;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The extended employee record — personal, bank, identity, statutory.
 *
 * None of this had a home before: onboarding collected a bank account and a UAN
 * from the joiner, the conversion left them on the onboarding row, and an
 * employee added directly had nowhere to put them at all. So payroll ran off a
 * spreadsheet kept beside the CRM.
 */
class EmployeeDetailTest extends TestCase
{
    use RefreshDatabase;

    private HrEmployee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'S', 'slug' => 'emp-detail', 'status' => 'active']);

        $admin = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Admin', 'email' => 'a@detail.test',
            'password' => Hash::make('x'), 'role' => 'admin', 'status' => 'active',
        ]);

        $this->employee = HrEmployee::create([
            'tenant_id' => $tenant->id, 'employee_code' => 'E1', 'name' => 'Ravi',
            'department' => 'Ops', 'designation' => 'Analyst',
            'joining_date' => '2024-01-01', 'status' => 'Active',
        ]);

        Sanctum::actingAs($admin);
    }

    private function saveDetail(array $data)
    {
        return $this->putJson("/api/hr/employees/{$this->employee->id}/detail", $data);
    }

    public function test_every_field_comes_back_even_when_nothing_is_filled_in(): void
    {
        $r = $this->getJson("/api/hr/employees/{$this->employee->id}/detail")->assertOk();

        // The form renders from this. A null payload would mean special-casing
        // every input for a person nobody has filled in yet.
        foreach (['bank_ifsc', 'pan_number', 'emergency_phone', 'permanent_address', 'uan_number'] as $field) {
            $this->assertArrayHasKey($field, $r->json('data'));
            $this->assertNull($r->json("data.{$field}"));
        }
    }

    public function test_the_whole_record_saves_and_reads_back(): void
    {
        $this->saveDetail([
            'father_name'              => 'Suresh',
            'marital_status'           => 'Married',
            'blood_group'              => 'O+',
            'permanent_address'        => '12 MG Road',
            'permanent_pincode'        => '560001',
            'emergency_name'           => 'Meera',
            'emergency_relationship'   => 'Spouse',
            'emergency_phone'          => '9876543210',
            'bank_account_holder_name' => 'Ravi Kumar',
            'bank_account_number'      => '0012345678',
            'bank_ifsc'                => 'HDFC0001234',
            'pan_number'               => 'ABCDE1234F',
            'aadhaar_number'           => '123456789012',
            'uan_number'               => '100200300400',
            'tax_regime'               => 'New',
        ])->assertOk();

        $detail = HrEmployeeDetail::where('employee_id', $this->employee->id)->firstOrFail();

        $this->assertSame('Suresh', $detail->father_name);
        $this->assertSame('Meera', $detail->emergency_name);
        $this->assertSame('HDFC0001234', $detail->bank_ifsc);
        $this->assertSame('New', $detail->tax_regime);
    }

    /**
     * A leading zero is part of the account number, not a formatting artefact.
     * Treated as a number it becomes 12345678 and the salary goes elsewhere.
     */
    public function test_a_bank_account_keeps_its_leading_zeros(): void
    {
        $this->saveDetail(['bank_account_number' => '0012345678'])->assertOk();

        $this->assertSame(
            '0012345678',
            HrEmployeeDetail::where('employee_id', $this->employee->id)->first()->bank_account_number,
        );
    }

    /** An Aadhaar is printed in groups of four; people type it that way. */
    public function test_an_aadhaar_typed_with_spaces_is_accepted_and_stored_as_digits(): void
    {
        $this->saveDetail(['aadhaar_number' => '1234 5678 9012'])->assertOk();

        $this->assertSame(
            '123456789012',
            HrEmployeeDetail::where('employee_id', $this->employee->id)->first()->aadhaar_number,
        );
    }

    public function test_pan_and_ifsc_are_stored_upper_case_however_they_are_typed(): void
    {
        $this->saveDetail(['pan_number' => 'abcde1234f', 'bank_ifsc' => 'hdfc0001234'])->assertOk();

        $detail = HrEmployeeDetail::where('employee_id', $this->employee->id)->first();
        $this->assertSame('ABCDE1234F', $detail->pan_number);
        $this->assertSame('HDFC0001234', $detail->bank_ifsc);
    }

    /** A salary paid to a malformed IFSC bounces; better to refuse it here. */
    public function test_a_wrong_ifsc_or_pan_is_refused(): void
    {
        $this->saveDetail(['bank_ifsc' => 'NOTANIFSC'])->assertStatus(422);
        $this->saveDetail(['pan_number' => '12345ABCDE'])->assertStatus(422);
        $this->saveDetail(['aadhaar_number' => '12345'])->assertStatus(422);
    }

    public function test_saving_twice_updates_one_row_rather_than_making_two(): void
    {
        $this->saveDetail(['father_name' => 'Suresh'])->assertOk();
        $this->saveDetail(['father_name' => 'Suresh Kumar'])->assertOk();

        $this->assertSame(1, HrEmployeeDetail::where('employee_id', $this->employee->id)->count());
        $this->assertSame('Suresh Kumar', $this->employee->fresh()->detail->father_name);
    }

    /** Cleared means absent, not an empty string that reads as "provided". */
    public function test_clearing_a_field_stores_null_not_an_empty_string(): void
    {
        $this->saveDetail(['pf_number' => 'PF/123'])->assertOk();
        $this->saveDetail(['pf_number' => ''])->assertOk();

        $this->assertNull($this->employee->fresh()->detail->pf_number);
    }

    /** Bank accounts must not end up in a log that is read far more widely. */
    public function test_the_audit_trail_records_which_fields_changed_but_not_their_values(): void
    {
        $this->saveDetail(['bank_account_number' => '0012345678', 'pan_number' => 'ABCDE1234F'])->assertOk();

        $audits = \App\Models\AuditLog::where('action', 'Employee Details Updated')->get();
        $this->assertNotEmpty($audits, 'The change was not audited at all.');

        foreach ($audits as $row) {
            $this->assertStringNotContainsString('0012345678', (string) json_encode($row));
            $this->assertStringNotContainsString('ABCDE1234F', (string) json_encode($row));
        }
    }

    /**
     * The form posts EVERY field, including the ones nobody touched.
     *
     * My other tests all sent a handful of keys, so they never exercised what
     * the screen actually sends — and the screen sent null for two NOT NULL
     * boolean columns, which passed validation and then died on the insert.
     * The whole tab could not be saved and no test noticed.
     */
    public function test_the_full_form_payload_saves_including_untouched_fields(): void
    {
        $everyField = collect((new HrEmployeeDetail)->getFillable())
            ->reject(fn ($f) => in_array($f, ['tenant_id', 'employee_id'], true))
            ->mapWithKeys(fn ($f) => [$f => null])
            ->all();

        $this->saveDetail(array_merge($everyField, ['father_name' => 'Suresh']))->assertOk();

        $detail = $this->employee->fresh()->detail;
        $this->assertNotNull($detail, 'The whole tab failed to save.');
        $this->assertSame('Suresh', $detail->father_name);
        $this->assertFalse($detail->is_international_worker, 'An unanswered checkbox must store false, not fail.');
        $this->assertFalse($detail->has_previous_pf);
    }

    /**
     * What the joiner typed on the onboarding form reaches their employee record.
     *
     * It used to stop at the onboarding row: HR then re-typed a bank account and
     * a UAN from a form the person had already completed, which is both wasted
     * work and a second chance to mistype an IFSC.
     */
    public function test_onboarding_answers_are_carried_onto_the_employee(): void
    {
        $profile = (object) [
            'bank_account_number' => '0098765432',
            'bank_ifsc'           => 'ICIC0004321',
            'uan_number'          => '100200300400',
            'emergency_name'      => 'Meera',
            'father_name'         => 'Suresh',
        ];

        app(\App\Services\Hr\EmployeeDetailService::class)
            ->carryFromOnboarding($this->employee, $profile);

        $detail = $this->employee->fresh()->detail;
        $this->assertSame('0098765432', $detail->bank_account_number);
        $this->assertSame('ICIC0004321', $detail->bank_ifsc);
        $this->assertSame('Meera', $detail->emergency_name);
    }

    /** A correction made after the conversion must survive it. */
    public function test_the_carry_fills_blanks_and_never_overwrites_a_correction(): void
    {
        $service = app(\App\Services\Hr\EmployeeDetailService::class);

        // HR fixed the IFSC by hand after the person joined.
        $service->save($this->employee, ['bank_ifsc' => 'HDFC0001234']);

        $service->carryFromOnboarding($this->employee->fresh(), (object) [
            'bank_ifsc'           => 'ICIC0004321',   // the older, wrong one
            'bank_account_number' => '0098765432',    // still blank, so it lands
        ]);

        $detail = $this->employee->fresh()->detail;
        $this->assertSame('HDFC0001234', $detail->bank_ifsc, 'The correction was overwritten by the onboarding answer.');
        $this->assertSame('0098765432', $detail->bank_account_number);
    }

    /**
     * 404, not 403 — the codebase's deliberate choice for cross-tenant access.
     * "Not found" tells an outsider nothing about who exists in another
     * workspace, which "forbidden" does.
     */
    public function test_another_tenants_employee_cannot_be_read_or_written(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'emp-detail-2', 'status' => 'active']);
        $theirs = HrEmployee::create([
            'tenant_id' => $other->id, 'employee_code' => 'X1', 'name' => 'Someone',
            'department' => 'Ops', 'designation' => 'Analyst',
            'joining_date' => '2024-01-01', 'status' => 'Active',
        ]);

        $this->getJson("/api/hr/employees/{$theirs->id}/detail")->assertNotFound();
        $this->putJson("/api/hr/employees/{$theirs->id}/detail", ['father_name' => 'X'])->assertNotFound();
        $this->assertSame(0, HrEmployeeDetail::where('employee_id', $theirs->id)->count());
    }
}
