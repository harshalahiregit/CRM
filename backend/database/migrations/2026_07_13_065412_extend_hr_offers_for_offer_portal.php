<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint-2 Offer Letter Portal: a secure token portal where the candidate views
 * the offer, downloads it, and accepts (with an audit fingerprint) / declines /
 * requests clarification. Adds the full status lifecycle timestamps, validity
 * expiry, and the post-acceptance pre-joining checklist. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Relax the status enum → string (Draft/Generated/Sent/Viewed/Accepted/Declined/Expired).
        Schema::table('hr_offers', function (Blueprint $table) {
            $table->string('status')->default('Generated')->change();
        });

        Schema::table('hr_offers', function (Blueprint $table) {
            $table->string('access_token', 64)->nullable()->unique()->after('id');
            // Lifecycle timestamps (sent_at, accepted_at already exist)
            $table->timestamp('generated_at')->nullable()->after('sent_at');
            $table->timestamp('viewed_at')->nullable()->after('generated_at');
            $table->timestamp('declined_at')->nullable()->after('accepted_at');
            $table->timestamp('expired_at')->nullable()->after('declined_at');
            $table->timestamp('joining_confirmed_at')->nullable()->after('expired_at');
            // Acceptance audit fingerprint
            $table->string('accepted_ip')->nullable()->after('joining_confirmed_at');
            $table->string('accepted_device')->nullable()->after('accepted_ip');
            $table->string('accepted_browser')->nullable()->after('accepted_device');
            // Clarification request (candidate → HR)
            $table->text('clarification')->nullable()->after('accepted_browser');
            $table->timestamp('clarification_at')->nullable()->after('clarification');
            // Pre-joining checklist state
            $table->json('pre_joining')->nullable()->after('clarification_at');
        });
    }

    public function down(): void
    {
        Schema::table('hr_offers', function (Blueprint $table) {
            $table->dropColumn([
                'access_token', 'generated_at', 'viewed_at', 'declined_at', 'expired_at',
                'joining_confirmed_at', 'accepted_ip', 'accepted_device', 'accepted_browser',
                'clarification', 'clarification_at', 'pre_joining',
            ]);
        });
    }
};
