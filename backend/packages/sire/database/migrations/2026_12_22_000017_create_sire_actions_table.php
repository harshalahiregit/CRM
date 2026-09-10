<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SIRE — corrective and preventive actions.
 *
 * Attaches to EITHER an issue or a recurrence group. A CAPA raised because the
 * same defect has now happened five times belongs to the pattern, not to the
 * fifth occurrence — and if it belonged to the fifth occurrence it would be closed
 * and forgotten when that issue closed.
 *
 * Exactly one of report_id / recurrence_group_id is set; the service enforces it.
 * A CHECK constraint would express it better, but the test suite runs on SQLite
 * and the production database is MySQL, so the guarantee lives where both agree.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sire_actions')) {
            return;
        }

        Schema::create('sire_actions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');

            $table->unsignedBigInteger('report_id')->nullable();
            $table->unsignedBigInteger('recurrence_group_id')->nullable();

            $table->string('action_type', 16);   // corrective | preventive | containment
            $table->string('title', 255);
            $table->text('description')->nullable();

            $table->unsignedBigInteger('owner_id')->nullable();
            $table->dateTime('due_at')->nullable();

            // open | in_progress | completed | verified | cancelled
            $table->string('status', 24)->default('open');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->text('completion_note')->nullable();

            $table->unsignedBigInteger('verified_by')->nullable();
            $table->dateTime('verified_at')->nullable();
            $table->text('verification_note')->nullable();
            $table->string('effectiveness', 16)->nullable(); // effective | partial | ineffective

            $table->unsignedBigInteger('task_id')->nullable(); // logical -> tasks
            $table->string('reminder_state', 16)->nullable();  // scheduler dedupe

            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
            $table->index(['tenant_id', 'report_id'], 'sire_act_report_idx');
            $table->index(['tenant_id', 'recurrence_group_id'], 'sire_act_group_idx');
            $table->index(['tenant_id', 'status'], 'sire_act_tenant_status_idx');
            $table->index(['tenant_id', 'due_at'], 'sire_act_tenant_due_idx');
            $table->index(['tenant_id', 'owner_id'], 'sire_act_tenant_owner_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sire_actions');
    }
};
