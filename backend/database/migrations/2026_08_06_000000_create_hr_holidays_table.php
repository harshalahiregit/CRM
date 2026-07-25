<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Leave Management — Phase 5 (Holiday Calendar).
 *
 * A single tenant-scoped table. Scope is expressed by applicable_for
 * (Organization | Department | Designation) with nullable department_id /
 * designation_id reused from Organization Setup (hr_departments / hr_designations)
 * — no duplication. Duplicate holidays (same date + scope) are blocked in the
 * service, since nullable scope columns make a portable unique index unreliable.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_holidays', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('holiday_date')->index();
            $table->string('holiday_type');                 // National | Festival | Company | Optional
            $table->string('applicable_for')->default('Organization'); // Organization | Department | Designation
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('designation_id')->nullable();
            $table->boolean('is_optional')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'holiday_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_holidays');
    }
};
