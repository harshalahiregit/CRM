<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The rungs of a ladder, in order.
 *
 * Sequential only in v1: one step is active at a time and the next becomes
 * active when the current one is approved. There is no quorum column and no
 * parallel mode — adding either later is an additive migration, and guessing at
 * their semantics now would bake in the wrong answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_approval_workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('workflow_id')->constrained('hr_approval_workflows')->cascadeOnDelete();

            // 1-based. Gaps are tolerated on read (the engine orders rather than
            // indexes), so removing a middle step does not require renumbering
            // inside the same transaction.
            $table->unsignedInteger('step_order');
            $table->string('name', 150);

            // An ApproverType key. Which of the two refs below is meaningful
            // depends on it, which is why neither is constrained.
            $table->string('approver_type', 40);

            // staff_roles.id for STAFF_ROLE, users.id for SPECIFIC_USER, null
            // for REPORTING_MANAGER. Not a foreign key: a deleted role must
            // leave the step visibly unresolvable in Settings rather than
            // silently deleting the rung out of a configured ladder.
            $table->unsignedBigInteger('approver_ref')->nullable();

            // How far up the reporting line to walk. 1 is the direct manager.
            $table->unsignedTinyInteger('levels_up')->default(1);

            /*
             | Declarative conditions, closed vocabulary, evaluated by
             | ConditionEvaluator against values the SERVER reads from the
             | database — never values posted with the approval.
             |
             | Shape: {"department_id":[3,7],"branch":["Pune"],"min_amount":1000}
             | There is no expression here and nothing is ever eval'd.
             */
            $table->json('conditions')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Named explicitly: the generated name would be 65 characters and
            // MySQL stops at 64, so this passes every SQLite test and aborts
            // migrate on production.
            $table->index(['tenant_id', 'workflow_id', 'step_order'], 'hr_apvl_steps_tenant_wf_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_approval_workflow_steps');
    }
};
