<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Candidate-facing onboarding (Sprint 2 / Req Doc 3): after a candidate is
 * Selected at the final interview, onboarding starts BEFORE the offer. The
 * candidate submits details + documents via a token portal; HR runs document /
 * background / medical verification and approves before an offer can be made.
 * Additive only — existing HR-checklist columns are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_onboarding', function (Blueprint $table) {
            $table->string('access_token', 64)->nullable()->unique()->after('id');
            $table->json('submission')->nullable()->after('document_checklist'); // personal/address/emergency/education/experience/bank
            $table->boolean('doc_verified')->default(false)->after('submission');
            $table->boolean('background_verified')->default(false)->after('doc_verified');
            $table->boolean('medical_verified')->nullable()->after('background_verified'); // optional
            $table->string('verification_status')->default('Pending')->after('medical_verified'); // Pending|Submitted|Approved|Rejected
            $table->text('verification_notes')->nullable()->after('verification_status');
            $table->text('rejection_reason')->nullable()->after('verification_notes');
            $table->timestamp('invited_at')->nullable()->after('rejection_reason');
            $table->timestamp('submitted_at')->nullable()->after('invited_at');
            $table->timestamp('verified_at')->nullable()->after('submitted_at');
            $table->timestamp('joining_confirmed_at')->nullable()->after('verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('hr_onboarding', function (Blueprint $table) {
            $table->dropColumn([
                'access_token', 'submission', 'doc_verified', 'background_verified', 'medical_verified',
                'verification_status', 'verification_notes', 'rejection_reason',
                'invited_at', 'submitted_at', 'verified_at', 'joining_confirmed_at',
            ]);
        });
    }
};
