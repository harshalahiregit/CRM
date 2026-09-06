<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The rest of a person: who to call, where the salary goes, which numbers the
 * statutory filings need.
 *
 * `hr_employee_onboarding_profile` has collected all of this since onboarding
 * shipped — but it hangs off an ONBOARDING, not an employee. Somebody hired
 * through the onboarding flow has a bank account on record that no employee
 * screen can read; somebody added straight into the Employees screen has nowhere
 * to put one at all. Payroll needs an IFSC and the PF filing needs a UAN, so the
 * information was being kept in a spreadsheet beside the CRM.
 *
 * COLUMN NAMES MIRROR `hr_employee_onboarding_profile` EXACTLY. Carrying an
 * onboarding across to an employee is then a copy of matching keys rather than a
 * mapping table that somebody has to remember to extend — every field renamed in
 * translation is a field that silently stops being carried.
 *
 * A separate table, not more columns on `hr_employees`:
 *   - hr_employees is read on every list, every rollup and every org chart. It
 *     does not need to carry thirty columns nobody on those screens looks at.
 *   - Bank accounts and Aadhaar numbers are the fields most likely to need their
 *     own permission later. Splitting the table now means that becomes a rule on
 *     a relation instead of a migration that moves live PII.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_employee_details')) {
            return;
        }

        Schema::create('hr_employee_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();

            // ── Personal ──────────────────────────────────────────────────────
            // dob and gender stay on hr_employees: the app reads them and lists
            // show them. Duplicating them here would create two answers.
            $table->string('marital_status')->nullable();       // Single|Married|Other
            $table->string('blood_group')->nullable();
            $table->string('father_name')->nullable();
            $table->string('mother_name')->nullable();
            $table->string('spouse_name')->nullable();
            $table->string('nationality')->nullable();
            $table->string('religion')->nullable();
            $table->string('personal_email')->nullable();
            $table->string('alternate_phone')->nullable();

            // ── Address ───────────────────────────────────────────────────────
            // `hr_employees.address` is where they live now; this is the address
            // on their documents, which is what statutory forms ask for.
            $table->text('permanent_address')->nullable();
            $table->string('permanent_city')->nullable();
            $table->string('permanent_state')->nullable();
            $table->string('permanent_pincode')->nullable();
            $table->string('permanent_country')->nullable();

            // ── Education ─────────────────────────────────────────────────────
            // The single highest qualification. Onboarding keeps a full 1:many
            // history in hr_employee_onboarding_education; this is the one line
            // an HR screen actually shows.
            $table->string('highest_qualification')->nullable();
            $table->string('specialization')->nullable();
            $table->string('institution')->nullable();
            $table->string('year_of_passing')->nullable();

            // ── Emergency contact ─────────────────────────────────────────────
            $table->string('emergency_name')->nullable();
            $table->string('emergency_relationship')->nullable();
            $table->string('emergency_phone')->nullable();
            $table->string('emergency_alt_phone')->nullable();
            $table->text('emergency_address')->nullable();

            // ── Bank ──────────────────────────────────────────────────────────
            $table->string('bank_account_holder_name')->nullable();
            $table->string('bank_account_number')->nullable();
            $table->string('bank_ifsc')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_branch')->nullable();
            $table->string('bank_account_type')->nullable();    // Savings|Current

            // ── Identity ──────────────────────────────────────────────────────
            $table->string('pan_number')->nullable();
            $table->string('aadhaar_number')->nullable();
            $table->string('passport_number')->nullable();
            $table->date('passport_expiry')->nullable();
            $table->string('driving_licence_number')->nullable();

            // ── Statutory ─────────────────────────────────────────────────────
            $table->string('uan_number')->nullable();
            $table->string('pf_number')->nullable();
            $table->string('esic_number')->nullable();
            $table->string('esic_ip_number')->nullable();
            $table->string('pf_nominee_name')->nullable();
            $table->string('pf_nominee_relation')->nullable();
            $table->boolean('is_international_worker')->default(false);
            $table->boolean('has_previous_pf')->default(false);
            $table->string('tax_regime')->nullable();           // Old|New

            $table->timestamps();

            // One row per employee. Without this, a double submit gives a person
            // two bank accounts and payroll picks whichever it reads first.
            $table->unique('employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_employee_details');
    }
};
