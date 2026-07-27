<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_attendance', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('employee_id');
            $table->date('date');

            // Shift (kept on the row so historical records are stable even if a
            // shift definition changes later).
            $table->string('shift')->default('General');
            $table->string('shift_start')->nullable();   // "09:00"
            $table->string('shift_end')->nullable();      // "18:00"
            $table->unsignedSmallInteger('grace_period')->default(0); // minutes

            $table->timestamp('check_in')->nullable();
            $table->timestamp('check_out')->nullable();
            $table->timestamp('break_start')->nullable();
            $table->timestamp('break_end')->nullable();

            $table->decimal('working_hours', 5, 2)->nullable();
            $table->decimal('overtime_hours', 5, 2)->nullable();

            // Present, Absent, Late, Half Day, Leave, Holiday, Weekend,
            // Work From Home, Remote — stored as string (no enum CHECK trap).
            $table->string('status')->default('Absent');
            $table->text('remarks')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'employee_id', 'date']);
            $table->index(['tenant_id', 'date']);
            $table->index(['tenant_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_attendance');
    }
};
