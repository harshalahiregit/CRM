<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TPV Work Packages & Activities (Sangoe TPV §13).
 *
 * The accountability spine: Vendor → Project → Work Package → Activity → Workforce.
 * A work package is a scoped chunk of a vendor's engagement on a project; each has
 * activities, and workers are deployed against a package. All additive; the worker
 * link is a nullable column so existing workers are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tpv_work_packages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('reference')->index();              // WP-YYYY-###
            $table->unsignedBigInteger('vendor_id')->index();
            $table->unsignedBigInteger('project_id')->nullable()->index();
            $table->unsignedBigInteger('contract_id')->nullable()->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('scope')->nullable();
            $table->string('location')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            // Planned / Active / On_Hold / Completed / Closed
            $table->string('status', 24)->default('Planned')->index();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tpv_activities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('work_package_id')->index();
            $table->string('name');
            $table->text('description')->nullable();
            // Required competency for this activity (Skill Matrix hook, Phase 5).
            $table->string('required_competency')->nullable();
            // Not_Started / In_Progress / Completed / On_Hold
            $table->string('status', 24)->default('Not_Started')->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        // Deploy a worker against a work package (nullable — additive).
        Schema::table('tpv_workers', function (Blueprint $table) {
            $table->unsignedBigInteger('work_package_id')->nullable()->after('vendor_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('tpv_workers', function (Blueprint $table) {
            $table->dropColumn('work_package_id');
        });
        Schema::dropIfExists('tpv_activities');
        Schema::dropIfExists('tpv_work_packages');
    }
};
