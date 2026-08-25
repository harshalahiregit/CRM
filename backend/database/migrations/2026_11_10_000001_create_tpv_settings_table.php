<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant TPV configuration overrides (Sangoe TPV §34 — "everything the admin
 * can configure"). One JSON document per (tenant, group). The engines read their
 * settings through the TpvSettings catalog, which layers an active row here over
 * the config-file / constant baseline — so an ABSENT row means "use the shipped
 * defaults", i.e. today's behaviour is unchanged until an admin overrides a group.
 *
 * Groups: strike_rules, vpi, approval_workflow, authority_matrix, approval_types, gate.
 * (PPE matrix and meeting types keep their own dedicated tables/endpoints.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tpv_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('group', 60);
            $table->json('payload');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'group']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tpv_settings');
    }
};
