<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The PPE requirement matrix for Purchase — role needs item, as TPV has had.
 *
 * Purchase had no such table, so its PPE gate was the weakest rule the data
 * supported: holding ANY one item counted as equipped. A worker with one pair of
 * gloves passed a check that on TPV would have demanded a helmet and boots by
 * name. Since the badge and the site gate both consult this, that gap was a
 * safety difference between the two engines, not a cosmetic one.
 *
 * Shape mirrors tpv_ppe_requirements so one matrix screen serves both. `hazard`
 * and `activity` are carried for that parity and are DESCRIPTIVE ONLY here:
 * purchase_workers records neither, and a rule that narrowed on a column nothing
 * sets would silently match nobody. See PurchasePpeRequirement::matches().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_ppe_requirements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');

            // 'all' applies to every worker; the others match a purchase_workers
            // column — deliberately only the two a worker actually records.
            $table->string('scope_type', 30)->default('designation');
            $table->string('scope_value', 120)->nullable();

            $table->string('hazard', 120)->nullable();
            $table->string('activity', 120)->nullable();

            // Only 'mandatory' gates the badge; the others are advisory.
            $table->string('ppe_class', 20)->default('mandatory');
            $table->string('condition', 200)->nullable();

            $table->unsignedBigInteger('product_id');       // an Inventory product
            $table->unsignedInteger('qty')->default(1);
            $table->unsignedInteger('replacement_frequency_days')->nullable();
            $table->boolean('verification_required')->default(false);

            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'scope_type', 'scope_value'], 'p_ppe_req_scope_idx');
            $table->unique(['tenant_id', 'scope_type', 'scope_value', 'product_id'], 'p_ppe_req_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_ppe_requirements');
    }
};
