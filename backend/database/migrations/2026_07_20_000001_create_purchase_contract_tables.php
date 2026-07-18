<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase contracts — master agreements (MSA) and rate contracts a vendor is
 * held to, that POs/RFQs can reference for pre-negotiated pricing.
 *
 * A reference authority beside the transaction chain, not part of PR→PO→GRN→
 * Invoice. Column shapes mirror the other Purchase tables.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('purchase_contracts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('contract_number');
            $table->string('title');
            $table->string('type')->default('rate_contract');   // rate_contract | msa
            $table->unsignedBigInteger('vendor_id')->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();

            $table->string('currency', 8)->default('INR');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            // Null ceiling = uncapped. consumed_amount is the denormalised running
            // total of PO spend booked against the contract (referenceable slice
            // tracks it; the decrement-at-PO-time wiring is a follow-on).
            $table->decimal('spend_ceiling', 15, 2)->nullable();
            $table->decimal('consumed_amount', 15, 2)->default(0);

            $table->string('status')->default('Draft')->index();
            $table->text('terms')->nullable();
            $table->string('document_path')->nullable();         // uploaded agreement (private disk)

            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'contract_number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'vendor_id', 'status']);
        });

        // The locked rate card. Empty for a document-only MSA.
        Schema::create('purchase_contract_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('purchase_contract_id')->index();
            $table->string('description');
            $table->string('unit')->nullable();
            $table->decimal('rate', 15, 2)->default(0);          // the locked price
            $table->decimal('tax', 5, 2)->default(0);            // percentage
            // Optional quantity band over which the locked rate applies.
            $table->decimal('min_qty', 15, 2)->nullable();
            $table->decimal('max_qty', 15, 2)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // A PO may cite the contract it draws its rates from. Mirrors the
        // existing purchase_request_id / purchase_quotation_id links.
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('purchase_contract_id')->nullable()->after('purchase_quotation_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn('purchase_contract_id');
        });
        Schema::dropIfExists('purchase_contract_items');
        Schema::dropIfExists('purchase_contracts');
    }
};
