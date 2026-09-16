<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SIRE — issue-to-issue relationships.
 *
 * Split of responsibility, deliberate:
 *   sire_reports.duplicate_of_id   the 1:1 duplicate pointer. Already exists, is
 *                                  already guarded by the mark_duplicate
 *                                  transition, and belongs on the row.
 *   sire_report_links              everything MANY-to-many: regression_of,
 *                                  related_to, caused_by, blocks.
 *
 * Keeping duplicate on the row avoids two sources of truth for the one
 * relationship the workflow actually enforces.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sire_report_links')) {
            return;
        }

        Schema::create('sire_report_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');

            $table->unsignedBigInteger('from_report_id');
            $table->unsignedBigInteger('to_report_id');

            // regression_of | related_to | caused_by | blocks
            $table->string('link_type', 24);
            $table->string('note', 500)->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
            $table->unique(['tenant_id', 'from_report_id', 'to_report_id', 'link_type'], 'sire_lnk_unique_edge_uq');
            $table->index(['tenant_id', 'to_report_id', 'link_type'], 'sire_lnk_inbound_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sire_report_links');
    }
};
