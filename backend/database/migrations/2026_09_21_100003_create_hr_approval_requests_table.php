<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One approval in progress, against one business record.
 *
 * Created lazily — the first time somebody tries to decide a record, not when
 * the record is made. That is what lets existing pending leave carry on
 * working: nothing is backfilled, no status is rewritten, and a record from
 * before this engine existed behaves exactly as it did until it is next touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_approval_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // Polymorphic, so one table serves every process.
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');

            // Denormalised so a queue ("everything waiting on me") is one index
            // hit rather than a join per process.
            $table->string('process', 60);

            /*
             | WHOSE record this is.
             |
             | This is the column ScopeResolver scopes on, and it is the reason
             | being named on a step grants no data access: the scope check runs
             | against this employee independently of who the step resolved to.
             */
            $table->unsignedBigInteger('employee_id')->nullable();

            $table->unsignedBigInteger('workflow_id')->nullable();
            $table->unsignedInteger('workflow_version')->nullable();

            /*
             | THE SNAPSHOT — the whole point of this table.
             |
             | The resolved steps, copied at creation. Once a request exists, this
             | is authoritative: editing the workflow afterwards cannot reroute an
             | approval somebody is already halfway through.
             |
             | AdvanceTierService has the opposite behaviour today — it recomputes
             | its ladder from live settings on every call, so changing an amount
             | limit silently rewrites an in-flight advance. This column is the
             | fix, and the reason the snapshot is stored rather than recomputed.
             */
            $table->json('steps_snapshot');

            $table->unsignedInteger('current_step')->default(1);
            $table->string('state', 20)->default('pending');

            // What the amount conditions were judged against, kept for audit.
            // Null for a process with no money on it, such as leave.
            $table->decimal('amount', 15, 2)->nullable();

            // Why a request is blocked, in words an administrator can act on.
            $table->string('blocked_reason', 255)->nullable();

            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'process', 'state']);
            $table->index(['subject_type', 'subject_id']);
            $table->index(['tenant_id', 'employee_id']);

            // One live request per record. Re-deciding a record reuses its row
            // rather than opening a second ladder beside the first.
            $table->unique(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_approval_requests');
    }
};
