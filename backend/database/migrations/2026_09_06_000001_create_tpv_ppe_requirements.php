<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Role → required PPE.
 *
 * The matrix says WHICH PPE a role needs; it never says what PPE exists or how
 * much of it is in stock — that stays with the Inventory product it points at.
 * A rule is (scope_type, scope_value) → product.
 *
 * This replaces the product-level `mandatory` tag, which could only express one
 * global rule for everybody. Existing tagged products are carried over as
 * scope_type='all' rules so nothing silently stops being required.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tpv_ppe_requirements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            // 'all' applies to every worker; the others match a worker column.
            $table->string('scope_type', 30)->default('designation');
            $table->string('scope_value', 120)->nullable();
            $table->unsignedBigInteger('product_id');
            $table->unsignedInteger('qty')->default(1);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'scope_type', 'scope_value'], 'ppe_req_scope_idx');
            $table->unique(['tenant_id', 'scope_type', 'scope_value', 'product_id'], 'ppe_req_unique');
        });

        // Carry the old global rule across: anything tagged 'mandatory' in a PPE
        // category becomes a requirement for every worker.
        $ppeCategoryIds = DB::table('inventory_categories')
            ->where('name', 'PPE')->pluck('id', 'id')->keys()->all();

        if ($ppeCategoryIds === []) {
            return;
        }

        $tagged = DB::table('inventory_products')
            ->whereIn('category_id', $ppeCategoryIds)
            ->whereNotNull('tags')
            ->get(['id', 'tenant_id', 'tags']);

        foreach ($tagged as $product) {
            $tags = json_decode((string) $product->tags, true);
            $isMandatory = is_array($tags) && collect($tags)
                ->map(fn ($t) => strtolower(trim((string) $t)))
                ->contains('mandatory');

            if (! $isMandatory) {
                continue;
            }

            DB::table('tpv_ppe_requirements')->insert([
                'tenant_id'   => $product->tenant_id,
                'scope_type'  => 'all',
                'scope_value' => null,
                'product_id'  => $product->id,
                'qty'         => 1,
                'is_active'   => true,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tpv_ppe_requirements');
    }
};
