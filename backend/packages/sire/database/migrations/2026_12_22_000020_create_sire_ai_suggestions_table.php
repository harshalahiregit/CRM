<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SIRE AI — the suggestion store.
 *
 * One table. The AI layer writes here and nowhere else, which is how "AI must
 * never overwrite original issue data" becomes a structural property rather than
 * a rule someone has to remember.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sire_ai_suggestions')) {
            return;
        }

        Schema::create('sire_ai_suggestions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');   // NOT NULL by design

            // Polymorphic within SIRE: report | recurrence_group | release | tenant.
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id')->nullable();

            $table->string('capability', 48);
            $table->string('status', 16)->default('pending');

            // The suggestion itself. Shape varies by capability, which is why it
            // is json — but it is never merged into the subject.
            $table->json('payload')->nullable();

            // Requirement 6. Nullable because "no opinion on confidence" is
            // honest, and a default of 1.0 would be a lie told by a schema.
            $table->decimal('confidence', 4, 3)->nullable();

            // Requirement 7. {summary, signals[], references[]} — why it thinks so.
            // A recommendation nobody can interrogate is not usable evidence.
            $table->json('evidence')->nullable();

            // Requirement 5. Which thing said this, so an old suggestion can be
            // read in the light of the model that made it.
            $table->string('provider', 48)->nullable();
            $table->string('model', 96)->nullable();
            $table->string('model_version', 48)->nullable();

            // What was actually sent, and what was withheld. Requirements 10-12 are
            // auditable rather than merely asserted.
            $table->json('redaction_report')->nullable();
            $table->string('context_fingerprint', 64)->nullable();

            // Requirement 8. accept / reject / modify, with who and when.
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->string('decision_note', 2000)->nullable();
            // What the human applied instead. The gap between this and `payload`
            // is the signal worth having.
            $table->json('final_value')->nullable();

            $table->timestamps();

            $table->index('tenant_id');
            $table->index(['tenant_id', 'subject_type', 'subject_id'], 'sire_ai_subject_idx');
            $table->index(['tenant_id', 'capability', 'status'], 'sire_ai_capability_idx');
            $table->index(['tenant_id', 'status'], 'sire_ai_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sire_ai_suggestions');
    }
};
