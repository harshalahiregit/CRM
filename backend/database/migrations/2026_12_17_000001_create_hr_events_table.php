<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company events — a townhall, a party, a training day.
 *
 * Distinct from a holiday: an event is something happening, not a day off. The
 * attendance app has always drawn the two separately (its calendar legend reads
 * "Event · Holiday · Optional" and it keeps two lists), but the CRM had nowhere
 * to store an event, so /api/Hrm/events was answered from hr_holidays. That put
 * every holiday in BOTH lists — counted twice on the day marker and listed again
 * under "Events" — and left the Event chip permanently unfillable.
 *
 * Scoping mirrors hr_holidays exactly (Organization / Department / Designation)
 * so one rule covers both, and an event spans dates because a two-day offsite is
 * the normal case.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();

            $table->string('title', 150);
            $table->text('description')->nullable();

            $table->date('start_date');
            // Nullable: a single-day event should not force the person adding it
            // to type the same date twice. Readers fall back to start_date.
            $table->date('end_date')->nullable();

            // Carried to the app as-is; it draws the dot in this colour.
            $table->string('color', 20)->default('#7C3AED');

            $table->string('applicable_for', 20)->default('Organization');
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('designation_id')->nullable();

            $table->boolean('is_active')->default(true);

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            // Named explicitly and kept short — MySQL rejects an identifier over
            // 64 characters and SQLite does not, so a generated name would pass
            // locally and fail only on deploy.
            $table->index(['tenant_id', 'start_date'], 'hr_events_tenant_start_idx');
            $table->index(['tenant_id', 'is_active'], 'hr_events_tenant_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_events');
    }
};
