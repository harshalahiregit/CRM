<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Project Meeting + Discussions tabs (owner: Shivam).
 *
 *  project_meetings     — Kickoff meetings: mode/participants/dates, status,
 *                         "Minutes of Meeting sent" flag. Participants are free
 *                         text (there is no vendor/TPV module to reference).
 *  project_discussions  — a discussion thread on a project.
 *  discussion_comments  — replies under a discussion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_meetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('title');
            $table->string('mode', 20)->default('online');   // online | offline | hybrid
            $table->text('participants')->nullable();         // comma-separated names (free text)
            $table->dateTime('planned_date')->nullable();
            $table->dateTime('meeting_date')->nullable();
            $table->string('status', 20)->default('pending'); // pending | completed
            $table->boolean('mom_sent')->default(false);      // Minutes of Meeting sent
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['tenant_id', 'project_id']);
        });

        Schema::create('project_discussions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('subject');
            $table->text('body')->nullable();
            $table->boolean('visible_to_customer')->default(false);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['tenant_id', 'project_id']);
        });

        Schema::create('discussion_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('discussion_id')->constrained('project_discussions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->text('content');
            $table->timestamps();

            $table->index(['tenant_id', 'discussion_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discussion_comments');
        Schema::dropIfExists('project_discussions');
        Schema::dropIfExists('project_meetings');
    }
};
