<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employee Onboarding module — Sprint 1 foundation.
 *
 * A NEW, isolated entity attached 1:1 to hr_employees (post "Employee Created").
 * It does NOT touch the existing candidate onboarding (hr_onboarding) or the
 * recruitment tables. Multi-tenant (row-level tenant_id), audit via the shared
 * polymorphic audit_logs (no table here). Designed ESS-ready: the future
 * Employee Self Service portal is authenticated (no public token) and resolves
 * the actor through hr_employees.user_id + the section-status submit/verify
 * split + per-task owner_role/visible_to_employee flags — all present now,
 * dormant in Sprint 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── ESS actor link — kept on HrEmployee so the User model never couples
        //    to HR entities. Nullable, additive, changes no existing behaviour.
        if (! Schema::hasColumn('hr_employees', 'user_id')) {
            Schema::table('hr_employees', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable()->after('tenant_id')->index();
            });
        }

        // ── 1. The onboarding process (1:1 with an employee) ──────────────────
        Schema::create('hr_employee_onboardings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->unsignedBigInteger('candidate_id')->nullable();   // soft trace to recruitment
            $table->unsignedBigInteger('onboarding_id')->nullable();  // soft trace to candidate onboarding
            $table->unsignedBigInteger('offer_id')->nullable();       // "Offer Accepted" trace

            $table->string('status')->default('Pending');             // EmployeeOnboardingStatus: Pending|In_Progress|Waiting_Documents|Waiting_HR|Waiting_Manager|Completed|On_Hold
            $table->string('current_stage')->default('Employee_Created'); // EmployeeOnboardingStage (14-step lifecycle)
            $table->unsignedTinyInteger('progress_percent')->default(0);

            $table->unsignedBigInteger('hr_owner_id')->nullable();    // assigned HR (users.id)
            $table->unsignedBigInteger('manager_id')->nullable();     // reporting manager (users.id)
            $table->string('manager_name')->nullable();
            $table->date('joining_date')->nullable();
            $table->date('target_completion_date')->nullable();       // for "Overdue Joining" bucket

            // Lifecycle stamps (one per meaningful stage)
            $table->timestamp('started_at')->nullable();
            $table->timestamp('personal_done_at')->nullable();
            $table->timestamp('education_done_at')->nullable();
            $table->timestamp('experience_done_at')->nullable();
            $table->timestamp('documents_verified_at')->nullable();
            $table->timestamp('compliance_verified_at')->nullable();
            $table->timestamp('bank_done_at')->nullable();
            $table->timestamp('it_asset_done_at')->nullable();
            $table->timestamp('orientation_done_at')->nullable();
            $table->timestamp('training_done_at')->nullable();
            $table->unsignedBigInteger('hr_approved_by')->nullable();
            $table->timestamp('hr_approved_at')->nullable();
            $table->text('hr_remarks')->nullable();
            $table->unsignedBigInteger('manager_approved_by')->nullable();
            $table->timestamp('manager_approved_at')->nullable();
            $table->text('manager_remarks')->nullable();
            $table->timestamp('completed_at')->nullable();

            // ── ESS-ready (dormant in Sprint 1) ──
            $table->boolean('self_service_enabled')->default(false);
            $table->timestamp('ess_invited_at')->nullable();
            $table->timestamp('ess_first_login_at')->nullable();
            $table->timestamp('ess_submitted_at')->nullable();

            $table->timestamps();
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'current_stage']);
            $table->index(['tenant_id', 'employee_id']);
        });

        // ── 2. 1:1 profile — Personal / Contact / Address / Emergency / Bank / Statutory ──
        Schema::create('hr_employee_onboarding_profile', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('onboarding_id')->constrained('hr_employee_onboardings')->cascadeOnDelete();

            // Personal Information
            $table->string('first_name')->nullable();
            $table->string('middle_name')->nullable();
            $table->string('last_name')->nullable();
            $table->date('dob')->nullable();
            $table->string('gender')->nullable();            // Male|Female|Other|Prefer not to say
            $table->string('marital_status')->nullable();    // Single|Married|Other
            $table->string('blood_group')->nullable();
            $table->string('father_name')->nullable();
            $table->string('mother_name')->nullable();
            $table->string('nationality')->nullable();
            $table->string('religion')->nullable();

            // Contact Information
            $table->string('personal_email')->nullable();
            $table->string('official_email')->nullable();
            $table->string('mobile_phone')->nullable();
            $table->string('alternate_phone')->nullable();

            // Address
            $table->text('current_address')->nullable();
            $table->string('current_city')->nullable();
            $table->string('current_state')->nullable();
            $table->string('current_pincode')->nullable();
            $table->string('current_country')->nullable();
            $table->text('permanent_address')->nullable();
            $table->string('permanent_city')->nullable();
            $table->string('permanent_state')->nullable();
            $table->string('permanent_pincode')->nullable();
            $table->string('permanent_country')->nullable();
            $table->boolean('same_as_current')->default(false);

            // Emergency Contact
            $table->string('emergency_name')->nullable();
            $table->string('emergency_relationship')->nullable();
            $table->string('emergency_phone')->nullable();
            $table->string('emergency_alt_phone')->nullable();
            $table->text('emergency_address')->nullable();

            // Bank Details
            $table->string('bank_account_holder_name')->nullable();
            $table->string('bank_account_number')->nullable();
            $table->string('bank_ifsc')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_branch')->nullable();
            $table->string('bank_account_type')->nullable();  // Savings|Current

            // Statutory Compliance (numbers; the scanned documents live in the documents table)
            $table->string('pan_number')->nullable();
            $table->string('aadhaar_number')->nullable();
            $table->string('uan_number')->nullable();
            $table->string('pf_number')->nullable();
            $table->string('esic_number')->nullable();
            $table->string('esic_ip_number')->nullable();
            $table->string('pf_nominee_name')->nullable();
            $table->string('pf_nominee_relation')->nullable();
            $table->boolean('is_international_worker')->default(false);
            $table->boolean('has_previous_pf')->default(false);
            $table->string('tax_regime')->nullable();          // Old|New

            $table->timestamps();
            $table->unique('onboarding_id');
        });

        // ── 3. Education (1:many) ──
        Schema::create('hr_employee_onboarding_education', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('onboarding_id')->constrained('hr_employee_onboardings')->cascadeOnDelete();
            $table->string('degree')->nullable();
            $table->string('specialization')->nullable();
            $table->string('institution')->nullable();
            $table->string('board_university')->nullable();
            $table->string('year_of_passing')->nullable();
            $table->string('percentage_grade')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // ── 4. Employment History (1:many) ──
        Schema::create('hr_employee_onboarding_experience', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('onboarding_id')->constrained('hr_employee_onboardings')->cascadeOnDelete();
            $table->string('company_name')->nullable();
            $table->string('designation')->nullable();
            $table->date('from_date')->nullable();
            $table->date('to_date')->nullable();
            $table->decimal('last_ctc', 14, 2)->nullable();
            $table->string('reason_for_leaving')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // ── 5. Family Details (1:many) ──
        Schema::create('hr_employee_onboarding_family', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('onboarding_id')->constrained('hr_employee_onboardings')->cascadeOnDelete();
            $table->string('member_name')->nullable();
            $table->string('relationship')->nullable();
            $table->date('dob')->nullable();
            $table->string('occupation')->nullable();
            $table->string('contact')->nullable();
            $table->boolean('is_dependent')->default(false);
            $table->boolean('is_nominee')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // ── 6. Assets Allocation + IT hardware (1:many) ──
        Schema::create('hr_employee_onboarding_assets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('onboarding_id')->constrained('hr_employee_onboardings')->cascadeOnDelete();
            $table->string('asset_type')->nullable();          // Laptop|Phone|SIM|ID Card|Access Card|…
            $table->string('asset_tag_serial')->nullable();
            $table->date('issued_date')->nullable();
            $table->string('condition')->nullable();
            $table->date('returned_date')->nullable();
            $table->string('status')->default('Allocated');    // Allocated|Returned|Pending
            $table->text('remarks')->nullable();
            $table->timestamps();
        });

        // ── 7. Universal trackable checklist tasks (Orientation/Training/IT/HR/Manager/General) ──
        Schema::create('hr_employee_onboarding_tasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('onboarding_id')->constrained('hr_employee_onboardings')->cascadeOnDelete();
            $table->string('category');                        // OnboardingTaskCategory
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('Pending');      // Pending|In Progress|Completed|Rejected
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->string('assigned_to_name')->nullable();
            $table->date('due_date')->nullable();
            $table->unsignedBigInteger('completed_by')->nullable();
            $table->string('completed_by_name')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('remarks')->nullable();
            $table->boolean('is_mandatory')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            // ESS-ready flags (dormant in Sprint 1)
            $table->string('source')->default('System');       // System|HR|Manager|Employee
            $table->string('owner_role')->default('HR');       // HR|Manager|Employee|System — who may action it
            $table->boolean('visible_to_employee')->default(false);
            $table->timestamps();
            $table->index(['tenant_id', 'onboarding_id', 'category']);
        });

        // ── 8. Documents — Identity Documents AND task attachments (new table; existing untouched) ──
        Schema::create('hr_employee_onboarding_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('onboarding_id')->constrained('hr_employee_onboardings')->cascadeOnDelete();
            $table->unsignedBigInteger('task_id')->nullable();  // attachment → hr_employee_onboarding_tasks
            $table->string('category')->default('Identity');    // Identity|Compliance|Task|Other
            $table->string('doc_type')->nullable();             // aadhaar|pan|passport|…
            $table->string('doc_number')->nullable();
            $table->string('original_name')->nullable();
            $table->string('path')->nullable();
            $table->unsignedInteger('size_kb')->default(0);
            $table->string('mime')->nullable();
            $table->string('status')->default('Pending');       // Pending|Verified|Rejected
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->text('remarks')->nullable();
            // ESS-ready
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->string('source')->default('HR');            // HR|Employee|System
            $table->timestamps();
            $table->index(['tenant_id', 'onboarding_id', 'category']);
        });

        // ── 9. Per-section submit → verify status (ESS submit/verify split; HR-only now) ──
        Schema::create('hr_employee_onboarding_section_status', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('onboarding_id')->constrained('hr_employee_onboardings')->cascadeOnDelete();
            $table->string('section');                          // personal|education|experience|documents|bank|compliance|assets|orientation|training|checklist|approvals
            $table->string('status')->default('Draft');         // Draft|Submitted|Verified|Rejected
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->unique(['onboarding_id', 'section']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_employee_onboarding_section_status');
        Schema::dropIfExists('hr_employee_onboarding_documents');
        Schema::dropIfExists('hr_employee_onboarding_tasks');
        Schema::dropIfExists('hr_employee_onboarding_assets');
        Schema::dropIfExists('hr_employee_onboarding_family');
        Schema::dropIfExists('hr_employee_onboarding_experience');
        Schema::dropIfExists('hr_employee_onboarding_education');
        Schema::dropIfExists('hr_employee_onboarding_profile');
        Schema::dropIfExists('hr_employee_onboardings');

        if (Schema::hasColumn('hr_employees', 'user_id')) {
            Schema::table('hr_employees', function (Blueprint $table) {
                $table->dropColumn('user_id');
            });
        }
    }
};
