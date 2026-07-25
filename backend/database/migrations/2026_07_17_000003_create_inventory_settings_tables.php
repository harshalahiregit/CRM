<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory OS — Settings master data (blueprint §10).
 *
 * These are the lookup tables the Item form's dropdowns read from: Units,
 * Commodity Type, Group → Sub-group, Taxes, and the four variation attributes
 * (Color / Model / Size / Style).
 *
 * The four variations share ONE table keyed by `kind` rather than four
 * near-identical tables — same trick as the task/project status manager. One
 * migration, one model, one controller, four tabs in the UI.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Units — Pieces / Kilogram / Box …
        Schema::create('inventory_units', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('name');
            $table->string('short_name')->nullable();   // pcs, kg — denormalised onto products
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
        });

        // Commodity Type — Goods / Service / Consumable …
        Schema::create('inventory_types', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('name');
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
        });

        // Commodity Group → Sub-group (sub-group is dependent on the group).
        Schema::create('inventory_groups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('name');
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
        });

        Schema::create('inventory_subgroups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('group_id')->index();
            $table->string('name');
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
        });

        // Variation attributes: color | model | size | style.
        Schema::create('inventory_attributes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('kind');            // color | model | size | style
            $table->string('name');
            $table->string('value')->nullable();   // hex for colours, free text otherwise
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
            $table->index(['tenant_id', 'kind']);
        });

        // Taxes — CGST / SGST / IGST / GST 18% …
        Schema::create('inventory_taxes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('name');
            $table->decimal('rate', 5, 2)->default(0);
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
        });

        $this->seedDefaults();
    }

    /**
     * Give every existing tenant a usable starting set, so the Item form has
     * real dropdown options the moment the module opens. Only universal values
     * are seeded — groups/variations are business-specific and stay empty.
     */
    private function seedDefaults(): void
    {
        if (! Schema::hasTable('tenants')) {
            return;
        }

        $now = now();
        $units = [
            ['Pieces', 'pcs'], ['Box', 'box'], ['Kilogram', 'kg'], ['Gram', 'g'],
            ['Litre', 'ltr'], ['Millilitre', 'ml'], ['Metre', 'm'], ['Pair', 'pair'],
            ['Pack', 'pack'], ['Set', 'set'],
        ];
        $types = ['Goods', 'Consumable', 'Service', 'Asset'];
        // Indian GST slabs — this CRM prices in ₹ and the item form has an HSN field.
        $taxes = [['Exempt', 0], ['GST 5%', 5], ['GST 12%', 12], ['GST 18%', 18], ['GST 28%', 28]];

        foreach (DB::table('tenants')->pluck('id') as $tid) {
            foreach ($units as $i => [$name, $short]) {
                DB::table('inventory_units')->insert([
                    'tenant_id' => $tid, 'name' => $name, 'short_name' => $short,
                    'order' => $i, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            foreach ($types as $i => $name) {
                DB::table('inventory_types')->insert([
                    'tenant_id' => $tid, 'name' => $name, 'order' => $i,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            foreach ($taxes as $i => [$name, $rate]) {
                DB::table('inventory_taxes')->insert([
                    'tenant_id' => $tid, 'name' => $name, 'rate' => $rate, 'order' => $i,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_taxes');
        Schema::dropIfExists('inventory_attributes');
        Schema::dropIfExists('inventory_subgroups');
        Schema::dropIfExists('inventory_groups');
        Schema::dropIfExists('inventory_types');
        Schema::dropIfExists('inventory_units');
    }
};
