<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STOS-FLEET — drivers, without duplicating a single person.
 *
 * Golden rule 3 says modules do not keep their own copies of shared master
 * data. A driver already exists in this CRM: they are a worker under a vendor
 * or a contact under a customer. If STOS copied them, the day somebody fixes a
 * phone number in the customer directory, Transport would still be calling the
 * old one — which is exactly the duplicate-master-data failure the rule exists
 * to prevent.
 *
 * So there is NO drivers table here. There are two much smaller things:
 *
 * 1. `driver_profiles` — the STOS OVERLAY. It holds only what Transport knows
 *    and the directory does not: licence number, class and expiry, and whether
 *    the driver is available to allocate. It carries a REFERENCE to the person
 *    (source + source_id), never a copy of their name or phone.
 *
 * 2. `stos_drivers` — the standalone directory. STOS must also run as its own
 *    application, where no CRM customer directory exists; then this table is
 *    the source and the same adapter reads it. In CRM mode it stays empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();

            // Where the person actually lives. `stos` means the standalone
            // directory below; everything else points into the CRM.
            $table->string('source', 40);
            $table->unsignedBigInteger('source_id');

            // What Transport knows and the customer directory does not.
            $table->string('licence_number', 40)->nullable();
            $table->string('licence_class', 20)->nullable();   // LMV | HMV | HTV | HAZ
            $table->date('licence_expiry')->nullable();

            // available | on_trip | suspended | inactive.
            // 'on_trip' is set by Dispatch, which owns trips — STOS-FLEET only
            // reports it; it never decides it.
            $table->string('status', 20)->default('available');
            $table->string('note', 255)->nullable();

            $table->timestamps();

            // One overlay per person, per workspace. Without this a driver
            // could hold two licences with two expiry dates.
            $table->unique(['company_id', 'source', 'source_id'], 'driver_profiles_person_unique');
            $table->index(['company_id', 'status']);
        });

        Schema::create('stos_drivers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();

            $table->string('name', 150);
            $table->string('phone', 30)->nullable();
            // Who they drive for, as free text: in standalone mode there is no
            // customer directory to point at.
            $table->string('employer_name', 150)->nullable();
            $table->string('designation', 60)->nullable();
            // The id this person carries in whatever system the operator came
            // from, so a later migration into the CRM directory can match them.
            $table->string('external_ref', 60)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stos_drivers');
        Schema::dropIfExists('driver_profiles');
    }
};
