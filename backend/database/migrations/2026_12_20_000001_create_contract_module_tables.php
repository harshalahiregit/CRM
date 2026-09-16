<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Contract module — its own tables, owned by nobody else.
 *
 * Sales, Purchase and TPV each already carry a contract feature of their own
 * (sales_contracts, purchase_contracts, tpv_contracts). Those are left exactly
 * as they are. This module is a separate, company-wide one that stands beside
 * them and LINKS to their records rather than rewriting them, which is why the
 * counterparty here is a morph pair rather than a client_id: one contract table
 * that can be with a customer, a TPV vendor or a purchase vendor without
 * borrowing a column from any of their modules.
 *
 * Table names are deliberately distinct — `contract_types` and
 * `contract_comments` already belong to Sales, so this module uses
 * `contract_categories` and `contract_discussions`. Two modules sharing a table
 * name is how one of them ends up reading the other's rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── The catalogue of contract kinds, creatable inline from the form ──
        Schema::create('contract_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('name', 120);
            $table->string('description')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Two "Service Agreement" rows in one tenant is a picker with the
            // same word twice and no way to tell which is which.
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('reference_no', 40)->nullable()->index();

            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('contract_category_id')->nullable()
                ->constrained('contract_categories')->nullOnDelete();

            // ── The counterparty ────────────────────────────────────────
            // A morph pair, so this one table serves a customer, a TPV vendor and
            // a purchase vendor. Nullable so a contract can be drafted before the
            // other side is chosen. The typed name is kept beside it because a
            // contract must still print correctly if the linked record is later
            // renamed or removed — the agreement was with the name on the page.
            $table->nullableMorphs('party');
            $table->string('party_name')->nullable();
            $table->string('party_email')->nullable();

            $table->decimal('value', 15, 2)->nullable();
            $table->string('currency', 8)->default('INR');

            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            // Renewal tracking: how many days before end_date to warn, and
            // whether that warning has already gone out (so a nightly sweep
            // cannot send it every night).
            $table->unsignedSmallInteger('renewal_notice_days')->default(30);
            $table->timestamp('renewal_reminder_sent_at')->nullable();
            $table->foreignId('renewed_from_id')->nullable()
                ->constrained('contracts')->nullOnDelete();

            // draft | sent | signed | active | expired | cancelled
            $table->string('status', 20)->default('draft');
            $table->timestamp('sent_at')->nullable();
            // Set only when BOTH parties have signed. This is the flag anything
            // downstream should read: a one-sided signature is an offer, not an
            // agreement in force.
            $table->timestamp('fully_signed_at')->nullable();

            // The bearer link the counterparty signs through. Hidden on the
            // model — possession is authority, so it must never ride along in a
            // list payload.
            $table->string('public_token', 64)->nullable()->unique();

            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'end_date']);
        });

        // ── Long-form terms, page by page (the spec's "2 to 10+ pages") ──
        Schema::create('contract_pages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('title')->nullable();
            // longText, not text: ten pages of terms runs past TEXT's 64KB on
            // MySQL and would be silently truncated mid-clause.
            $table->longText('content')->nullable();
            $table->timestamps();

            $table->index(['contract_id', 'sort_order']);
        });

        // ── One signature per party, with the evidence that it was theirs ──
        Schema::create('contract_signatures', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();

            // 'party' (the customer or vendor) or 'company' (our representative).
            $table->string('signer_party', 20);

            $table->string('signer_name')->nullable();
            $table->string('signer_email')->nullable();
            $table->unsignedBigInteger('user_id')->nullable()->index();

            // draw | type | upload | stamp
            $table->string('method', 20)->nullable();
            // A PNG data URI runs past what TEXT holds.
            $table->longText('image')->nullable();

            // The audit trail. Viewing and signing are different facts — "I saw
            // it Monday and signed Thursday" is what a dispute turns on — and
            // both belong to a PERSON, which is why they live here and not on
            // the contract: two signers produce two sets of them.
            $table->timestamp('viewed_at')->nullable();
            $table->string('viewed_ip', 45)->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->string('signed_ip', 45)->nullable();
            $table->string('user_agent')->nullable();

            // Consented geolocation. Nullable on purpose: refusing the browser
            // permission must never block a signature.
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('location_label')->nullable();

            $table->string('certificate_no', 60)->nullable();

            $table->timestamps();

            // The database refuses a second signature per party, rather than
            // trusting every future caller to check first.
            $table->unique(['contract_id', 'signer_party']);
        });

        // ── Negotiation thread, internal and external side by side ──────
        Schema::create('contract_discussions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            // Set when the comment came through the public link instead of a
            // login, so the thread can show who is speaking.
            $table->string('guest_name')->nullable();
            $table->text('body');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('contract_attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->string('name');
            $table->string('path');
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();
        });

        // ── Soft links out to the rest of the CRM ───────────────────────
        // The spec asks for contracts linked to tasks, notes and project
        // templates. A morph pair with no foreign key, deliberately: this module
        // must not own a constraint on another module's table, and a link whose
        // target has been deleted should degrade to "no longer available"
        // rather than block the contract from loading.
        Schema::create('contract_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->string('linkable_type', 60);
            $table->unsignedBigInteger('linkable_id');
            $table->string('label')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['linkable_type', 'linkable_id']);
            $table->unique(['contract_id', 'linkable_type', 'linkable_id'], 'contract_link_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_links');
        Schema::dropIfExists('contract_attachments');
        Schema::dropIfExists('contract_discussions');
        Schema::dropIfExists('contract_signatures');
        Schema::dropIfExists('contract_pages');
        Schema::dropIfExists('contracts');
        Schema::dropIfExists('contract_categories');
    }
};
