<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_onboarding', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('candidate_id')->nullable();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('candidate_name');
            $table->string('position');
            $table->date('joining_date');
            $table->string('department')->nullable();
            $table->string('employee_code')->nullable();
            $table->string('reporting_manager_name')->nullable();
            // 6-step checklist
            $table->boolean('step_doc_verification')->default(false);
            $table->boolean('step_joining_confirmed')->default(false);
            $table->boolean('step_emp_id_generated')->default(false);
            $table->boolean('step_dept_assigned')->default(false);
            $table->boolean('step_manager_assigned')->default(false);
            $table->boolean('step_record_created')->default(false);
            $table->enum('status', ['Pending','In Progress','Completed'])->default('Pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_onboarding');
    }
};
