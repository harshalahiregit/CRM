<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Career Portal + multi-channel job publishing.
 *
 * 1. hr_job_postings: on_career_portal flag + career_published_at.
 * 2. hr_candidates: public application fields (CTC, notice period, applied_at).
 * 3. hr_job_publications: a per-channel publication ledger so a job can be
 *    published to the Career Portal today and LinkedIn/Naukri/Indeed/TrulyTalents
 *    later without schema changes.
 *
 * Multi-tenant: every table carries tenant_id (row-level isolation) and is
 * indexed for the per-tenant public portal queries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_job_postings', function (Blueprint $table) {
            if (! Schema::hasColumn('hr_job_postings', 'on_career_portal')) {
                $table->boolean('on_career_portal')->default(false)->after('published_at');
            }
            if (! Schema::hasColumn('hr_job_postings', 'career_published_at')) {
                $table->timestamp('career_published_at')->nullable()->after('on_career_portal');
            }
        });

        Schema::table('hr_candidates', function (Blueprint $table) {
            if (! Schema::hasColumn('hr_candidates', 'current_ctc')) {
                $table->decimal('current_ctc', 12, 2)->nullable();
            }
            if (! Schema::hasColumn('hr_candidates', 'expected_ctc')) {
                $table->decimal('expected_ctc', 12, 2)->nullable();
            }
            if (! Schema::hasColumn('hr_candidates', 'notice_period')) {
                $table->string('notice_period', 60)->nullable();
            }
            if (! Schema::hasColumn('hr_candidates', 'applied_at')) {
                $table->timestamp('applied_at')->nullable();
            }
        });

        if (! Schema::hasTable('hr_job_publications')) {
            Schema::create('hr_job_publications', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable();
                $table->unsignedBigInteger('job_posting_id');
                $table->string('channel');                    // careers, linkedin, naukri, indeed, trulytalents
                $table->string('status')->default('published'); // published, removed, failed
                $table->string('external_ref')->nullable();   // id returned by the external platform
                $table->string('external_url')->nullable();   // public URL on that channel
                $table->timestamp('published_at')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();

                $table->unique(['job_posting_id', 'channel'], 'hr_job_pub_unique');
                $table->index(['tenant_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_job_publications');
        Schema::table('hr_job_postings', function (Blueprint $table) {
            foreach (['on_career_portal', 'career_published_at'] as $c) {
                if (Schema::hasColumn('hr_job_postings', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
        Schema::table('hr_candidates', function (Blueprint $table) {
            foreach (['current_ctc', 'expected_ctc', 'notice_period', 'applied_at'] as $c) {
                if (Schema::hasColumn('hr_candidates', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
