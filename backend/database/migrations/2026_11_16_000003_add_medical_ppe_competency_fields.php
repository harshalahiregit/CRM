<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §16 Medical Fitness — a distinct sign-off (approved_by/at) separate from the
 * clerk who recorded the exam, plus a certificate and general supporting-document
 * upload (only a signature path existed before).
 * §17 PPE — project-level scope on an issue so kit can be tracked per project.
 * §15 Competency — an explicit experience field on the competency record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tpv_worker_medicals', function (Blueprint $table) {
            $table->unsignedBigInteger('approved_by')->nullable()->after('recorded_by');
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->string('certificate_path')->nullable()->after('signature_path');
            $table->string('document_path')->nullable()->after('certificate_path');
        });

        Schema::table('tpv_worker_ppe_issues', function (Blueprint $table) {
            $table->string('project', 160)->nullable()->after('tpv_worker_id');
            $table->string('site', 160)->nullable()->after('project');
            // Points a superseded issue at the fresh one that replaced it (§17).
            $table->unsignedBigInteger('replaced_by_id')->nullable()->after('return_notes');
        });

        Schema::table('tpv_worker_competencies', function (Blueprint $table) {
            $table->decimal('experience_years', 5, 1)->nullable()->after('skill_level');
        });

        // §17 — vendor-level PPE stock / allocation. Structure for tracking kit a
        // vendor holds for its own workforce (allocated vs issued), distinct from
        // the warehouse Inventory ledger. Optional link to an Inventory product.
        Schema::create('tpv_vendor_ppe_stocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('vendor_id')->index();
            $table->unsignedBigInteger('inventory_item_id')->nullable();
            $table->string('item', 160);
            $table->string('project', 160)->nullable();
            $table->decimal('allocated_qty', 12, 3)->default(0);
            $table->decimal('issued_qty', 12, 3)->default(0);
            $table->string('notes', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('tpv_worker_medicals', function (Blueprint $table) {
            $table->dropColumn(['approved_by', 'approved_at', 'certificate_path', 'document_path']);
        });

        Schema::table('tpv_worker_ppe_issues', function (Blueprint $table) {
            $table->dropColumn(['project', 'site', 'replaced_by_id']);
        });

        Schema::table('tpv_worker_competencies', function (Blueprint $table) {
            $table->dropColumn('experience_years');
        });

        Schema::dropIfExists('tpv_vendor_ppe_stocks');
    }
};
