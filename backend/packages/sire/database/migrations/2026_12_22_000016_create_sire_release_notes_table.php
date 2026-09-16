<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SIRE — release notes, generated from structured issue data.
 *
 * One row per (release, audience). The generated body is stored rather than
 * rendered on demand, because a published note must say what it said when it was
 * approved — reopening an issue afterwards must not silently rewrite history that
 * customers have already read.
 *
 * `sections` holds the structured generation; `body_override` holds human edits.
 * Both are kept so "regenerate" can refresh the structure without discarding the
 * wording someone worked on.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sire_release_notes')) {
            return;
        }

        Schema::create('sire_release_notes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('release_id');

            $table->string('audience', 16);   // internal | user
            $table->string('status', 24)->default('draft'); // draft | pending_approval | approved | published

            $table->string('title', 255)->nullable();
            $table->json('sections')->nullable();       // structured generation
            $table->longText('body_override')->nullable(); // human edits, if any
            $table->dateTime('generated_at')->nullable();
            $table->unsignedInteger('issue_count')->default(0);

            $table->unsignedBigInteger('approved_by')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->unsignedBigInteger('published_by')->nullable();
            $table->dateTime('published_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
            $table->unique(['tenant_id', 'release_id', 'audience'], 'sire_rn_release_audience_uq');
            $table->index(['tenant_id', 'status'], 'sire_rn_tenant_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sire_release_notes');
    }
};
