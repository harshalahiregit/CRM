<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One decision, on one rung. Append-only.
 *
 * This does not replace audit_logs, which 78 services already write to and
 * which stays the human-readable trail. This is the STRUCTURED record the
 * engine queries — "has step 2 already been decided?" — and the reason a
 * duplicate approval can be refused by a unique index rather than a race.
 *
 * Editing a workflow never touches these rows. The history of what was decided
 * survives any change to what would be decided next time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_approval_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('approval_request_id')->constrained('hr_approval_requests')->cascadeOnDelete();

            $table->unsignedInteger('step_order');
            $table->string('step_name', 150)->nullable();

            $table->unsignedBigInteger('actor_id')->nullable();
            // Denormalised so the trail still reads correctly after a user is
            // deleted or renamed. An approval record that says "deleted user"
            // is worth less than one that says who it was at the time.
            $table->string('actor_name', 191)->nullable();
            $table->string('actor_role', 100)->nullable();

            // approved | rejected — the two the engine acts on in v1.
            $table->string('action', 30);
            $table->text('comment')->nullable();

            // Who the engine EXPECTED for this step, kept so a later audit can
            // tell whether the right person acted even after the role changed.
            $table->json('resolved_approver')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'approval_request_id']);

            // A rung is decided once. This is what makes a duplicate approval a
            // constraint violation rather than a check that two concurrent
            // requests can both pass.
            $table->unique(['approval_request_id', 'step_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_approval_actions');
    }
};
