<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generic custom-fields engine, same shape as the legacy CRM's
 * tblcustomfields/tblcustomfieldsvalues: fields are defined per entity type
 * (`field_to`, e.g. 'customers', 'contacts') and values stored per record
 * (`rel_id`). Built for the Customer module first but intentionally generic —
 * other modules can adopt it by passing their own `field_to`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('field_to', 30);                        // customers | contacts | ...
            $table->string('name', 150);
            $table->string('slug', 170);
            $table->string('type', 20)->default('input');          // input|number|textarea|select|multiselect|checkbox|date_picker|datetime_picker|colorpicker|link
            $table->text('options')->nullable();                   // newline-separated, for select/multiselect/checkbox
            $table->boolean('required')->default(false);
            $table->unsignedInteger('field_order')->default(0);
            $table->boolean('active')->default(true);
            $table->boolean('show_on_table')->default(false);
            $table->text('default_value')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'field_to', 'slug']);
            $table->index(['tenant_id', 'field_to', 'active']);
        });

        Schema::create('custom_field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('field_id')->constrained('custom_fields')->cascadeOnDelete();
            $table->unsignedBigInteger('rel_id');                  // the entity's id (e.g. clients.id)
            $table->string('field_to', 30);
            $table->text('value')->nullable();
            $table->timestamps();

            $table->unique(['field_id', 'rel_id']);
            $table->index(['field_to', 'rel_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_field_values');
        Schema::dropIfExists('custom_fields');
    }
};
