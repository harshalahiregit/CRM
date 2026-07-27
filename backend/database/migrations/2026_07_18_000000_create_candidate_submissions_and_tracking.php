<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

/**
 * Recruitment Services — Phase 2 (Client Collaboration).
 *
 * Adds a public client-tracking token to hiring requests and a single new
 * table for candidate deliveries to the client. No existing recruitment
 * table (candidates, interviews, offers, onboarding) is touched — deliveries
 * merely REFERENCE existing candidates, and feedback history rides the
 * existing polymorphic audit_logs via the Auditable trait.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Per-request public tracking token for the client portal.
        Schema::table('hr_hiring_requests', function (Blueprint $table) {
            $table->string('tracking_token', 64)->nullable()->unique()->after('manpower_request_id');
        });

        // Backfill tokens for any pre-existing requests.
        DB::table('hr_hiring_requests')->whereNull('tracking_token')->orderBy('id')
            ->get(['id'])->each(function ($row) {
                DB::table('hr_hiring_requests')->where('id', $row->id)
                    ->update(['tracking_token' => Str::random(48)]);
            });

        // Candidates delivered to a client for a given hiring request.
        Schema::create('hr_candidate_submissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('hiring_request_id')->constrained('hr_hiring_requests')->cascadeOnDelete();
            $table->foreignId('candidate_id')->constrained('hr_candidates')->cascadeOnDelete();
            $table->string('status')->default('Pending Client Review'); // Pending Client Review | Accepted | Rejected | Need More Profiles
            $table->text('recruiter_note')->nullable();
            $table->text('client_feedback')->nullable();  // latest feedback comment (full history is in audit_logs)
            $table->timestamp('feedback_at')->nullable();
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->unique(['hiring_request_id', 'candidate_id']); // a candidate is delivered once per request
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_candidate_submissions');
        Schema::table('hr_hiring_requests', function (Blueprint $table) {
            $table->dropColumn('tracking_token');
        });
    }
};
