<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TPV vendor contacts — the master list of people belonging to an onboarded
 * vendor (first/last name, designation, department, contact details). One
 * contact per vendor may be flagged primary; single-primary is enforced in the
 * service. Contacts are never hard-deleted — status (Active/Inactive) is the
 * soft toggle, and softDeletes() is retained for the module convention.
 *
 * This becomes the reusable source for future TPV features (kickoff
 * participants, safety meetings, PTW, training, etc.), which reference a
 * tpv_contacts row rather than re-entering names.
 *
 * Row-level multi-tenancy (tenant_id); no DB foreign keys, matching the module.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tpv_contacts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            // The owning vendor. Ownership/tenant integrity is enforced in the
            // service/controller, not the DB (module convention — no FKs).
            $table->unsignedBigInteger('vendor_id')->index();

            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('designation')->nullable();
            $table->string('department')->nullable();
            $table->string('email')->nullable();
            $table->string('mobile')->nullable();
            $table->string('alternate_mobile')->nullable();

            // At most one primary per vendor — demotion of the previous primary
            // happens in TpvContactService (no partial-unique index in MySQL).
            $table->boolean('is_primary')->default(false);
            $table->string('status')->default('Active')->index();   // Active | Inactive

            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'vendor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tpv_contacts');
    }
};
