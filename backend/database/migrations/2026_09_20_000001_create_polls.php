<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Polls — a shared, polymorphic collaboration object for Shivam's modules.
 *
 * A poll attaches to a context (a task, a ticket or a project) via
 * context_type + context_id, so ONE poll engine serves every editor's "Poll"
 * button without each module owning its own copy. Visibility is delegated to
 * the owning module's assert…Visible() — a poll is seen by whoever can see the
 * thing it hangs on. Votes are one-per-option-per-user; single-choice polls
 * are enforced in the service (a voter's older pick is cleared before the new
 * one lands), multi-choice polls simply allow several rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('polls')) {
            Schema::create('polls', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $t->string('context_type', 20);          // task | ticket | project
                $t->unsignedBigInteger('context_id');
                $t->string('question', 500);
                $t->boolean('allow_multiple')->default(false);
                $t->timestamp('closes_at')->nullable();
                $t->foreignId('created_by')->constrained('users')->cascadeOnDelete();
                $t->timestamps();
                $t->softDeletes();

                $t->index(['tenant_id', 'context_type', 'context_id']);
            });
        }

        if (! Schema::hasTable('poll_options')) {
            Schema::create('poll_options', function (Blueprint $t) {
                $t->id();
                $t->foreignId('poll_id')->constrained('polls')->cascadeOnDelete();
                $t->string('label', 255);
                $t->unsignedSmallInteger('position')->default(0);
                $t->timestamps();

                $t->index('poll_id');
            });
        }

        if (! Schema::hasTable('poll_votes')) {
            Schema::create('poll_votes', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $t->foreignId('poll_id')->constrained('polls')->cascadeOnDelete();
                $t->foreignId('poll_option_id')->constrained('poll_options')->cascadeOnDelete();
                $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $t->timestamps();

                // A user can back a given option only once; multi-choice still lets
                // them hold several rows across DIFFERENT options of the same poll.
                $t->unique(['poll_option_id', 'user_id']);
                $t->index(['poll_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('poll_votes');
        Schema::dropIfExists('poll_options');
        Schema::dropIfExists('polls');
    }
};
