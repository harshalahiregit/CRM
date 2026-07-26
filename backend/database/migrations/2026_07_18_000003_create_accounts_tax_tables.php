<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts module — Phase 3: India GST / TDS compliance masters + tax capture.
 *
 * Rates are DATA, not code (spec §4.6 / §8): new rates are rows superseding old by
 * effective date. GST is modelled with real components (CGST/SGST/IGST/Cess), not
 * loose named taxes; place-of-supply drives intra- vs inter-state. Per-transaction
 * tax breakup lives in acc_voucher_tax_lines, which drives GSTR-1/3B and TDS returns.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── GST rate slabs (effective-dated) ────────────────────────────────
        Schema::create('acc_gst_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');                       // e.g. "GST 18%"
            $table->decimal('rate_percent', 6, 3);        // total, e.g. 18.000
            $table->decimal('cgst', 6, 3)->default(0);    // 9.000
            $table->decimal('sgst', 6, 3)->default(0);    // 9.000
            $table->decimal('igst', 6, 3)->default(0);    // 18.000
            $table->decimal('cess', 6, 3)->default(0);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });

        // ── HSN / SAC catalog ───────────────────────────────────────────────
        Schema::create('acc_hsn_sac', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('description')->nullable();
            $table->enum('type', ['hsn', 'sac'])->default('hsn');
            $table->foreignId('default_gst_rate_id')->nullable()->constrained('acc_gst_rates')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        // ── TDS sections (rate + threshold, effective-dated) ────────────────
        Schema::create('acc_tds_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('section_code', 20);           // 194C, 194J, ...
            $table->string('description')->nullable();
            $table->decimal('rate_percent', 6, 3);
            $table->decimal('threshold', 15, 2)->default(0);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });

        // ── Per-voucher tax breakup (drives GSTR-1/3B & TDS returns) ─────────
        Schema::create('acc_voucher_tax_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('voucher_id')->constrained('acc_vouchers')->cascadeOnDelete();
            $table->enum('tax_type', ['cgst', 'sgst', 'igst', 'cess', 'tds', 'tcs']);
            $table->decimal('rate', 6, 3)->default(0);
            $table->decimal('taxable_amount', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->unsignedBigInteger('hsn_sac_id')->nullable();       // soft link
            $table->string('tds_section_code', 20)->nullable();
            $table->string('place_of_supply_state', 2)->nullable();
            $table->enum('direction', ['outward', 'inward'])->default('outward'); // sales vs purchase
            $table->timestamps();

            $table->index(['tenant_id', 'voucher_id']);
            $table->index(['tenant_id', 'tax_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_voucher_tax_lines');
        Schema::dropIfExists('acc_tds_sections');
        Schema::dropIfExists('acc_hsn_sac');
        Schema::dropIfExists('acc_gst_rates');
    }
};
