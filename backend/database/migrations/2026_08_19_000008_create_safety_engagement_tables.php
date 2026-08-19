<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proactive safety engagement (Doc_4 Phase 5/6): behaviour-based safety
 * OBSERVATIONS (unsafe acts/conditions, positive spots) and TOOLBOX TALKS
 * (pre-work micro-training). Both are dated field records tied to a vendor/
 * location, the leading indicators that sit alongside incidents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('safety_observations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('vendor_id')->nullable()->index();
            $table->unsignedBigInteger('observed_by')->nullable();
            $table->string('category');                    // Unsafe_Act | Unsafe_Condition | Positive | Near_Miss
            $table->string('severity')->default('Low');    // Low | Medium | High
            $table->dateTime('observed_at')->nullable();
            $table->string('location')->nullable();
            $table->text('description');
            $table->text('action_taken')->nullable();
            $table->string('status')->default('Open');      // Open | Closed
            $table->dateTime('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('toolbox_talks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('vendor_id')->nullable()->index();
            $table->unsignedBigInteger('conducted_by')->nullable();
            $table->string('topic');
            $table->dateTime('held_at')->nullable();
            $table->string('location')->nullable();
            $table->unsignedInteger('attendee_count')->default(0);
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('toolbox_talks');
        Schema::dropIfExists('safety_observations');
    }
};
