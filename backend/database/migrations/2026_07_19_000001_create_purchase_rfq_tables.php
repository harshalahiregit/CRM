<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quotations (RFQ) — the upstream sourcing step of procure-to-pay:
 * RFQ → sent to N vendors → each returns a Quotation → compare → award → PO.
 *
 * Column shapes mirror purchase_requests / purchase_orders so the whole module
 * stays consistent (decimal(15,2), string statuses, softDeletes, per-tenant
 * unique numbers).
 */
return new class extends Migration {
    public function up(): void
    {
        // ── The request itself ──────────────────────────────────────────
        Schema::create('purchase_rfqs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('rfq_number');
            $table->string('title');
            $table->string('department')->nullable();
            // An RFQ can originate from an approved purchase request.
            $table->unsignedBigInteger('purchase_request_id')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->date('required_by')->nullable();
            $table->date('closes_at')->nullable();          // quote deadline
            $table->string('currency', 8)->default('INR');
            $table->string('status')->default('Draft')->index();
            $table->timestamp('sent_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'rfq_number']);
            $table->index(['tenant_id', 'status']);
        });

        // ── What we're asking to be quoted (no committed price) ─────────
        Schema::create('purchase_rfq_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('purchase_rfq_id')->index();
            $table->string('description');
            $table->decimal('qty', 15, 2)->default(1);
            $table->string('unit')->nullable();
            $table->decimal('target_rate', 15, 2)->nullable();   // optional budget estimate
            $table->decimal('tax', 5, 2)->default(0);            // percentage
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // ── The recipient list (RFQ ↔ vendors) ──────────────────────────
        Schema::create('purchase_rfq_vendors', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('purchase_rfq_id')->index();
            $table->unsignedBigInteger('vendor_id')->index();
            // Reserved for a future public / portal quote-submission link. Unused
            // in v1 (quotes are staff-entered), but shipping the column now means
            // that follow-on needs no schema change.
            $table->string('invite_token', 64)->nullable()->unique();
            $table->string('status')->default('Invited');   // Invited | Responded | Declined
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
            $table->unique(['purchase_rfq_id', 'vendor_id']);
        });

        // ── A vendor's response ─────────────────────────────────────────
        Schema::create('purchase_quotations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('purchase_rfq_id')->index();
            $table->unsignedBigInteger('vendor_id')->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->string('quotation_number');
            $table->string('currency', 8)->default('INR');
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('tax_total', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->string('status')->default('Draft')->index();
            $table->date('valid_until')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'quotation_number']);
            $table->index(['tenant_id', 'purchase_rfq_id', 'status']);
        });

        // ── The vendor's price per RFQ line (aligned for comparison) ────
        Schema::create('purchase_quotation_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('purchase_quotation_id')->index();
            // Aligns this quote line to the RFQ line it answers — what makes the
            // comparison matrix line up across vendors. Nullable so a vendor may
            // add an off-spec extra line.
            $table->unsignedBigInteger('purchase_rfq_item_id')->nullable()->index();
            $table->string('description');
            $table->decimal('qty', 15, 2)->default(1);
            $table->string('unit')->nullable();
            $table->decimal('rate', 15, 2)->default(0);
            $table->decimal('tax', 5, 2)->default(0);
            $table->decimal('amount', 15, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // ── Trace a converted PO back to its winning quote ──────────────
        // Mirrors the existing purchase_orders.purchase_request_id.
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('purchase_quotation_id')->nullable()->after('purchase_request_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn('purchase_quotation_id');
        });
        Schema::dropIfExists('purchase_quotation_items');
        Schema::dropIfExists('purchase_quotations');
        Schema::dropIfExists('purchase_rfq_vendors');
        Schema::dropIfExists('purchase_rfq_items');
        Schema::dropIfExists('purchase_rfqs');
    }
};
