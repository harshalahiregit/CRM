<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase ← TPV parity: work packages and their activities.
 *
 * A work package is the accountability spine — the named parcel of scope a
 * vendor is actually on site to deliver, and the thing a worker, a permit and
 * an authorisation all hang off. Purchase had no equivalent at all, so there
 * was nothing to answer "what is this crew here to do?".
 *
 * Activities live under a package because the competency rule ("no competency,
 * no work") is per ACTIVITY, not per package: welding and scaffolding inside
 * one package demand different tickets, and a package-level requirement would
 * either over- or under-gate every worker on it.
 *
 * Mirrors tpv_work_packages / tpv_activities, with purchase_vendor_id in place
 * of vendor_id. project_id and contract_id are plain nullable columns with no
 * FK, exactly as TPV has them — those modules own their own tables and a hard
 * constraint across a module boundary is what the architecture forbids.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_work_packages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('reference', 60)->nullable();
            $table->unsignedBigInteger('purchase_vendor_id')->nullable()->index();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('contract_id')->nullable();

            $table->string('name', 190);
            $table->text('description')->nullable();
            $table->text('scope')->nullable();
            $table->string('location', 190)->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('status', 30)->default('Planned');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('purchase_activities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('work_package_id')->index();

            $table->string('name', 190);
            $table->text('description')->nullable();
            // The competency this activity demands, if any. Null means the
            // activity gates on nothing extra — which is a real answer, not a
            // missing one, so the column stays nullable rather than defaulted.
            $table->string('required_competency', 120)->nullable();
            $table->string('status', 30)->default('Active');
            $table->unsignedSmallInteger('sort_order')->default(0);

            // Whether doing this needs a permit to work, and of what type —
            // so the authorisation check can say "this activity needs a hot-work
            // permit" rather than leaving it to be remembered.
            $table->boolean('requires_permit')->default(false);
            $table->string('permit_type', 60)->nullable();
            $table->string('hazard', 500)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['work_package_id', 'sort_order']);
        });

        // A worker's package assignment — the accountability link TPV keeps as
        // tpv_workers.work_package_id.
        Schema::table('purchase_workers', function (Blueprint $table) {
            $table->unsignedBigInteger('work_package_id')->nullable()->after('department')->index();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_workers', function (Blueprint $table) {
            $table->dropColumn('work_package_id');
        });
        Schema::dropIfExists('purchase_activities');
        Schema::dropIfExists('purchase_work_packages');
    }
};
