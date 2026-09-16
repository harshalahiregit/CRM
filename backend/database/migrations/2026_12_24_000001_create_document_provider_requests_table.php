<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A vendor asking a compliance agency to call them back.
 *
 * A vendor who does not hold a registration cannot discharge the obligation,
 * and it is the commonest reason onboarding stalls. The Documents screen
 * therefore offers agencies who sell that registration; picking one hands over
 * a lead and the CRM steps out — the conversation that follows is between the
 * vendor and the agency.
 *
 * The screen already existed and sent the lead nowhere: no endpoint, no table,
 * no mail. It pushed the form into React state, told the vendor a callback was
 * "pending", and lost it on the next refresh. This is where the request lives
 * now, so that a vendor who says "nobody called me" can be answered from the
 * record rather than from memory.
 *
 * One table for both engines. The vendor is identified by `vendor_kind` plus
 * `vendor_id` rather than two nullable foreign keys, because the row is a
 * historical fact about a lead that was handed over — it must survive the
 * vendor being deleted, and it is never joined back for business logic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_provider_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();

            // 'tpv' | 'purchase' — which engine's vendor master the id belongs to.
            $table->string('vendor_kind', 16);
            $table->unsignedBigInteger('vendor_id');

            // Which agency, and the document that prompted it.
            $table->string('provider_id', 64);
            $table->string('provider_name', 160);
            $table->string('provider_email', 191)->nullable();
            $table->string('document_type', 64);
            $table->string('document_label', 160);

            // What the vendor asked us to pass on. Captured as sent, not looked
            // up later: the agency was given these exact details, and a vendor
            // who changes their number afterwards must not rewrite history.
            $table->string('contact_name', 160);
            $table->string('contact_email', 191);
            $table->string('contact_mobile', 60)->nullable();
            $table->string('company_name', 191)->nullable();
            $table->text('notes')->nullable();

            // Handing a vendor's phone number to an outside company needs their
            // say-so, and the say-so has to be evidenced, not assumed.
            $table->timestamp('consented_at');

            // Queued → Sent, or Failed with the reason. A request raised before
            // an address is configured stays Queued rather than being lost.
            $table->string('status', 16)->default('Queued')->index();
            $table->timestamp('sent_at')->nullable();
            $table->text('failure_reason')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'vendor_kind', 'vendor_id'], 'dpr_tenant_vendor_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_provider_requests');
    }
};
