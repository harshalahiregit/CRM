<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The complainant's way back into their own case.
 *
 * A complainant is never a case member — that is the whole shape of the POSH
 * access model — so they cannot be given a membership row and cannot be
 * authenticated as a User. What they get instead is one link to one case, and
 * this table is the record of it.
 *
 * ONLY THE HASH IS STORED. There is no plaintext column, which is a deliberate
 * departure from the existing HR token convention: hr_offers.access_token and
 * hr_onboarding.access_token both keep the raw value, so anybody with read
 * access to those tables holds every candidate's credential. That is a poor
 * trade on an offer letter and an indefensible one on a harassment complaint.
 * The pattern followed here is ProposalOtpService's — sha256 at rest, compared
 * by hash, never recoverable.
 *
 * expires_at is NOT NULL on purpose. The TTL is a tenant setting, and a
 * setting can be saved as 0, as null, or as something that is not a number at
 * all. Making the column non-nullable means a non-expiring token cannot be
 * represented, so a code path that forgets to check fails on the insert rather
 * than quietly minting a credential that never dies.
 *
 * No unique (tenant_id, case_id). A case accumulates tokens over its life —
 * issued, superseded, revoked — and each row is the history of one credential.
 * "At most one LIVE token" is enforced in code, the same way case membership
 * handles the same shape.
 *
 * No foreign key on case_id, matching committee_id on the case and the
 * department string on a clearance item: tenancy is enforced in the service.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_posh_case_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('case_id');

            // sha256 hex of the raw token. The raw value exists in exactly one
            // place for exactly one response, and then nowhere.
            $table->char('token_hash', 64)->unique();

            // For the audit trail, so a read is attributable to something.
            // Never the complainant's name, never the narrative, never the
            // respondent, and never any part of the token itself.
            $table->string('label', 150);

            $table->timestamp('issued_at');
            $table->unsignedBigInteger('issued_by')->nullable();

            // Frozen at issuance. Changing the tenant's TTL later moves no
            // existing token, which is the same snapshot rule the rest of POSH
            // follows: a setting edited today cannot reach into something
            // already handed out.
            $table->timestamp('expires_at');

            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->string('revoked_reason', 255)->nullable();

            // The raw token is shown once. This records that it happened, so a
            // second look can be refused rather than quietly served.
            $table->timestamp('presented_at')->nullable();

            $table->timestamp('last_used_at')->nullable();
            $table->unsignedInteger('use_count')->default(0);

            $table->timestamps();

            $table->index(['tenant_id', 'case_id'], 'hr_posh_token_tenant_case_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_posh_case_tokens');
    }
};
